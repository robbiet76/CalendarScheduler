<?php
declare(strict_types=1);

final class ExportService
{
    private const FPP_ENV_PATH =
        __DIR__ . '/../../runtime/fpp-env.json';

    /**
     * Export scheduler entries into calendar payload.
     *
     * @param array<int,array<string,mixed>> $entries
     * @return array<string,mixed>
     */
    public static function export(array $entries): array
    {
        $warnings = [];

        // -----------------------------------------------------------------
        // Load runtime FPP environment
        // -----------------------------------------------------------------
        $env = FppEnvironment::loadFromFile(
            self::FPP_ENV_PATH,
            $warnings
        );

        // Register environment with FPPSemantics
        FPPSemantics::setEnvironment($env->toArray());

        // Optional: set PHP default timezone for DateTime operations
        if ($env->getTimezone()) {
            date_default_timezone_set($env->getTimezone());
        }

        // -----------------------------------------------------------------
        // Export entries
        // -----------------------------------------------------------------
        $events = [];

        foreach ($entries as $entry) {
            $adapted = ScheduleEntryExportAdapter::adapt($entry, $warnings);
            if ($adapted !== null) {
                $events[] = $adapted;
            }
        }

        return [
            'events'   => $events,
            'warnings' => $warnings,
            'fppEnv'   => $env->toArray(),
        ];
    }
}