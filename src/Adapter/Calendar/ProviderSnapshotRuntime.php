<?php
declare(strict_types=1);

namespace CalendarScheduler\Adapter\Calendar;

/**
 * Provider runtime boundary for snapshot refresh.
 *
 * Implementations live in provider folders and are responsible for:
 * - loading provider config
 * - fetching raw provider events
 * - translating into provider-neutral event rows
 */
interface ProviderSnapshotRuntime
{
    public function providerName(): string;

    public function calendarId(): string;

    /**
     * @return array<int,array<string,mixed>>
     */
    public function translatedEvents(): array;
}

