<?php
declare(strict_types=1);

/**
 * IcsWriter
 *
 * Pure ICS generator.
 *
 * - Uses the FPP system timezone (date_default_timezone_get()) with TZID + VTIMEZONE
 * - DTSTART/DTEND are local wall-clock times
 * - YAML metadata is embedded as fenced YAML in DESCRIPTION
 * - Output is deterministic and round-trip safe
 */
final class IcsWriter
{
    /**
     * @param array<int,array<string,mixed>> $events Export intents
     */
    public static function build(array $events): string
    {
        $tzName = date_default_timezone_get();
        $tz     = new DateTimeZone($tzName);

        $lines = [];
        $lines[] = 'BEGIN:VCALENDAR';
        $lines[] = 'PRODID:-//GoogleCalendarScheduler//Scheduler Export//EN';
        $lines[] = 'VERSION:2.0';
        $lines[] = 'CALSCALE:GREGORIAN';
        $lines[] = 'METHOD:PUBLISH';
        $lines[] = 'X-WR-TIMEZONE:' . $tzName;

        $lines = array_merge($lines, self::buildVtimezone($tz));

        foreach ($events as $ev) {
            if (!is_array($ev)) {
                continue;
            }
            $lines = array_merge($lines, self::buildEventBlock($ev, $tzName));
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * @param array<string,mixed> $ev
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
        $yaml    = is_array($ev['yaml'] ?? null) ? $ev['yaml'] : [];
        $uid     = (string)($ev['uid'] ?? '');

        $exdates = [];
        if (isset($ev['exdates']) && is_array($ev['exdates'])) {
            foreach ($ev['exdates'] as $d) {
                if ($d instanceof DateTime) {
                    $exdates[] = $d;
                }
            }
        }

        $lines = [];
        $lines[] = 'BEGIN:VEVENT';

        $lines[] = 'DTSTART;TZID=' . $tzName . ':' . $dtStart->format('Ymd\THis');
        $lines[] = 'DTEND;TZID='   . $tzName . ':' . $dtEnd->format('Ymd\THis');

        if (is_string($rrule) && $rrule !== '') {
            $lines[] = 'RRULE:' . $rrule;
        }

        foreach ($exdates as $ex) {
            $lines[] = 'EXDATE;TZID=' . $tzName . ':' . $ex->format('Ymd\THis');
        }

        if ($summary !== '') {
            $lines[] = 'SUMMARY:' . self::escapeText($summary);
        }

        if (!empty($yaml)) {
            $yamlText = self::emitYamlBlock($yaml);
            $lines[]  = 'DESCRIPTION:' . self::escapeText($yamlText);
        }

        $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
        $lines[] = 'UID:' . ($uid !== '' ? $uid : self::generateUid());

        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /**
     * Emit deterministic, fenced YAML for DESCRIPTION.
     *
     * @param array<string,mixed> $yaml
     */
    private static function emitYamlBlock(array $yaml): string
    {
        $lines = [];
        $lines[] = '```yaml';

        // Stable, human-friendly ordering
        $preferredOrder = ['stopType', 'repeat', 'start', 'end'];
        foreach ($preferredOrder as $k) {
            if (array_key_exists($k, $yaml)) {
                self::emitYamlValue($lines, $k, $yaml[$k], 0);
                unset($yaml[$k]);
            }
        }

        // Emit any remaining keys
        foreach ($yaml as $k => $v) {
            self::emitYamlValue($lines, (string)$k, $v, 0);
        }

        $lines[] = '```';

        return implode("\n", $lines);
    }

    /**
     * Recursive YAML emitter (maps only, max depth = practical 1–2).
     *
     * @param array<int,string> $lines
     * @param string $key
     * @param mixed $value
     * @param int $indent
     */
    private static function emitYamlValue(array &$lines, string $key, $value, int $indent): void
    {
        $pad = str_repeat('  ', $indent);

        if (is_array($value)) {
            $lines[] = $pad . $key . ':';
            foreach ($value as $k => $v) {
                self::emitYamlValue($lines, (string)$k, $v, $indent + 1);
            }
            return;
        }

        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } elseif (is_int($value) || is_float($value)) {
            $value = (string)$value;
        } elseif (is_string($value)) {
            $value = trim($value);
        } else {
            // Unsupported types are ignored safely
            return;
        }

        $lines[] = $pad . $key . ': ' . $value;
    }

    private static function buildVtimezone(DateTimeZone $tz): array
    {
        $tzName = $tz->getName();
        $lines = [
            'BEGIN:VTIMEZONE',
            'TZID:' . $tzName,
        ];

        $transitions = $tz->getTransitions();
        if (empty($transitions)) {
            $lines[] = 'END:VTIMEZONE';
            return $lines;
        }

        $now = time();
        $minTs = $now - (365 * 24 * 3600);
        $maxTs = $now + (6 * 365 * 24 * 3600);

        $prevOffset = null;

        foreach ($transitions as $t) {
            if (!isset($t['ts'], $t['offset'], $t['isdst'])) {
                continue;
            }

            $ts = (int)$t['ts'];
            if ($ts < $minTs || $ts > $maxTs) {
                $prevOffset = (int)$t['offset'];
                continue;
            }

            $currOffset = (int)$t['offset'];
            $fromOffset = ($prevOffset !== null) ? $prevOffset : $currOffset;

            $type = $t['isdst'] ? 'DAYLIGHT' : 'STANDARD';
            $dt = (new DateTime('@' . $ts))->setTimezone($tz);

            $lines[] = 'BEGIN:' . $type;
            $lines[] = 'DTSTART:' . $dt->format('Ymd\THis');
            $lines[] = 'TZOFFSETFROM:' . self::formatOffset($fromOffset);
            $lines[] = 'TZOFFSETTO:'   . self::formatOffset($currOffset);

            if (!empty($t['abbr'])) {
                $lines[] = 'TZNAME:' . self::escapeText((string)$t['abbr']);
            }

            $lines[] = 'END:' . $type;

            $prevOffset = $currOffset;
        }

        $lines[] = 'END:VTIMEZONE';
        return $lines;
    }

    private static function formatOffset(int $seconds): string
    {
        $sign = ($seconds >= 0) ? '+' : '-';
        $seconds = abs($seconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        return sprintf('%s%02d%02d', $sign, $hours, $minutes);
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