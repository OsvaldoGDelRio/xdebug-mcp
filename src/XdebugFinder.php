<?php

declare(strict_types=1);

namespace Koriym\XdebugMcp;

use function escapeshellarg;
use function explode;
use function extension_loaded;
use function fclose;
use function file_exists;
use function fwrite;
use function getenv;
use function ini_get;
use function is_executable;
use function is_resource;
use function microtime;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function str_contains;
use function stream_get_contents;
use function stream_set_blocking;
use function trim;
use function usleep;

use const DIRECTORY_SEPARATOR;
use const PATH_SEPARATOR;
use const PHP_MAJOR_VERSION;
use const PHP_MINOR_VERSION;
use const PHP_OS_FAMILY;
use const STDERR;

/**
 * Intelligent Xdebug detection and loading utility
 *
 * Handles various Xdebug installation methods:
 * - Already loaded via php.ini
 * - Homebrew installation
 * - Standard extension_dir installation
 * - PECL installation
 */
final class XdebugFinder
{
    /**
     * Get the appropriate Xdebug flag for command line usage
     *
     * @return string Empty string if already loaded, or -dzend_extension=PATH
     */
    public static function getXdebugFlag(): string
    {
        // Check if Xdebug is already loaded
        if (extension_loaded('xdebug')) {
            return '';
        }

        // @codeCoverageIgnoreStart
        $xdebugPath = self::detectXdebugPath();

        if ($xdebugPath !== null) {
            return ' -dzend_extension=' . escapeshellarg($xdebugPath);
        }

        return '';
        // @codeCoverageIgnoreEnd
    }

    /**
     * Get the Xdebug loading flag for a specific PHP binary.
     *
     * @return string Empty string if that binary already loads Xdebug, or -dzend_extension=PATH
     */
    public static function getXdebugFlagForPhpBinary(string $phpBinary): string
    {
        if (! self::canProbePhpBinary($phpBinary)) {
            return '';
        }

        $loaded = self::runPhpBinaryCode($phpBinary, "echo extension_loaded('xdebug') ? '1' : '0';");
        if (trim((string) $loaded) === '1') {
            return '';
        }

        $xdebugPath = self::detectXdebugPathForPhpBinary($phpBinary);
        if ($xdebugPath !== null) {
            return ' -dzend_extension=' . escapeshellarg($xdebugPath);
        }

        return '';
    }

    /**
     * Detect Xdebug extension path from various installation methods
     *
     * @return string|null Path to xdebug.so or null if not found
     */
    public static function detectXdebugPath(): string|null
    {
        if (extension_loaded('xdebug')) {
            return null;
        }

        // @codeCoverageIgnoreStart
        if (PHP_OS_FAMILY === 'Darwin') {
            $phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
            $brewPaths = [
                "/opt/homebrew/opt/xdebug@{$phpVersion}/xdebug.so",
                "/usr/local/opt/xdebug@{$phpVersion}/xdebug.so",
            ];

            foreach ($brewPaths as $path) {
                if (file_exists($path)) {
                    return $path;
                }
            }
        }

        $extensionDir = ini_get('extension_dir');
        if ($extensionDir !== false && $extensionDir !== '') {
            $extension = PHP_OS_FAMILY === 'Windows' ? 'php_xdebug.dll' : 'xdebug.so';
            $standardPath = $extensionDir . DIRECTORY_SEPARATOR . $extension;
            if (file_exists($standardPath)) {
                return $standardPath;
            }
        }

        return null;
        // @codeCoverageIgnoreEnd
    }

    private static function detectXdebugPathForPhpBinary(string $phpBinary): string|null
    {
        // @codeCoverageIgnoreStart
        if (PHP_OS_FAMILY === 'Darwin') {
            $phpVersion = self::runPhpBinaryCode($phpBinary, 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;');
            $phpVersion = trim((string) $phpVersion);
            if ($phpVersion !== '') {
                $brewPaths = [
                    "/opt/homebrew/opt/xdebug@{$phpVersion}/xdebug.so",
                    "/usr/local/opt/xdebug@{$phpVersion}/xdebug.so",
                ];

                foreach ($brewPaths as $path) {
                    if (file_exists($path)) {
                        return $path;
                    }
                }
            }
        }

        $extensionDir = self::runPhpBinaryCode($phpBinary, 'echo ini_get("extension_dir");');
        $extensionDir = trim((string) $extensionDir);
        if ($extensionDir !== '') {
            $extension = PHP_OS_FAMILY === 'Windows' ? 'php_xdebug.dll' : 'xdebug.so';
            $standardPath = $extensionDir . DIRECTORY_SEPARATOR . $extension;
            if (file_exists($standardPath)) {
                return $standardPath;
            }
        }

        return null;
        // @codeCoverageIgnoreEnd
    }

    private static function canProbePhpBinary(string $phpBinary): bool
    {
        if (str_contains($phpBinary, '/') || str_contains($phpBinary, '\\')) {
            return file_exists($phpBinary);
        }

        $path = getenv('PATH');
        if ($path === false || $path === '') {
            return false;
        }

        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            $candidate = $dir . DIRECTORY_SEPARATOR . $phpBinary;
            if (is_executable($candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Execute a tiny probe against a target PHP binary without letting broken runtimes hang callers.
     */
    private static function runPhpBinaryCode(string $phpBinary, string $code): string|null
    {
        $process = proc_open(
            [$phpBinary, '-r', $code],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );
        if (! is_resource($process)) {
            return null;
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $deadline = microtime(true) + 2.0;
        $timedOut = false;

        do {
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $status = proc_get_status($process);
            if (! $status['running']) {
                break;
            }

            if (microtime(true) >= $deadline) {
                $timedOut = true;
                proc_terminate($process);
                usleep(100000);

                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process, 9);
                }

                break;
            }

            usleep(10000);
        } while (true);

        $stdout .= stream_get_contents($pipes[1]) ?: '';

        foreach ($pipes as $pipe) {
            if (! is_resource($pipe)) {
                continue;
            }

            fclose($pipe);
        }

        proc_close($process);

        return $timedOut ? null : trim($stdout);
    }

    /**
     * Check if Xdebug is available (loaded or can be loaded)
     *
     * @return bool True if Xdebug is available
     */
    public static function isXdebugAvailable(): bool
    {
        return extension_loaded('xdebug') || self::detectXdebugPath() !== null;
    }

    /**
     * Display installation guidance when Xdebug is not found
     *
     * @param bool $exitAfter Whether to exit after showing guidance
     */
    public static function showInstallationGuidance(bool $exitAfter = true): void
    {
        fwrite(STDERR, "❌ Xdebug not found. Please install Xdebug to use this tool.\n");
        fwrite(STDERR, "📖 More info: https://xdebug.org/docs/install\n");

        if ($exitAfter) {
            exit(1); // @codeCoverageIgnore
        }
    }
}
