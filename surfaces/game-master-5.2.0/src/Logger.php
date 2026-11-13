<?php

namespace GameMaster;

/**
 * Logger - Central logging facility
 *
 * All output goes to errors.log in the webroot.
 * PHP's own error_log is redirected there too, so every notice,
 * warning, fatal, and uncaught exception lands in one file.
 *
 * Usage:
 *   Logger::init(__DIR__ . '/errors.log');
 *   Logger::info('Game started', ['gameId' => $id]);
 *   Logger::exception($e, 'GameActionHandler');
 */
class Logger
{
    private static ?string $logFile = null;
    private static string  $requestId = '-';

    // ── Bootstrap ────────────────────────────────────────────────────────────

    public static function init(string $logFile): void
    {
        self::$logFile   = $logFile;
        self::$requestId = substr(bin2hex(random_bytes(4)), 0, 8);

        // Redirect PHP's native error_log to the same file
        ini_set('error_log',  $logFile);
        ini_set('log_errors', '1');
        error_reporting(E_ALL);
    }

    // ── Log levels ────────────────────────────────────────────────────────────

    public static function info(string $msg, array $ctx = []): void
    {
        self::write('INFO ', $msg, $ctx);
    }

    public static function warn(string $msg, array $ctx = []): void
    {
        self::write('WARN ', $msg, $ctx);
    }

    public static function error(string $msg, array $ctx = []): void
    {
        self::write('ERROR', $msg, $ctx);
    }

    public static function debug(string $msg, array $ctx = []): void
    {
        self::write('DEBUG', $msg, $ctx);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Log a Throwable (Exception or Error) with full trace.
     */
    public static function exception(\Throwable $e, string $label = ''): void
    {
        $prefix = $label ? "[{$label}] " : '';
        self::error(
            $prefix . get_class($e) . ': ' . $e->getMessage(),
            [
                'file'  => $e->getFile() . ':' . $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]
        );
    }

    /**
     * Log a PHP fatal error array from error_get_last().
     */
    public static function fatal(array $err): void
    {
        self::error('PHP FATAL', $err);
    }

    /**
     * Log an incoming HTTP request.
     */
    public static function request(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
        $uri    = $_SERVER['REQUEST_URI']    ?? '/';
        $ip     = $_SERVER['REMOTE_ADDR']    ?? 'unknown';
        self::info("→ {$method} {$uri}", ['ip' => $ip]);
    }

    /**
     * Log an outgoing HTTP response (call with the start time from request()).
     */
    public static function response(int $status, float $startTime): void
    {
        $ms  = round((microtime(true) - $startTime) * 1000, 1);
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        self::info("← {$status} {$uri} [{$ms}ms]");
    }

    /**
     * Log a gate or pipeline step.
     */
    public static function gate(string $gate, string $msg, array $ctx = []): void
    {
        self::write('GATE ', "[{$gate}] {$msg}", $ctx);
    }

    public static function getRequestId(): string
    {
        return self::$requestId;
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private static function write(string $level, string $msg, array $ctx): void
    {
        if (self::$logFile === null) {
            return;
        }

        $ts  = date('Y-m-d H:i:s');
        $rid = self::$requestId;
        $ctx = empty($ctx)
            ? ''
            : ' ' . json_encode($ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $line = "[{$ts}] [{$level}] [rid:{$rid}] {$msg}{$ctx}\n";

        @file_put_contents(self::$logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
