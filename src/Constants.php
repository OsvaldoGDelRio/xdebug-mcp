<?php

declare(strict_types=1);

namespace Koriym\XdebugMcp;

/**
 * Constants for the Xdebug MCP system
 */
final class Constants
{
    public const DEFAULT_HOST = '127.0.0.1';
    public const XDEBUG_DEBUG_PORT = 9004;
    public const GLOBAL_STATE_FILE = '/tmp/xdebug-mcp-global-state.json';

    /**
     * Debug port for this process: XDEBUG_MCP_PORT if set, else the default.
     *
     * The listener binds one fixed port and there is no EADDRINUSE handling, so two
     * sessions sharing a port do not fail — they hang waiting for a connection that
     * went to the other one. A caller running several sessions at once (a pipeline
     * with concurrent workers) needs to hand each one its own port; anyone running a
     * single session keeps the default and notices nothing.
     */
    public static function debugPort(): int
    {
        $raw = getenv('XDEBUG_MCP_PORT');
        if ($raw === false || $raw === '') {
            return self::XDEBUG_DEBUG_PORT;
        }

        $port = filter_var($raw, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1024, 'max_range' => 65535],
        ]);

        // A malformed or out-of-range value must not silently land on some other
        // service's port: fall back to the documented default.
        return $port === false ? self::XDEBUG_DEBUG_PORT : $port;
    }
}
