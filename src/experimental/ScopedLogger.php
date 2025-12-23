<?php
declare(strict_types=1);

/**
 * ScopedLogger
 *
 * Logging wrapper for experimental paths.
 *
 * IMPORTANT:
 * - Logging is OFF by default.
 * - Enablement is explicit and local to this file.
 * - No runtime behavior changes unless ENABLED is set to true.
 */
final class ScopedLogger
{
    /**
     * Experimental logging enable switch.
     *
     * MUST remain false unless explicitly testing.
     */
    private const ENABLED = false;

    /**
     * Write an experimental log entry.
     *
     * @param string $message
     */
    public static function log(string $message): void
    {
        if (!self::ENABLED) {
            return;
        }

        // Experimental logging path (opt-in only)
        GcsLog::info('[Experimental] ' . $message);
    }
}
