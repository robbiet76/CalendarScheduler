<?php
declare(strict_types=1);

namespace CalendarScheduler\Intent;

/**
 * RawEventValidator
 *
 * Runtime guard to enforce the RawEvent contract.
 *
 * This MUST only be used at system boundaries:
 *  - Adapter → IntentNormalizer
 *
 * It MUST NOT be used inside Diff or reconciliation logic.
 *
 * Any failure here is an adapter bug, not a diff bug.
 */
final class RawEventValidator
{
    public static function assertValid(RawEvent $e): void
    {
        // ----------------------------
        // Source
        // ----------------------------
        if (!in_array($e->source, ['fpp', 'calendar'], true)) {
            throw new \RuntimeException(
                "RawEvent.source must be 'fpp' or 'calendar'"
            );
        }

        // ----------------------------
        // Type
        // ----------------------------
        if (!in_array($e->type, ['command', 'playlist', 'sequence'], true)) {
            throw new \RuntimeException(
                "RawEvent.type must be command | playlist | sequence"
            );
        }

        // ----------------------------
        // Target
        // ----------------------------
        if (!is_string($e->target) || trim($e->target) === '') {
            throw new \RuntimeException(
                "RawEvent.target must be a non-empty string"
            );
        }

        // ----------------------------
        // Timing
        // ----------------------------
        self::assertTiming($e->timing);

        // ----------------------------
        // Payload
        // ----------------------------
        self::assertPayload($e->payload, $e->type);

        // ----------------------------
        // Ownership
        // ----------------------------
        self::assertOwnership($e->ownership);

        // ----------------------------
        // Correlation
        // ----------------------------
        if (!is_array($e->correlation)) {
            throw new \RuntimeException(
                "RawEvent.correlation must be an array"
            );
        }

        // ----------------------------
        // Authority timestamp
        // ----------------------------
        if (!is_int($e->sourceUpdatedAt) || $e->sourceUpdatedAt <= 0) {
            throw new \RuntimeException(
                "RawEvent.sourceUpdatedAt must be a valid epoch integer"
            );
        }
    }

    // ==========================================================
    // Helpers
    // ==========================================================

    private static function assertTiming(array $timing): void
    {
        $requiredKeys = [
            'all_day',
            'start_date',
            'end_date',
            'start_time',
            'end_time',
            'days',
        ];

        foreach ($requiredKeys as $k) {
            if (!array_key_exists($k, $timing)) {
                throw new \RuntimeException("Timing missing key '{$k}'");
            }
        }

        if (!is_bool($timing['all_day'])) {
            throw new \RuntimeException("timing.all_day must be boolean");
        }

        self::assertDateField($timing['start_date'], 'start_date');
        self::assertDateField($timing['end_date'], 'end_date');
        self::assertTimeField($timing['start_time'], 'start_time');
        self::assertTimeField($timing['end_time'], 'end_time');
        self::assertDaysField($timing['days']);
    }

    private static function assertDateField(mixed $v, string $label): void
    {
        if (!is_array($v)) {
            throw new \RuntimeException("timing.{$label} must be an array");
        }

        if (!array_key_exists('hard', $v) || !array_key_exists('symbolic', $v)) {
            throw new \RuntimeException(
                "timing.{$label} must contain hard and symbolic keys"
            );
        }

        if ($v['hard'] !== null && !is_string($v['hard'])) {
            throw new \RuntimeException(
                "timing.{$label}.hard must be string or null"
            );
        }

        if ($v['symbolic'] !== null && !is_string($v['symbolic'])) {
            throw new \RuntimeException(
                "timing.{$label}.symbolic must be string or null"
            );
        }
    }

    private static function assertTimeField(mixed $v, string $label): void
    {
        if ($v === null) {
            return;
        }

        if (!is_array($v)) {
            throw new \RuntimeException("timing.{$label} must be array or null");
        }

        foreach (['hard', 'symbolic', 'offset'] as $k) {
            if (!array_key_exists($k, $v)) {
                throw new \RuntimeException(
                    "timing.{$label} missing key '{$k}'"
                );
            }
        }

        if ($v['hard'] !== null && !is_string($v['hard'])) {
            throw new \RuntimeException(
                "timing.{$label}.hard must be string or null"
            );
        }

        if ($v['symbolic'] !== null && !is_string($v['symbolic'])) {
            throw new \RuntimeException(
                "timing.{$label}.symbolic must be string or null"
            );
        }

        if (!is_int($v['offset'])) {
            throw new \RuntimeException(
                "timing.{$label}.offset must be int"
            );
        }
    }

    private static function assertDaysField(mixed $v): void
    {
        if ($v === null) {
            return;
        }

        if (!is_array($v)) {
            throw new \RuntimeException("timing.days must be array or null");
        }

        if (($v['type'] ?? null) !== 'weekly') {
            throw new \RuntimeException("timing.days.type must be 'weekly'");
        }

        if (!isset($v['value']) || !is_array($v['value'])) {
            throw new \RuntimeException("timing.days.value must be array");
        }

        foreach ($v['value'] as $d) {
            if (!in_array($d, ['SU','MO','TU','WE','TH','FR','SA'], true)) {
                throw new \RuntimeException(
                    "Invalid day token '{$d}' in timing.days"
                );
            }
        }
    }

    private static function assertPayload(array $payload, string $type): void
    {
        foreach (['enabled','repeat','stopType'] as $k) {
            if (!array_key_exists($k, $payload)) {
                throw new \RuntimeException("payload missing key '{$k}'");
            }
        }

        if (!is_bool($payload['enabled'])) {
            throw new \RuntimeException("payload.enabled must be boolean");
        }

        if (!is_string($payload['repeat'])) {
            throw new \RuntimeException("payload.repeat must be string");
        }

        if (!is_string($payload['stopType'])) {
            throw new \RuntimeException("payload.stopType must be string");
        }

        if ($type === 'command') {
            if (!isset($payload['command']) || !is_array($payload['command'])) {
                throw new \RuntimeException(
                    "payload.command required for command type"
                );
            }
        }
    }

    private static function assertOwnership(array $ownership): void
    {
        foreach (['managed','controller','locked'] as $k) {
            if (!array_key_exists($k, $ownership)) {
                throw new \RuntimeException("ownership missing key '{$k}'");
            }
        }

        if (!is_bool($ownership['managed'])) {
            throw new \RuntimeException("ownership.managed must be boolean");
        }

        if (!in_array($ownership['controller'], ['fpp','calendar'], true)) {
            throw new \RuntimeException(
                "ownership.controller must be 'fpp' or 'calendar'"
            );
        }

        if (!is_bool($ownership['locked'])) {
            throw new \RuntimeException("ownership.locked must be boolean");
        }
    }
}