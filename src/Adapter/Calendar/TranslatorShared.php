<?php
declare(strict_types=1);

namespace CalendarScheduler\Adapter\Calendar;

use CalendarScheduler\Platform\FPPSemantics;
use CalendarScheduler\Platform\IniMetadata;
use DateTimeImmutable;
use DateTimeZone;

final class TranslatorShared
{
    /**
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    public static function normalizeSettings(array $settings): array
    {
        $out = [];
        foreach ($settings as $k => $v) {
            if (!is_string($k) || trim($k) === '') {
                continue;
            }

            $key = strtolower(trim($k));
            $compact = preg_replace('/[^a-z0-9]/', '', $key);
            if (!is_string($compact)) {
                $compact = $key;
            }

            $key = match ($compact) {
                'scheduletype' => 'type',
                'stoptype' => 'stopType',
                'enabled' => 'enabled',
                'repeat' => 'repeat',
                'type' => 'type',
                default => $key,
            };

            $out[$key] = $v;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $symbolics
     * @return array<string,mixed>
     */
    public static function normalizeSymbolicSettings(array $symbolics): array
    {
        $out = [];
        foreach ($symbolics as $k => $v) {
            if (!is_string($k) || trim($k) === '') {
                continue;
            }

            $key = strtolower(trim($k));
            $compact = preg_replace('/[^a-z0-9]/', '', $key);
            if (!is_string($compact)) {
                $compact = $key;
            }

            $normalized = match ($compact) {
                'start', 'starttime' => 'start',
                'end', 'endtime' => 'end',
                'startoffset', 'startoffsetmin', 'starttimeoffset', 'starttimeoffsetmin' => 'start_offset',
                'endoffset', 'endoffsetmin', 'endtimeoffset', 'endtimeoffsetmin' => 'end_offset',
                default => null,
            };

            if (is_string($normalized)) {
                $out[$normalized] = $v;
            }
        }

        return $out;
    }

    public static function normalizeTypeValue(string $value): string
    {
        $v = strtolower(trim($value));
        return match ($v) {
            'sequence' => FPPSemantics::TYPE_SEQUENCE,
            'command' => FPPSemantics::TYPE_COMMAND,
            default => FPPSemantics::TYPE_PLAYLIST,
        };
    }

    public static function normalizeRepeatValue(string $value): string
    {
        $v = strtolower(trim($value));
        $v = str_replace(['.', ' '], '', $v);

        if ($v === '' || $v === 'none') {
            return 'none';
        }
        if ($v === 'immediate') {
            return 'immediate';
        }

        if (preg_match('/^(\d+)min$/', $v, $m) === 1) {
            return $m[1] . 'min';
        }
        if (ctype_digit($v)) {
            $n = (int)$v;
            return $n > 0 ? (string)$n . 'min' : 'none';
        }

        return 'none';
    }

    public static function normalizeStopTypeValue(string $value): string
    {
        $v = strtolower(trim($value));
        $v = str_replace(['-', '_'], ' ', $v);
        $v = preg_replace('/\s+/', ' ', $v);
        if (!is_string($v)) {
            return 'graceful';
        }

        return match ($v) {
            'hard', 'hard stop' => 'hard',
            'graceful loop' => 'graceful_loop',
            default => 'graceful',
        };
    }

    public static function normalizeExecutionOrder(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : 0;
        }
        if (is_string($value) && is_numeric($value)) {
            $n = (int)$value;
            return $n >= 0 ? $n : 0;
        }
        return null;
    }

    public static function normalizeExecutionOrderManual(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            return is_bool($parsed) ? $parsed : null;
        }
        return null;
    }

    public static function resolveLocalTimezone(): DateTimeZone
    {
        $paths = [
            '/home/fpp/media/config/calendar-scheduler/runtime/fpp-runtime.json',
            '/home/fpp/media/config/calendar-scheduler/runtime/fpp-env.json',
        ];
        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }
            $raw = @file_get_contents($path);
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $json = @json_decode($raw, true);
            if (!is_array($json)) {
                continue;
            }

            $candidates = [
                $json['timezone'] ?? null,
                $json['settings']['TimeZone'] ?? null,
                $json['settings']['TimeZoneName'] ?? null,
                $json['settings']['timezone'] ?? null,
            ];
            foreach ($candidates as $tzName) {
                if (is_string($tzName) && trim($tzName) !== '') {
                    try {
                        return new DateTimeZone(trim($tzName));
                    } catch (\Throwable) {
                        // continue candidate scan
                    }
                }
            }
        }

        try {
            return new DateTimeZone(date_default_timezone_get());
        } catch (\Throwable) {
            return new DateTimeZone('UTC');
        }
    }

    public static function isoToEpoch(mixed $iso): ?int
    {
        if (!is_string($iso) || trim($iso) === '') {
            return null;
        }

        try {
            $dt = new DateTimeImmutable($iso);
            return $dt->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Description is treated as user input and overrides per-key metadata.
     *
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    public static function reconcileSchedulerMetadata(
        array $metadata,
        ?string $description,
        string $provider,
        string|int $schemaVersion,
        string $managedFormatVersion,
        bool $includeTimezone = false
    ): array {
        $settings = self::normalizeSettings(
            is_array($metadata['settings'] ?? null) ? $metadata['settings'] : []
        );

        $descIni = IniMetadata::fromDescription($description);
        $descSettings = self::normalizeSettings(
            is_array($descIni['settings'] ?? null) ? $descIni['settings'] : []
        );
        $descSymbolics = self::normalizeSymbolicSettings(
            is_array($descIni['symbolic_time'] ?? null) ? $descIni['symbolic_time'] : []
        );

        $settings = array_merge($settings, $descSettings, $descSymbolics);

        if (!isset($settings['type']) || !is_string($settings['type']) || trim($settings['type']) === '') {
            $settings['type'] = FPPSemantics::TYPE_PLAYLIST;
        } else {
            $settings['type'] = self::normalizeTypeValue((string)$settings['type']);
        }

        unset($settings['target']);

        if (!array_key_exists('enabled', $settings)) {
            $settings['enabled'] = FPPSemantics::defaultBehavior()['enabled'];
        } else {
            $settings['enabled'] = FPPSemantics::normalizeEnabled($settings['enabled']);
        }

        if (!isset($settings['repeat']) || !is_string($settings['repeat']) || trim($settings['repeat']) === '') {
            $settings['repeat'] = FPPSemantics::repeatToSemantic(
                FPPSemantics::defaultBehavior()['repeat']
            );
        } else {
            $settings['repeat'] = self::normalizeRepeatValue((string)$settings['repeat']);
        }

        if (!isset($settings['stopType']) || !is_string($settings['stopType']) || trim($settings['stopType']) === '') {
            $settings['stopType'] = FPPSemantics::stopTypeToSemantic(
                FPPSemantics::defaultBehavior()['stopType']
            );
        } else {
            $settings['stopType'] = self::normalizeStopTypeValue((string)$settings['stopType']);
        }

        if (isset($settings['start']) && is_string($settings['start'])) {
            $settings['start'] = FPPSemantics::normalizeSymbolicTimeToken(trim($settings['start']));
            if ($settings['start'] === '' || $settings['start'] === null) {
                unset($settings['start'], $settings['start_offset']);
            } else {
                $settings['start_offset'] = FPPSemantics::normalizeTimeOffset($settings['start_offset'] ?? 0);
            }
        } else {
            unset($settings['start'], $settings['start_offset']);
        }

        if (isset($settings['end']) && is_string($settings['end'])) {
            $settings['end'] = FPPSemantics::normalizeSymbolicTimeToken(trim($settings['end']));
            if ($settings['end'] === '' || $settings['end'] === null) {
                unset($settings['end'], $settings['end_offset']);
            } else {
                $settings['end_offset'] = FPPSemantics::normalizeTimeOffset($settings['end_offset'] ?? 0);
            }
        } else {
            unset($settings['end'], $settings['end_offset']);
        }

        ksort($settings);

        $currentFormatVersion = is_string($metadata['formatVersion'] ?? null)
            ? trim((string)$metadata['formatVersion'])
            : '';

        $out = [
            'manifestEventId' => is_string($metadata['manifestEventId'] ?? null)
                ? $metadata['manifestEventId']
                : null,
            'subEventHash' => is_string($metadata['subEventHash'] ?? null)
                ? $metadata['subEventHash']
                : null,
            'provider' => is_string($metadata['provider'] ?? null)
                ? $metadata['provider']
                : $provider,
            'schemaVersion' => $schemaVersion,
            'formatVersion' => $managedFormatVersion,
            'needsFormatRefresh' => ($currentFormatVersion !== $managedFormatVersion),
            'executionOrder' => self::normalizeExecutionOrder($metadata['executionOrder'] ?? null),
            'executionOrderManual' => self::normalizeExecutionOrderManual($metadata['executionOrderManual'] ?? null),
            'settings' => $settings,
        ];

        if ($includeTimezone) {
            $out['timezone'] = is_string($metadata['timezone'] ?? null) && trim((string)$metadata['timezone']) !== ''
                ? trim((string)$metadata['timezone'])
                : null;
        }

        return $out;
    }
}
