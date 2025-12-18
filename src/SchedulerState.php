<?php

/**
 * Loads existing FPP scheduler state.
 */
final class SchedulerState
{
    private const SCHEDULE_PATH = '/home/fpp/media/config/schedule.json';

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function load(): array
    {
        if (!file_exists(self::SCHEDULE_PATH)) {
            return [];
        }

        $raw = file_get_contents(self::SCHEDULE_PATH);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }
}
