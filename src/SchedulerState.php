<?php

class SchedulerState
{
    /**
     * Load existing FPP scheduler entries owned by GoogleCalendarScheduler
     *
     * @return ExistingScheduleEntry[]
     */
    public static function loadExisting(): array
    {
        $entries = [];

        // TODO:
        // 1. Load scheduler JSON from FPP
        // 2. Filter entries containing "GCS:v1|"
        // 3. Extract UID, date range, metadata
        // 4. Instantiate ExistingScheduleEntry objects

        Logger::info('Loaded existing GCS scheduler entries', [
            'count' => count($entries)
        ]);

        return $entries;
    }
}
