<?php

declare(strict_types=1);

namespace Koriym\XdebugMcp;

use Koriym\XdebugMcp\Exceptions\FileNotFoundException;
use Koriym\XdebugMcp\Exceptions\InvalidArgumentException;

use function array_key_exists;
use function count;
use function explode;
use function file_exists;
use function file_get_contents;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function str_contains;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Cross-references xcoverage line-level data with a function location map
 * to determine which flagged functions are reachable during normal execution.
 *
 * Reports which functions have zero executed lines (unreachable) versus
 * functions that have at least one executed line (reachable).
 *
 * @codeCoverageIgnore Requires xcoverage and function-map data files
 */
class FunctionReachabilityAnalyzer
{
    /**
     * Analyze reachability and return result as a JSON string
     */
    public function analyzeToJson(string $coverageFile, string $functionMapFile): string
    {
        $result = $this->analyze($coverageFile, $functionMapFile);

        return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Analyze function reachability by cross-referencing coverage data with function map
     *
     * @return array{functions: list<array{function: string, file: string, line_start: int, line_end: int, executed_lines: int, total_lines: int, reachable: bool}>, total_functions: int, reachable: int, unreachable: int}
     */
    public function analyze(string $coverageFile, string $functionMapFile): array
    {
        $coverage = $this->loadCoverageData($coverageFile);
        $functionMap = $this->loadFunctionMap($functionMapFile);

        /** @var list<array{function: string, file: string, line_start: int, line_end: int, executed_lines: int, total_lines: int, reachable: bool}> $functions */
        $functions = [];
        $reachable = 0;
        $unreachable = 0;

        foreach ($functionMap as $entry) {
            $functionName = $entry['function_name'];
            $filePath = $entry['file_path'];
            $lineStart = (int) $entry['line_start'];
            $lineEnd = (int) $entry['line_end'];

            $totalLines = $lineEnd - $lineStart + 1;
            $executedLines = $this->countExecutedLines($coverage, $filePath, $lineStart, $lineEnd);
            $isReachable = $executedLines > 0;

            if ($isReachable) {
                $reachable++;
            } else {
                $unreachable++;
            }

            $functions[] = [
                'function' => $functionName,
                'file' => $filePath,
                'line_start' => $lineStart,
                'line_end' => $lineEnd,
                'executed_lines' => $executedLines,
                'total_lines' => $totalLines,
                'reachable' => $isReachable,
            ];
        }

        return [
            'functions' => $functions,
            'total_functions' => count($functions),
            'reachable' => $reachable,
            'unreachable' => $unreachable,
        ];
    }

    /**
     * Load and parse xcoverage JSON data
     *
     * Supports two formats:
     * 1. xcoverage JSON output with "uncovered" key (line ranges)
     * 2. Raw Xdebug coverage JSON with file => {line: hitCount} structure
     *
     * @return array<string, array<int, int>> Normalized coverage: file => [line => hitCount]
     */
    private function loadCoverageData(string $coverageFile): array
    {
        if (! file_exists($coverageFile)) {
            throw new FileNotFoundException("Coverage file not found: $coverageFile");
        }

        $contents = file_get_contents($coverageFile);
        if ($contents === false || $contents === '') {
            throw new InvalidArgumentException("Cannot read or empty coverage file: $coverageFile");
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        // Raw Xdebug coverage format: {"/path/file.php": {"10": 1, "11": 0, ...}}
        // Each value is a hit count per line
        /** @var array<string, array<int, int>> $result */
        $result = [];

        foreach ($data as $key => $value) {
            // Skip non-file keys (e.g., '$schema', 'coverage_type', 'summary', 'uncovered')
            if (! is_string($key) || ! is_array($value)) {
                continue;
            }

            // Check if this looks like a file path with line coverage data
            if (str_contains($key, '/') || str_contains($key, '\\')) {
                /** @var array<string|int, int> $value */
                $lineData = [];
                foreach ($value as $lineNum => $hitCount) {
                    $lineData[(int) $lineNum] = (int) $hitCount;
                }

                $result[$key] = $lineData;
            }
        }

        // If we found raw coverage data, return it
        if ($result !== []) {
            return $result;
        }

        // Try xcoverage output format with 'uncovered' key
        // In this format, we know uncovered lines but must infer covered lines
        if (array_key_exists('summary', $data) && array_key_exists('uncovered', $data)) {
            /** @var array<string, list<int|string>> $uncovered */
            $uncovered = $data['uncovered'];

            foreach ($uncovered as $file => $ranges) {
                if (! is_array($ranges)) {
                    continue;
                }

                $lineData = [];
                foreach ($ranges as $range) {
                    if (is_int($range)) {
                        $lineData[$range] = 0; // uncovered
                    } elseif (is_string($range) && str_contains($range, '-')) {
                        [$start, $end] = explode('-', $range, 2);
                        for ($i = (int) $start; $i <= (int) $end; $i++) {
                            $lineData[$i] = 0; // uncovered
                        }
                    }
                }

                $result[$file] = $lineData;
            }

            return $result;
        }

        return $result;
    }

    /**
     * Load and validate the function map JSON file
     *
     * @return list<array{function_name: string, file_path: string, line_start: int, line_end: int}>
     */
    private function loadFunctionMap(string $functionMapFile): array
    {
        if (! file_exists($functionMapFile)) {
            throw new FileNotFoundException("Function map file not found: $functionMapFile");
        }

        $contents = file_get_contents($functionMapFile);
        if ($contents === false || $contents === '') {
            throw new InvalidArgumentException("Cannot read or empty function map file: $functionMapFile");
        }

        /** @var list<array{function_name: string, file_path: string, line_start: int, line_end: int}> $data */
        $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new InvalidArgumentException("Function map must be a JSON array: $functionMapFile");
        }

        // Validate each entry has required fields
        foreach ($data as $index => $entry) {
            if (! is_array($entry)) {
                throw new InvalidArgumentException("Function map entry at index $index must be an object");
            }

            $requiredFields = ['function_name', 'file_path', 'line_start', 'line_end'];
            foreach ($requiredFields as $field) {
                if (! array_key_exists($field, $entry)) {
                    throw new InvalidArgumentException(
                        "Function map entry at index $index missing required field: $field",
                    );
                }
            }
        }

        return $data;
    }

    /**
     * Count how many lines within a function's range were executed
     *
     * @param array<string, array<int, int>> $coverage
     */
    private function countExecutedLines(array $coverage, string $filePath, int $lineStart, int $lineEnd): int
    {
        if (! array_key_exists($filePath, $coverage)) {
            return 0;
        }

        $fileCoverage = $coverage[$filePath];
        $executedCount = 0;

        for ($line = $lineStart; $line <= $lineEnd; $line++) {
            if (array_key_exists($line, $fileCoverage) && $fileCoverage[$line] > 0) {
                $executedCount++;
            }
        }

        return $executedCount;
    }
}
