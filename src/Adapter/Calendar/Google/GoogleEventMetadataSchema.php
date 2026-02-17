<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Adapter/Calendar/Google/GoogleEventMetadataSchema.php
 * Purpose: Defines the GoogleEventMetadataSchema component used by the Calendar Scheduler Adapter/Calendar/Google layer.
 */

namespace CalendarScheduler\Adapter\Calendar\Google;

/**
 * Canonical key registry for Google event private metadata.
 *
 * All calendar-side metadata keys used in extendedProperties.private
 * must be defined here to keep reads/writes versioned and consistent.
 */
final class GoogleEventMetadataSchema
{
    public const VERSION = '2';

    public const KEY_MANIFEST_EVENT_ID = 'cs.manifestEventId';
    public const KEY_SUB_EVENT_HASH = 'cs.subEventHash';
    public const KEY_PROVIDER = 'cs.provider';
    public const KEY_SCHEMA_VERSION = 'cs.schemaVersion';
    public const KEY_FORMAT_VERSION = 'cs.formatVersion';
    public const KEY_TYPE = 'cs.type';
    public const KEY_ENABLED = 'cs.enabled';
    public const KEY_REPEAT = 'cs.repeat';
    public const KEY_STOP_TYPE = 'cs.stopType';
    public const KEY_SYMBOLIC_START = 'cs.symbolicStart';
    public const KEY_SYMBOLIC_START_OFFSET = 'cs.symbolicStartOffset';
    public const KEY_SYMBOLIC_END = 'cs.symbolicEnd';
    public const KEY_SYMBOLIC_END_OFFSET = 'cs.symbolicEndOffset';

    private function __construct()
    {
    }

    /**
     * @param string $manifestEventId
     * @param string $subEventHash
     * @param string $provider
     * @return array<string,string>
     */
    public static function privateMetadata(
        string $manifestEventId,
        string $subEventHash,
        string $provider = 'google',
        ?string $formatVersion = null,
        ?string $type = null,
        ?bool $enabled = null,
        ?string $repeat = null,
        ?string $stopType = null,
        ?string $symbolicStart = null,
        ?int $symbolicStartOffset = null,
        ?string $symbolicEnd = null,
        ?int $symbolicEndOffset = null
    ): array {
        $out = [
            self::KEY_MANIFEST_EVENT_ID => $manifestEventId,
            self::KEY_SUB_EVENT_HASH => $subEventHash,
            self::KEY_PROVIDER => $provider,
            self::KEY_SCHEMA_VERSION => self::VERSION,
        ];
        if (is_string($formatVersion) && trim($formatVersion) !== '') {
            $out[self::KEY_FORMAT_VERSION] = trim($formatVersion);
        }

        if (is_string($type) && trim($type) !== '') {
            $out[self::KEY_TYPE] = trim($type);
        }
        if (is_bool($enabled)) {
            $out[self::KEY_ENABLED] = $enabled ? 'true' : 'false';
        }
        if (is_string($repeat) && trim($repeat) !== '') {
            $out[self::KEY_REPEAT] = trim($repeat);
        }
        if (is_string($stopType) && trim($stopType) !== '') {
            $out[self::KEY_STOP_TYPE] = trim($stopType);
        }
        if (is_string($symbolicStart) && trim($symbolicStart) !== '') {
            $out[self::KEY_SYMBOLIC_START] = trim($symbolicStart);
            $out[self::KEY_SYMBOLIC_START_OFFSET] = (string)($symbolicStartOffset ?? 0);
        }
        if (is_string($symbolicEnd) && trim($symbolicEnd) !== '') {
            $out[self::KEY_SYMBOLIC_END] = trim($symbolicEnd);
            $out[self::KEY_SYMBOLIC_END_OFFSET] = (string)($symbolicEndOffset ?? 0);
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $private
     * @return array<string,mixed>
     */
    public static function decodePrivateMetadata(array $private): array
    {
        $manifestEventId = self::readString($private, self::KEY_MANIFEST_EVENT_ID);
        $subEventHash = self::readString($private, self::KEY_SUB_EVENT_HASH);
        $provider = self::readString($private, self::KEY_PROVIDER);
        $schemaVersion = self::readString($private, self::KEY_SCHEMA_VERSION);
        $formatVersion = self::readString($private, self::KEY_FORMAT_VERSION);

        $settings = [];

        $type = self::readString($private, self::KEY_TYPE);
        if ($type !== null) {
            $settings['type'] = $type;
        }

        $enabled = self::readBool($private, self::KEY_ENABLED);
        if ($enabled !== null) {
            $settings['enabled'] = $enabled;
        }

        $repeat = self::readString($private, self::KEY_REPEAT);
        if ($repeat !== null) {
            $settings['repeat'] = $repeat;
        }

        $stopType = self::readString($private, self::KEY_STOP_TYPE);
        if ($stopType !== null) {
            $settings['stopType'] = $stopType;
        }

        $symbolicStart = self::readString($private, self::KEY_SYMBOLIC_START);
        if ($symbolicStart !== null) {
            $settings['start'] = $symbolicStart;
            $settings['start_offset'] = self::readInt($private, self::KEY_SYMBOLIC_START_OFFSET) ?? 0;
        }

        $symbolicEnd = self::readString($private, self::KEY_SYMBOLIC_END);
        if ($symbolicEnd !== null) {
            $settings['end'] = $symbolicEnd;
            $settings['end_offset'] = self::readInt($private, self::KEY_SYMBOLIC_END_OFFSET) ?? 0;
        }

        return [
            'manifestEventId' => $manifestEventId,
            'subEventHash' => $subEventHash,
            'provider' => $provider,
            'schemaVersion' => $schemaVersion,
            'formatVersion' => $formatVersion,
            'settings' => $settings,
        ];
    }

    /**
     * @param array<string,mixed> $ev
     * @return array<string,mixed>
     */
    public static function decodeFromGoogleEvent(array $ev): array
    {
        $private = $ev['extendedProperties']['private'] ?? null;
        if (!is_array($private)) {
            return [
                'manifestEventId' => null,
                'subEventHash' => null,
                'provider' => null,
                'schemaVersion' => null,
                'formatVersion' => null,
                'settings' => [],
            ];
        }

        return self::decodePrivateMetadata($private);
    }

    /**
     * @param array<string,mixed> $arr
     */
    private static function readString(array $arr, string $key): ?string
    {
        $v = $arr[$key] ?? null;
        if (!is_string($v)) {
            return null;
        }
        $v = trim($v);
        return $v === '' ? null : $v;
    }

    /**
     * @param array<string,mixed> $arr
     */
    private static function readBool(array $arr, string $key): ?bool
    {
        $v = $arr[$key] ?? null;
        if (is_bool($v)) {
            return $v;
        }
        if (!is_string($v)) {
            return null;
        }
        $parsed = filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return is_bool($parsed) ? $parsed : null;
    }

    /**
     * @param array<string,mixed> $arr
     */
    private static function readInt(array $arr, string $key): ?int
    {
        $v = $arr[$key] ?? null;
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (int)$v;
        }
        return null;
    }
}
