<?php
declare(strict_types=1);

namespace CalendarScheduler\Adapter\Calendar;

use CalendarScheduler\Adapter\Calendar\Google\GoogleApiClient;
use CalendarScheduler\Adapter\Calendar\Google\GoogleApplyExecutor;
use CalendarScheduler\Adapter\Calendar\Google\GoogleApplyRuntime;
use CalendarScheduler\Adapter\Calendar\Google\GoogleConfig;
use CalendarScheduler\Adapter\Calendar\Google\GoogleEventMapper;
use CalendarScheduler\Adapter\Calendar\Outlook\OutlookApiClient;
use CalendarScheduler\Adapter\Calendar\Outlook\OutlookApplyExecutor;
use CalendarScheduler\Adapter\Calendar\Outlook\OutlookApplyRuntime;
use CalendarScheduler\Adapter\Calendar\Outlook\OutlookConfig;
use CalendarScheduler\Adapter\Calendar\Outlook\OutlookEventMapper;

final class CalendarApplyRuntimeFactory
{
    public static function create(string $provider): ?CalendarApplyRuntime
    {
        $provider = strtolower(trim($provider));

        if ($provider === 'outlook') {
            $outlookConfigPath = '/home/fpp/media/config/calendar-scheduler/calendar/outlook';
            if (!(is_dir($outlookConfigPath) || is_file($outlookConfigPath))) {
                return null;
            }

            $config = new OutlookConfig($outlookConfigPath);
            $client = new OutlookApiClient($config);
            $mapper = new OutlookEventMapper();
            $executor = new OutlookApplyExecutor($client, $mapper);

            return new OutlookApplyRuntime($executor);
        }

        $googleConfigPath = '/home/fpp/media/config/calendar-scheduler/calendar/google';
        if (!(is_dir($googleConfigPath) || is_file($googleConfigPath))) {
            return null;
        }

        $config = new GoogleConfig($googleConfigPath);
        $client = new GoogleApiClient($config);
        $mapper = new GoogleEventMapper();
        $executor = new GoogleApplyExecutor($client, $mapper);

        return new GoogleApplyRuntime($executor);
    }
}
