<?php
declare(strict_types=1);

/**
 * IcsWriter
 *
 * Pure ICS generator.
 *
 * PURPOSE:
 * - Convert export intents into a valid RFC5545-compatible ICS document
 * - Intended for re-import into Google Calendar or similar systems
 *
 * RESPONSIBILITIES:
 * - Serialize VEVENT blocks from pre-sanitized export intents
 * - Emit minimal but valid calendar metadata
 * - Encode YAML metadata into DESCRIPTION field
 *
 * HARD RULES:
 * - No scheduler knowledge
 * - No filtering or validation
 * - No mutation of inputs
 * - Assumes all DateTime values are valid
 *
 * EXPORT RULE (Phase 30):
 * - Unmanaged export must be timezone-agnostic, so we emit UTC-only.
 * - DTSTART/DTEND are written as UTC "Z" values.
 * - No TZID parameters and no VTIMEZONE block.
 */
final class IcsWriter
{
    /**
     * Generate a complete ICS calendar document.
     *
     * @param array<int,array<string,mixed>> $events Export intents
     * @return string RFC5545-compatible ICS content
     */
    public static function build(array $events): string
    {
        $lines = [];

        // --------------------------------------------------
        // Calendar header
        // --------------------------------------------------
        $lines[] = 'BEGIN:VCALENDAR';
        $lines[] = 'PRODID:-//GoogleCalendarScheduler//Scheduler Export//EN';
        $lines[] = 'VERSION:2.0';
        $lines[] = 'CALSCALE:GREGORIAN';
        $lines[] = 'METHOD:PUBLISH';

        // Helpful for importers; harmless even if ignored.
        $lines[] = 'X-WR-TIMEZONE:UTC';

        // --------------------------------------------------
        // Events
        // --------------------------------------------------
        foreach ($events as $ev) {
            if (!is_array($ev)) {
                continue;
            }
            $lines = array_merge($lines, self::buildEventBlock($ev));
        }

        $lines[] = 'END:VCALENDAR';

        // RFC5545 prefers CRLF line endings
        return implode("\r\n", $lines) . "\r\n";
    }

    /* ==========================================================
     * VEVENT generation
     * ========================================================== */

    /**
     * Build a single VEVENT block.
     *
     * @param array<string,mixed> $ev Export intent
     * @return array<int,string> RFC5545 VEVENT lines
     */
    private static function buildEventBlock(array $ev): array
    {
        /** @var DateTime $dtStart */
        $dtStart = $ev['dtstart'];
        /** @var DateTime $dtEnd */
        $dtEnd   = $ev['dtend'];

        $summary = (string)($ev['summary'] ?? '');
        $rrule   = $ev['rrule'] ?? null;
        $yaml    = (array)($ev['yaml'] ?? []);
        $uid     = (string)($ev['uid'] ?? '');

        $lines = [];
        $lines[] = 'BEGIN:VEVENT';

        // DTSTART / DTEND in UTC ("Z") for timezone-agnostic import.
        $lines[] = 'DTSTART:' . self::formatUtc($dtStart);
        $lines[] = 'DTEND:'   . self::formatUtc($dtEnd);

        // RRULE (optional)
        if (is_string($rrule) && $rrule !== '') {
            $lines[] = 'RRULE:' . $rrule;
        }

        // SUMMARY
        if ($summary !== '') {
            $lines[] = 'SUMMARY:' . self::escapeText($summary);
        }

        // DESCRIPTION (embedded YAML metadata)
        if (!empty($yaml)) {
            $lines[] = 'DESCRIPTION:' . self::escapeText(self::yamlToText($yaml));
        }

        // Required metadata
        $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
        $lines[] = 'UID:' . ($uid !== '' ? $uid : self::generateUid());

        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /**
     * Format a DateTime as UTC date-time (RFC5545 basic format with "Z").
     */
    private static function formatUtc(DateTime $dt): string
    {
        $utc = clone $dt;
        $utc->setTimezone(new DateTimeZone('UTC'));
        return $utc->format('Ymd\THis\Z');
    }

    /**
     * Convert YAML metadata array into plain-text block.
     *
     * @param array<string,mixed> $yaml
     * @return string
     */
    private static function yamlToText(array $yaml): string
    {
        $out = [];
        foreach ($yaml as $k => $v) {
            if (is_bool($v)) {
                $v = $v ? 'true' : 'false';
            }
            $out[] = $k . ': ' . $v;
        }
        return implode("\n", $out);
    }

    /**
     * Escape text for inclusion in ICS fields (RFC5545).
     */
    private static function escapeText(string $text): string
    {
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace("\n", '\\n', $text);
        $text = str_replace(',', '\,', $text);
        $text = str_replace(';', '\;', $text);
        return $text;
    }

    /**
     * Generate a unique UID for exported events.
     *
     * NOTE:
     * - UID stability is not required for export use-case
     * - Google Calendar will normalize or replace as needed
     */
    private static function generateUid(): string
    {
        return uniqid('gcs-export-', true) . '@local';
    }
}
