<?php
declare(strict_types=1);

namespace CalendarScheduler\Adapter\Calendar;

use CalendarScheduler\Adapter\Calendar\Google\GoogleSnapshotRuntime;
use CalendarScheduler\Adapter\Calendar\Outlook\OutlookSnapshotRuntime;

final class ProviderSnapshotRuntimeFactory
{
    public static function create(string $provider): ProviderSnapshotRuntime
    {
        $provider = strtolower(trim($provider));
        return match ($provider) {
            'outlook' => new OutlookSnapshotRuntime(),
            default => new GoogleSnapshotRuntime(),
        };
    }
}

