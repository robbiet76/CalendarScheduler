<?php
declare(strict_types=1);

/**
 * ExecutionController
 *
 * Explicit entry point for experimental execution paths.
 *
 * IMPORTANT:
 * - Nothing in this class runs automatically.
 * - Methods are only executed when explicitly invoked.
 * - CalendarReader is wired but NOT executed in Step B.
 */
final class ExecutionController
{
    /**
     * Manual execution entry point.
     *
     * Intentionally inert for Milestone 11.4 Step B.
     * CalendarReader is referenced but not called.
     *
     * @param array $config Loaded plugin configuration
     */
    public static function run(array $config): void
    {
        // CalendarReader is intentionally NOT invoked yet.
        // This wiring exists only to validate structure.
        //
        // Example (DO NOT ENABLE YET):
        // $summary = CalendarReader::readSummary($config);
    }
}
