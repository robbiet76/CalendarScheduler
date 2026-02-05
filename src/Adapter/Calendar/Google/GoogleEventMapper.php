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
     * Map an ApplyOp into a Google Calendar mutation instruction.
     *
     * @param array $applyOp  Fully-resolved ApplyOp (Diff output)
     * @param string $timezone FPP local timezone (e.g. "America/Chicago")
     *
     * @return array {
     *   method: "create"|"update"|"delete",
     *   eventId?: string,
     *   etag?: string,
     *   payload?: array
     * }
     */
    public function mapApplyOp(array $applyOp, string $timezone): array
    {
        $op = $applyOp['op'] ?? null;

        if (!in_array($op, ['create', 'update', 'delete'], true)) {
            throw new RuntimeException("Invalid ApplyOp: unsupported op '{$op}'");
        }

        if ($op === 'delete') {
            return $this->mapDelete($applyOp);
        }

        return $this->mapCreateOrUpdate($applyOp, $timezone);
    }

    /**
     * DELETE mapping
     */
    private function mapDelete(array $applyOp): array
    {
        if (empty($applyOp['providerEventId'])) {
            throw new RuntimeException('Delete ApplyOp missing providerEventId');
        }

        return [
            'method'  => 'delete',
            'eventId' => $applyOp['providerEventId'],
            'etag'    => $applyOp['etag'] ?? null,
        ];
    }

    /**
     * CREATE / UPDATE mapping
     */
    private function mapCreateOrUpdate(array $applyOp, string $timezone): array
    {
        $base = $applyOp['baseSubEvent'] ?? null;

        if (!is_array($base)) {
            throw new RuntimeException('ApplyOp missing baseSubEvent');
        }

        $payload = [
            'summary'     => $this->mapSummary($applyOp),
            'description' => $this->mapDescription($applyOp),
            'start'       => $this->mapDateTime($base['start'], $timezone),
            'end'         => $this->mapDateTime($base['end'], $timezone),
        ];

        // Recurrence
        if (!empty($base['rrule'])) {
            $payload['recurrence'] = [$base['rrule']];
        }

        // Exceptions → EXDATE
        if (!empty($applyOp['exceptionSubEvents'])) {
            $payload['recurrence'] ??= [];
            $payload['recurrence'][] = $this->mapExDates(
                $applyOp['exceptionSubEvents'],
                $timezone
            );
        }

        // Extended properties (machine-authoritative)
        $payload['extendedProperties'] = [
            'private' => [
                'cs.manifestEventId' => $applyOp['manifestEventId'],
                'cs.provider'        => 'google',
                'cs.schemaVersion'   => '1', // bump only via explicit migration
            ],
        ];

        $method = match ((string)$applyOp['op']) {
            'create' => 'create',
            'update' => 'update',
            default  => throw new RuntimeException('Invalid ApplyOp: unsupported op for create/update'),
        };

        $result = [
            'method'  => $method,
            'payload' => $payload,
        ];

        if ($applyOp['op'] === 'update') {
            if (empty($applyOp['providerEventId'])) {
                throw new RuntimeException('Update ApplyOp missing providerEventId');
            }

            $result['eventId'] = $applyOp['providerEventId'];
            $result['etag']    = $applyOp['etag'] ?? null;
        }

        return $result;
    }

    /**
     * Summary mapping
     */
    private function mapSummary(array $applyOp): string
    {
        return (string)($applyOp['summary'] ?? '');
    }

    /**
     * Description mapping
     *
     * Description is opaque; Apply already assembled it.
     */
    private function mapDescription(array $applyOp): string
    {
        return (string)($applyOp['description'] ?? '');
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