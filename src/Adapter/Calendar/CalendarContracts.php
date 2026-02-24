<?php
declare(strict_types=1);

namespace CalendarScheduler\Adapter\Calendar;

interface CalendarApplyRuntime
{
    public function providerName(): string;

    public function payloadEventIdField(): string;

    public function correlationEventIdsField(): string;

    /**
     * @param array<int,\CalendarScheduler\Diff\ReconciliationAction> $actions
     * @return array<int,CalendarMutationLink>
     */
    public function applyActions(array $actions): array;
}

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

final class CalendarMutationLink
{
    public function __construct(
        public readonly string $op,
        public readonly string $manifestEventId,
        public readonly string $subEventHash,
        public readonly string $providerEventId
    ) {}
}
