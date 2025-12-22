<?php
declare(strict_types=1);

/**
 * ScopedLogger
 *
 * Logging wrapper for experimental paths.
 * Hard-disabled for Milestone 11.1 (no log output permitted).
 */
final class ScopedLogger
{
    private const ENABLED = false;

    public static function log(string $message): void
    {
        if (!self::ENABLED) {
            return;
        }

        // Intentionally unreachable in 11.1.
        // Future: call the project's logger here.
        // Log::debug('[Experimental] ' . $message);
    }
}
