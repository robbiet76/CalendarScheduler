<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Adapter/Calendar/Outlook/OutlookEventMetadataSchema.php
 * Purpose: Canonical metadata key registry for Outlook event private metadata.
 */

namespace CalendarScheduler\Adapter\Calendar\Outlook;

final class OutlookEventMetadataSchema
{
    public const VERSION = '2';
    public const EXTENDED_PROPERTY_SET_GUID = '00020329-0000-0000-C000-000000000046';

    public const KEY_MANIFEST_EVENT_ID = 'cs.manifestEventId';
    public const KEY_SUB_EVENT_HASH = 'cs.subEventHash';
    public const KEY_PROVIDER = 'cs.provider';
    public const KEY_SCHEMA_VERSION = 'cs.schemaVersion';
    public const KEY_FORMAT_VERSION = 'cs.formatVersion';

    private function __construct()
    {
    }

    /** @return array<string,string> */
    public static function privateMetadata(
        string $manifestEventId,
        string $subEventHash,
        string $provider = 'outlook',
        ?string $formatVersion = null
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

        return $out;
    }

    public static function graphPropertyId(string $key): string
    {
        return 'String {' . self::EXTENDED_PROPERTY_SET_GUID . '} Name ' . $key;
    }

    /**
     * @return array<int,string>
     */
    public static function graphPropertyIds(): array
    {
        return [
            self::graphPropertyId(self::KEY_MANIFEST_EVENT_ID),
            self::graphPropertyId(self::KEY_SUB_EVENT_HASH),
            self::graphPropertyId(self::KEY_PROVIDER),
            self::graphPropertyId(self::KEY_SCHEMA_VERSION),
            self::graphPropertyId(self::KEY_FORMAT_VERSION),
        ];
    }

    /**
     * @param array<string,string> $privateMetadata
     * @return array<int,array{id:string,value:string}>
     */
    public static function toSingleValueExtendedProperties(array $privateMetadata): array
    {
        $out = [];
        foreach ($privateMetadata as $key => $value) {
            if (!is_string($key) || !str_starts_with($key, 'cs.')) {
                continue;
            }
            if (!is_string($value)) {
                continue;
            }
            $out[] = [
                'id' => self::graphPropertyId($key),
                'value' => $value,
            ];
        }

        return $out;
    }

    public static function graphExpandQuery(): string
    {
        $predicates = [];
        foreach (self::graphPropertyIds() as $id) {
            $escaped = str_replace("'", "''", $id);
            $predicates[] = "id eq '{$escaped}'";
        }

        return 'singleValueExtendedProperties($filter=' . implode(' or ', $predicates) . ')';
    }

    /**
     * @param array<string,mixed> $private
     * @return array<string,mixed>
     */
    public static function decodePrivateMetadata(array $private): array
    {
        return [
            'manifestEventId' => self::readString($private, self::KEY_MANIFEST_EVENT_ID),
            'subEventHash' => self::readString($private, self::KEY_SUB_EVENT_HASH),
            'provider' => self::readString($private, self::KEY_PROVIDER),
            'schemaVersion' => self::readString($private, self::KEY_SCHEMA_VERSION),
            'formatVersion' => self::readString($private, self::KEY_FORMAT_VERSION),
            'settings' => [],
        ];
    }

    /**
     * @param array<string,mixed> $ev
     * @return array<string,mixed>
     */
    public static function decodeFromOutlookEvent(array $ev): array
    {
        $private = self::extractPrivateMapFromExtendedProperties($ev);
        if ($private === []) {
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
     * @param array<string,mixed> $ev
     * @return array<string,string>
     */
    private static function extractPrivateMapFromExtendedProperties(array $ev): array
    {
        $out = [];
        $properties = $ev['singleValueExtendedProperties'] ?? null;
        if (!is_array($properties)) {
            return $out;
        }

        foreach ($properties as $property) {
            if (!is_array($property)) {
                continue;
            }

            $id = is_string($property['id'] ?? null) ? trim($property['id']) : '';
            $value = is_string($property['value'] ?? null) ? $property['value'] : null;
            if ($id === '' || $value === null) {
                continue;
            }

            $parts = explode(' ', $id);
            $name = end($parts);
            if (is_string($name) && str_starts_with($name, 'cs.')) {
                $out[$name] = $value;
            }
        }

        return $out;
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
}
