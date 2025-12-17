<?php

class IcsParser
{
    /**
     * Parse raw ICS into structured event records.
     *
     * @param string        $ics
     * @param DateTime|null $now
     * @param DateTime      $horizonEnd
     * @return array<int,array<string,mixed>>
     */
    public function parse(string $ics, ?DateTime $now, DateTime $horizonEnd): array
    {
        $events = [];

        preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics, $matches);

        foreach ($matches[1] as $raw) {
            $uid          = null;
            $summary      = '';
            $dtstart      = null;
            $dtend        = null;
            $isAllDay     = false;
            $rrule        = null;
            $exDates      = [];
            $recurrenceId = null;

            if (preg_match('/UID:(.+)/', $raw, $m)) {
                $uid = trim($m[1]);
            }

            if (preg_match('/SUMMARY:(.+)/', $raw, $m)) {
                $summary = trim($m[1]);
            }

            if (preg_match('/DTSTART([^:]*):(.+)/', $raw, $m)) {
                [$dtstart, $isAllDay] = $this->parseDateWithFlags($m[2], $m[1]);
            }

            if (preg_match('/DTEND([^:]*):(.+)/', $raw, $m)) {
                [$dtend, $isAllDayEnd] = $this->parseDateWithFlags($m[2], $m[1]);
                $isAllDay = $isAllDay || $isAllDayEnd;
            }

            if (preg_match('/RRULE:(.+)/', $raw, $m)) {
                $rrule = $this->parseRrule($m[1]);
            }

            if (preg_match_all('/EXDATE([^:]*):([^\r\n]+)/', $raw, $m, PREG_SET_ORDER)) {
                foreach ($m as $ex) {
                    $exDates = array_merge(
                        $exDates,
                        $this->parseExDates($ex[2], $ex[1])
                    );
                }
            }

            if (preg_match('/RECURRENCE-ID([^:]*):(.+)/', $raw, $m)) {
                $recurrenceId = $this->parseDate($m[2], $m[1]);
            }

            // Skip malformed events
            if (!$uid || !$dtstart || !$dtend) {
                continue;
            }

            // Horizon filtering applies to base DTSTART/DTEND
            if ($now && $dtend < $now) {
                continue;
            }

            if ($dtstart > $horizonEnd) {
                continue;
            }

            $events[] = [
                'uid'          => $uid,
                'summary'      => $summary,
                'start'        => $dtstart->format('Y-m-d H:i:s'),
                'end'          => $dtend->format('Y-m-d H:i:s'),
                'isAllDay'     => $isAllDay,
                'rrule'        => $rrule,
                'exDates'      => array_map(
                    fn(DateTime $d) => $d->format('Y-m-d H:i:s'),
                    $exDates
                ),
                'recurrenceId' => $recurrenceId
                    ? $recurrenceId->format('Y-m-d H:i:s')
                    : null,
                'isOverride'   => ($recurrenceId !== null),
            ];
        }

        return $events;
    }

    /* -----------------------------------------------------------------
     * Helpers
     * ----------------------------------------------------------------- */

    private function parseDateWithFlags(string $value, string $params): array
    {
        $isAllDay = str_contains($params, 'VALUE=DATE');
        $dt = $this->parseDate($value, $params);

        if ($dt && $isAllDay) {
            $dt = $dt->setTime(0, 0, 0);
        }

        return [$dt, $isAllDay];
    }

    private function parseDate(string $raw, string $params = ''): ?DateTime
    {
        try {
            // UTC timestamp
            if (preg_match('/^\d{8}T\d{6}Z$/', $raw)) {
                return DateTime::createFromFormat(
                    'Ymd\THis\Z',
                    $raw,
                    new DateTimeZone('UTC')
                );
            }

            // Floating local time
            if (preg_match('/^\d{8}T\d{6}$/', $raw)) {
                return DateTime::createFromFormat('Ymd\THis', $raw);
            }

            // All-day date
            if (preg_match('/^\d{8}$/', $raw)) {
                return DateTime::createFromFormat('Ymd', $raw);
            }

            return new DateTime($raw);
        } catch (Throwable $e) {
            return null;
        }
    }

    private function parseRrule(string $raw): array
    {
        $out = [];
        foreach (explode(';', trim($raw)) as $part) {
            if (str_contains($part, '=')) {
                [$k, $v] = explode('=', $part, 2);
                $out[strtoupper($k)] = $v;
            }
        }
        return $out;
    }

    private function parseExDates(string $raw, string $params): array
    {
        $dates = [];
        foreach (explode(',', trim($raw)) as $val) {
            $dt = $this->parseDate($val, $params);
            if ($dt) {
                $dates[] = $dt;
            }
        }
        return $dates;
    }
}

/**
 * Compatibility alias
 */
class GcsIcsParser extends IcsParser {}

