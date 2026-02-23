<?php
declare(strict_types=1);

namespace CalendarScheduler\Adapter\Calendar;

use CalendarScheduler\Adapter\Calendar\Google\GoogleApiClient;
use CalendarScheduler\Adapter\Calendar\Google\GoogleApplyExecutor;
use CalendarScheduler\Adapter\Calendar\Google\GoogleConfig;
use CalendarScheduler\Adapter\Calendar\Google\GoogleEventMapper;
use CalendarScheduler\Adapter\Calendar\Google\GoogleMutation;
use CalendarScheduler\Adapter\Calendar\Google\GoogleMutationResult;
use CalendarScheduler\Adapter\Calendar\Google\GoogleCalendarTranslator;
use CalendarScheduler\Adapter\Calendar\Outlook\OutlookApiClient;
use CalendarScheduler\Adapter\Calendar\Outlook\OutlookApplyExecutor;
use CalendarScheduler\Adapter\Calendar\Outlook\OutlookCalendarTranslator;
use CalendarScheduler\Adapter\Calendar\Outlook\OutlookConfig;
use CalendarScheduler\Adapter\Calendar\Outlook\OutlookEventMapper;
use CalendarScheduler\Adapter\Calendar\Outlook\OutlookMutation;
use CalendarScheduler\Adapter\Calendar\Outlook\OutlookMutationResult;

final class ProviderRuntimeFactory
{
    public static function createSnapshot(string $provider): ProviderSnapshotRuntime
    {
        $provider = self::normalizeProvider($provider);
        if ($provider === 'outlook') {
            $config = new OutlookConfig('/home/fpp/media/config/calendar-scheduler/calendar/outlook');
            $client = new OutlookApiClient($config);
            $translator = new OutlookCalendarTranslator();

            return new class (
                'outlook',
                $config->getCalendarId(),
                static fn() => $translator->ingest(
                    $client->listEvents($config->getCalendarId()),
                    $config->getCalendarId()
                )
            ) implements ProviderSnapshotRuntime {
                /** @var callable():array<int,array<string,mixed>> */
                private $translatedEventsFn;

                /**
                 * @param callable():array<int,array<string,mixed>> $translatedEventsFn
                 */
                public function __construct(
                    private readonly string $provider,
                    private readonly string $calendarIdValue,
                    callable $translatedEventsFn
                ) {
                    $this->translatedEventsFn = $translatedEventsFn;
                }

                public function providerName(): string
                {
                    return $this->provider;
                }

                public function calendarId(): string
                {
                    return $this->calendarIdValue;
                }

                public function translatedEvents(): array
                {
                    return ($this->translatedEventsFn)();
                }
            };
        }

        $config = new GoogleConfig('/home/fpp/media/config/calendar-scheduler/calendar/google');
        $client = new GoogleApiClient($config);
        $translator = new GoogleCalendarTranslator();

        return new class (
            'google',
            $config->getCalendarId(),
            static fn() => $translator->ingest(
                $client->listEvents($config->getCalendarId()),
                $config->getCalendarId()
            )
        ) implements ProviderSnapshotRuntime {
            /** @var callable():array<int,array<string,mixed>> */
            private $translatedEventsFn;

            /**
             * @param callable():array<int,array<string,mixed>> $translatedEventsFn
             */
            public function __construct(
                private readonly string $provider,
                private readonly string $calendarIdValue,
                callable $translatedEventsFn
            ) {
                $this->translatedEventsFn = $translatedEventsFn;
            }

            public function providerName(): string
            {
                return $this->provider;
            }

            public function calendarId(): string
            {
                return $this->calendarIdValue;
            }

            public function translatedEvents(): array
            {
                return ($this->translatedEventsFn)();
            }
        };
    }

    public static function createApply(string $provider): ?CalendarApplyRuntime
    {
        $provider = self::normalizeProvider($provider);

        if ($provider === 'outlook') {
            $configPath = '/home/fpp/media/config/calendar-scheduler/calendar/outlook';
            if (!(is_dir($configPath) || is_file($configPath))) {
                return null;
            }

            $config = new OutlookConfig($configPath);
            $client = new OutlookApiClient($config);
            $mapper = new OutlookEventMapper();
            $executor = new OutlookApplyExecutor($client, $mapper);

            return new ExecutorApplyRuntime(
                'outlook',
                'outlookEventId',
                'outlookEventIds',
                static function (array $actions) use ($executor): array {
                    $results = $executor->applyActions($actions);
                    $links = [];
                    foreach ($results as $result) {
                        if (!($result instanceof OutlookMutationResult)) {
                            continue;
                        }
                        if (($result->op ?? '') !== OutlookMutation::OP_CREATE && ($result->op ?? '') !== OutlookMutation::OP_UPDATE) {
                            continue;
                        }
                        $eventId = is_string($result->outlookEventId ?? null) ? trim((string)$result->outlookEventId) : '';
                        if ($eventId === '') {
                            continue;
                        }
                        $manifestEventId = is_string($result->manifestEventId ?? null) ? trim((string)$result->manifestEventId) : '';
                        $subEventHash = is_string($result->subEventHash ?? null) ? trim((string)$result->subEventHash) : '';
                        if ($manifestEventId === '' || $subEventHash === '') {
                            continue;
                        }
                        $links[] = new CalendarMutationLink(
                            (string)$result->op,
                            $manifestEventId,
                            $subEventHash,
                            $eventId
                        );
                    }
                    return $links;
                }
            );
        }

        $configPath = '/home/fpp/media/config/calendar-scheduler/calendar/google';
        if (!(is_dir($configPath) || is_file($configPath))) {
            return null;
        }

        $config = new GoogleConfig($configPath);
        $client = new GoogleApiClient($config);
        $mapper = new GoogleEventMapper();
        $executor = new GoogleApplyExecutor($client, $mapper);

        return new ExecutorApplyRuntime(
            'google',
            'googleEventId',
            'googleEventIds',
            static function (array $actions) use ($executor): array {
                $results = $executor->applyActions($actions);
                $links = [];
                foreach ($results as $result) {
                    if (!($result instanceof GoogleMutationResult)) {
                        continue;
                    }
                    if (($result->op ?? '') !== GoogleMutation::OP_CREATE && ($result->op ?? '') !== GoogleMutation::OP_UPDATE) {
                        continue;
                    }
                    $eventId = is_string($result->googleEventId ?? null) ? trim((string)$result->googleEventId) : '';
                    if ($eventId === '') {
                        continue;
                    }
                    $manifestEventId = is_string($result->manifestEventId ?? null) ? trim((string)$result->manifestEventId) : '';
                    $subEventHash = is_string($result->subEventHash ?? null) ? trim((string)$result->subEventHash) : '';
                    if ($manifestEventId === '' || $subEventHash === '') {
                        continue;
                    }
                    $links[] = new CalendarMutationLink(
                        (string)$result->op,
                        $manifestEventId,
                        $subEventHash,
                        $eventId
                    );
                }
                return $links;
            }
        );
    }

    private static function normalizeProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        return $provider === 'outlook' ? 'outlook' : 'google';
    }
}
