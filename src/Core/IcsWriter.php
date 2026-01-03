<?php
declare(strict_types=1);

/**
 * IcsWriter
 *
 * Pure ICS generator.
 *
 * FINAL EXPORT MODEL (Phase 30 – v1.0):
 * - Export uses the FPP system timezone (date_default_timezone_get()).
 * - DTSTART / DTEND are LOCAL wall-clock times with TZID.
 * - A full VTIMEZONE block is emitted so Google handles DST correctly.
 *
 * This preserves FPP scheduler semantics exactly and renders correctly
 * in Google Calendar across DST boundaries.
 *
 * HARD RULES:
 * - No scheduler knowledge
 * - No filtering or validation
 * - No mutation of inputs
 * - Assumes DateTime values represent LOCAL times
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
        $tzName = date_default_timezone_get();
        $tz     = new DateTimeZone($tzName);

        $lines = [];

        // --------------------------------------------------
        // Calendar header
        // --------------------------------------------------
        $lines[] = 'BEGIN:VCALENDAR';
        $lines[] = 'PRODID:-//GoogleCalendarScheduler//Scheduler Export//EN';
        $lines[] = 'VERSION:2.0';
        $lines[] = 'CALSCALE:GREGORIAN';
        $lines[] = 'METHOD:PUBLISH';
        $lines[] = 'X-WR-TIMEZONE:' . $tzName;

        // Timezone definition (required for correct DST handling)
        $lines = array_merge($lines, self::buildVtimezone($tz));

        // --------------------------------------------------
        // Events
        // --------------------------------------------------
        foreach ($events as $ev) {
            if (!is_array($ev)) {
                continue;
            }
            $lines = array_merge($lines, self::buildEventBlock($ev, $tzName));
        }

        $lines[] = 'END:VCALENDAR';

        // RFC5545 requires CRLF
        return implode("\r\n", $lines) . "\r\n";
    }

    /* ==========================================================
     * VEVENT generation
     * ========================================================== */

    /**
     * Build a single VEVENT block.
     *
     * @param array<string,mixed> $ev Export intent
     * @param string $tzName Timezone identifier
     * @return array<int,string>
     */
    private static function buildEventBlock(array $ev, string $tzName): array
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

        // Local wall-clock times with TZID
        $lines[] = 'DTSTART;TZID=' . $tzName . ':' . $dtStart->format('Ymd\THis');
        $lines[] = 'DTEND;TZID='   . $tzName . ':' . $dtEnd->format('Ymd\THis');

        // RRULE (already RFC5545-correct from adapter)
        if (is_string($rrule) && $rrule !== '') {
            $lines[] = 'RRULE:' . $rrule;
        }

        if ($summary !== '') {
            $lines[] = 'SUMMARY:' . self::escapeText($summary);
        }

        if (!empty($yaml)) {
            $lines[] = 'DESCRIPTION:' . self::escapeText(self::yamlToText($yaml));
        }

        // Required metadata
        $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
        $lines[] = 'UID:' . ($uid !== '' ? $uid : self::generateUid());

        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /* ==========================================================
     * VTIMEZONE
     * ========================================================== */

    /**
     * Build a full VTIMEZONE block for the given timezone.
     *
     * This mirrors the structure Google Calendar expects and
     * ensures DST transitions are applied correctly.
     */
    private static function buildVtimezone(DateTimeZone $tz): array
    {
        $tzName = $tz->getName();
        $lines = [
            'BEGIN:VTIMEZONE',
            'TZID:' . $tzName,
        ];

        // Use transitions to emit STANDARD / DAYLIGHT blocks
        $transitions = $tz->getTransitions();

        foreach ($transitions as $t) {
            if (!isset($t['ts'], $t['isdst'], $t['offset'])) {
                continue;
            }

            $type = $t['isdst'] ? 'DAYLIGHT' : 'STANDARD';
            $dt   = (new DateTime('@' . $t['ts']))->setTimezone($tz);

            $lines[] = 'BEGIN:' . $type;
            $lines[] = 'DTSTART:' . $dt->format('Ymd\THis');
            $lines[] = 'TZOFFSETFROM:' . self::formatOffset($t['offset'] - ($t['isdst'] ? 3600 : 0));
            $lines[] = 'TZOFFSETTO:'   . self::formatOffset($t['offset']);
            $lines[] = 'END:' . $type;
        }

        $lines[] = 'END:VTIMEZONE';

        return $lines;
    }

    private static function formatOffset(int $seconds): string
    {
        $sign = $seconds >= 0 ? '+' : '-';
        $seconds = abs($seconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        return sprintf('%s%02d%02d', $sign, $hours, $minutes);
    }

    /* ==========================================================
     * Helpers
     * ========================================================== */

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

    private static function escapeText(string $text): string
    {
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace("\n", '\\n', $text);
        $text = str_replace(',', '\,', $text);
        $text = str_replace(';', '\;', $text);
        return $text;
    }

    private static function generateUid(): string
    {
        return uniqid('gcs-export-', true) . '@local';
    }
}
