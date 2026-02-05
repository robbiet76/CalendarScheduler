<?php

declare(strict_types=1);

namespace CalendarScheduler\Adapter\Calendar\Google;

use RuntimeException;

/**
 * GoogleEventMapper
 *
 * NOTE:
 * - This mapper currently assumes a single base SubEvent per ManifestEvent.
 * - Multi-SubEvent collapse rules will be introduced in a future revision.
 * - Calendar ID selection is handled by the Apply executor / API client, not here.
 * - Description content is treated as fully-assembled opaque text and MUST NOT be modified here.
 *
 * Pure mapper from ApplyOp → Google Calendar API mutation payload.
 *
 * No I/O. No authority. No normalization.
 */
final class GoogleEventMapper
{
    /**
     * Map a ReconciliationAction into a Google Calendar mutation instruction.
     *
     * @param ReconciliationAction $action
     * @param GoogleConfig $config
     *
     * @return array {
     *   method: "create"|"update"|"delete",
     *   eventId?: string,
     *   etag?: string,
     *   payload?: array
     * }
     */
    public function mapAction(object $action, object $config): array
    {
        // $action is expected to be a ReconciliationAction instance
        // $config is expected to be a GoogleConfig instance
        $op = $action->op ?? null;
        $timezone = $config->timezone ?? null;
        if (!in_array($op, ['create', 'update', 'delete'], true)) {
            throw new RuntimeException("Invalid ReconciliationAction: unsupported op '{$op}'");
        }
        if ($op === 'delete') {
            return $this->mapDeleteFromAction($action);
        }
        return $this->mapCreateOrUpdateFromAction($action, $timezone);
    }

    /**
     * DELETE mapping
     */
    private function mapDeleteFromAction(object $action): array
    {
        if (empty($action->providerEventId)) {
            throw new RuntimeException('Delete ReconciliationAction missing providerEventId');
        }
        return [
            'method'  => 'delete',
            'eventId' => $action->providerEventId,
            'etag'    => $action->etag ?? null,
        ];
    }

    /**
     * CREATE / UPDATE mapping
     */
    private function mapCreateOrUpdateFromAction(object $action, string $timezone): array
    {
        $base = $action->baseSubEvent ?? null;
        if (!is_array($base)) {
            throw new RuntimeException('ReconciliationAction missing baseSubEvent');
        }
        $payload = [
            'summary'     => $this->mapSummaryFromAction($action),
            'description' => $this->mapDescriptionFromAction($action),
            'start'       => $this->mapDateTime($base['start'], $timezone),
            'end'         => $this->mapDateTime($base['end'], $timezone),
        ];
        // Recurrence
        if (!empty($base['rrule'])) {
            $payload['recurrence'] = [$base['rrule']];
        }
        // Exceptions → EXDATE
        if (!empty($action->exceptionSubEvents)) {
            $payload['recurrence'] ??= [];
            $payload['recurrence'][] = $this->mapExDates(
                $action->exceptionSubEvents,
                $timezone
            );
        }
        // Extended properties (machine-authoritative)
        $payload['extendedProperties'] = [
            'private' => [
                'cs.manifestEventId' => $action->manifestEventId,
                'cs.provider'        => 'google',
                'cs.schemaVersion'   => '1', // bump only via explicit migration
            ],
        ];
        $method = match ((string)$action->op) {
            'create' => 'create',
            'update' => 'update',
            default  => throw new RuntimeException('Invalid ReconciliationAction: unsupported op for create/update'),
        };
        $result = [
            'method'  => $method,
            'payload' => $payload,
        ];
        if ($action->op === 'update') {
            if (empty($action->providerEventId)) {
                throw new RuntimeException('Update ReconciliationAction missing providerEventId');
            }
            $result['eventId'] = $action->providerEventId;
            $result['etag']    = $action->etag ?? null;
        }
        return $result;
    }

    /**
     * Summary mapping
     */
    private function mapSummaryFromAction(object $action): string
    {
        return (string)($action->summary ?? '');
    }

    /**
     * Description mapping
     *
     * Description is opaque; Apply already assembled it.
     */
    private function mapDescriptionFromAction(object $action): string
    {
        return (string)($action->description ?? '');
    }

    /**
     * Map start/end DateTime
     *
     * $dt shape expected:
     * [
     *   'date' => 'YYYY-MM-DD' | null,
     *   'time' => 'HH:MM:SS' | null,
     *   'allDay' => bool
     * ]
     */
    private function mapDateTime(array $dt, string $timezone): array
    {
        if (!empty($dt['allDay'])) {
            return [
                'date' => $dt['date'],
            ];
        }

        return [
            'dateTime' => $dt['date'] . 'T' . $dt['time'],
            'timeZone' => $timezone,
        ];
    }

    /**
     * Map exception SubEvents → EXDATE RRULE fragment
     */
    private function mapExDates(array $exceptions, string $timezone): string
    {
        $values = [];

        foreach ($exceptions as $ex) {
            if (!isset($ex['start'])) {
                continue;
            }

            if (!empty($ex['start']['allDay'])) {
                $values[] = str_replace('-', '', $ex['start']['date']);
            } else {
                $values[] =
                    str_replace('-', '', $ex['start']['date']) .
                    'T' .
                    str_replace(':', '', $ex['start']['time']);
            }
        }

        if (empty($values)) {
            return '';
        }

        return 'EXDATE;TZID=' . $timezone . ':' . implode(',', $values);
    }
}