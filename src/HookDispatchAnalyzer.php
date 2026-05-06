<?php

declare(strict_types=1);

namespace Koriym\XdebugMcp;

use Koriym\XdebugMcp\Exceptions\InvalidArgumentException;
use RuntimeException;

use function array_key_exists;
use function count;
use function explode;
use function fclose;
use function fgets;
use function file_exists;
use function fopen;
use function gzclose;
use function gzgets;
use function gzopen;
use function in_array;
use function is_readable;
use function json_encode;
use function str_ends_with;
use function str_starts_with;
use function substr;
use function trim;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Post-processes Xdebug trace files to build a WordPress hook dispatch map.
 *
 * Parses tab-separated Xdebug trace output and identifies calls to WordPress
 * hook dispatch functions (do_action, apply_filters, etc.), then collects the
 * callbacks that execute within each hook's scope.
 *
 * @codeCoverageIgnore Requires Xdebug trace file data
 */
class HookDispatchAnalyzer
{
    /** @var list<string> WordPress hook dispatch functions to detect */
    private const HOOK_DISPATCH_FUNCTIONS = [
        'do_action',
        'do_action_ref_array',
        'apply_filters',
        'apply_filters_ref_array',
    ];

    /**
     * Analyze a trace file and return hook dispatch data as a JSON string
     */
    public function analyzeToJson(string $traceFile): string
    {
        $result = $this->analyze($traceFile);

        return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Analyze a trace file and return hook dispatch data as an array
     *
     * @return array{hooks: array<string, array{callbacks: list<array{function: string, file: string, line: int, priority: int|null}>}>, total_hooks: int, total_callbacks: int}
     */
    public function analyze(string $traceFile): array
    {
        if (! file_exists($traceFile) || ! is_readable($traceFile)) {
            throw new InvalidArgumentException("Trace file not found or not readable: $traceFile");
        }

        /** @var array<string, array{callbacks: list<array{function: string, file: string, line: int, priority: int|null}>}> $hooks */
        $hooks = [];
        $totalCallbacks = 0;

        // State tracking for hook scopes
        /** @var array{hook_name: string, level: int}|null $currentHook */
        $currentHook = null;

        $lineReader = $this->createLineReader($traceFile);

        foreach ($lineReader as $line) {
            $parts = explode("\t", trim($line));
            if (count($parts) < 6) {
                continue;
            }

            $level = (int) $parts[0];
            $entryExit = $parts[2]; // 0=Entry, 1=Exit, R=Return
            $function = $parts[5];

            // Only process function entries
            if ($entryExit !== '0') {
                // If we're inside a hook scope and see an exit at the hook's level,
                // close the scope
                if ($currentHook !== null && $entryExit === '1' && $level <= $currentHook['level']) {
                    $currentHook = null;
                }

                continue;
            }

            // Detect hook dispatch function calls
            if (in_array($function, self::HOOK_DISPATCH_FUNCTIONS, true)) {
                // Extract hook name from first parameter
                $hookName = $this->extractHookName($parts);
                if ($hookName === null) {
                    continue;
                }

                // Initialize hook entry if not seen before
                if (! array_key_exists($hookName, $hooks)) {
                    $hooks[$hookName] = ['callbacks' => []];
                }

                // Set current hook scope
                $currentHook = [
                    'hook_name' => $hookName,
                    'level' => $level,
                ];

                continue;
            }

            // If we're inside a hook scope, collect callbacks
            if ($currentHook !== null && $level > $currentHook['level']) {
                // Skip internal WordPress hook infrastructure functions
                if ($this->isHookInfrastructure($function)) {
                    continue;
                }

                $file = $parts[8] ?? '';
                $lineNum = (int) ($parts[9] ?? 0);

                $hooks[$currentHook['hook_name']]['callbacks'][] = [
                    'function' => $function,
                    'file' => $file,
                    'line' => $lineNum,
                    'priority' => null,
                ];
                $totalCallbacks++;
            }

            // If we're at or above the hook level, we've left the hook scope
            if ($currentHook !== null && $level <= $currentHook['level']) {
                $currentHook = null;
            }
        }

        return [
            'hooks' => $hooks,
            'total_hooks' => count($hooks),
            'total_callbacks' => $totalCallbacks,
        ];
    }

    /**
     * Extract hook name from the trace line parts
     *
     * The first parameter of a hook dispatch function is the hook name.
     * In Xdebug trace format 1, parameters start at index 11.
     *
     * @param list<string> $parts
     */
    private function extractHookName(array $parts): string|null
    {
        $params = $parts[11] ?? '';
        if ($params === '') {
            return null;
        }

        // Parameters in Xdebug trace format can be quoted strings
        $hookName = trim($params);

        // Remove surrounding quotes if present (e.g., 'init' or "init")
        if (
            (str_starts_with($hookName, "'") && str_ends_with($hookName, "'"))
            || (str_starts_with($hookName, '"') && str_ends_with($hookName, '"'))
        ) {
            $hookName = substr($hookName, 1, -1);
        }

        return $hookName !== '' ? $hookName : null;
    }

    /**
     * Check if a function is WordPress hook infrastructure (not a user callback)
     */
    private function isHookInfrastructure(string $function): bool
    {
        $infraFunctions = [
            'WP_Hook->apply_filters',
            'WP_Hook->do_action',
            'WP_Hook->has_filter',
            'WP_Hook->has_filters',
            '_wp_call_all_hook',
            'call_user_func_array',
            'call_user_func',
        ];

        return in_array($function, $infraFunctions, true);
    }

    /**
     * Create a line reader generator for trace files (supports compressed and plain)
     *
     * @return \Generator<int, string>
     */
    private function createLineReader(string $traceFile): \Generator
    {
        if (str_ends_with($traceFile, '.gz')) {
            $handle = gzopen($traceFile, 'r');
            if (! $handle) {
                throw new RuntimeException("Cannot open compressed trace file: $traceFile");
            }

            try {
                while (($line = gzgets($handle)) !== false) {
                    yield $line;
                }
            } finally {
                gzclose($handle);
            }
        } else {
            $handle = fopen($traceFile, 'r');
            if (! $handle) {
                throw new RuntimeException("Cannot open trace file: $traceFile");
            }

            try {
                while (($line = fgets($handle)) !== false) {
                    yield $line;
                }
            } finally {
                fclose($handle);
            }
        }
    }
}
