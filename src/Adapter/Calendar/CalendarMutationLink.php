<?php
declare(strict_types=1);

namespace CalendarScheduler\Adapter\Calendar;

final class CalendarMutationLink
{
    public function __construct(
        public readonly string $op,
        public readonly string $manifestEventId,
        public readonly string $subEventHash,
        public readonly string $providerEventId
    ) {}
}

