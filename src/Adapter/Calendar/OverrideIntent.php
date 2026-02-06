<?php

namespace CalendarScheduler\Adapter\Calendar;

final class OverrideIntent
{
    public array $originalStartTime;

    public array $start;
    public array $end;

    public array $payload = [];

    public bool $enabled = true;
    public ?string $stopType = null;
}