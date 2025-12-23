<?php
declare(strict_types=1);

/**
 * ExecutionController
 *
 * Explicit entry point for experimental execution paths.
 *
 * TEMPORARY STATE (Milestone 11.4 Step C):
 * - Invokes CalendarReader in read-only mode
 * - Logs summary information only
 */
final class ExecutionController
{
    /**
     * Manual execution entry point.
     *
     * @param array $config Loaded plugin configuration
     */
    public static function run(array $config): void
    {
        $summary = CalendarReader::readSummary($config);

        ScopedLogger::log(
            'Calendar read summary ' . json_encode($summary)
        );
    }
}
