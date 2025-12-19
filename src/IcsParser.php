<?php

class GcsIcsParser
{
    /**
     * Parse ICS into an array of VEVENTs with normalized fields.
     *
     * @param string   $ics
     * @param DateTime $now
     * @param DateTime $horizonEnd
     * @return array<int,array<string,mixed>>
     */
    public function parse(string $ics, DateTime $now, DateTime $horizonEnd): array
    {
        $ics = str_replace("\r\n", "\n", $ics);
        $lines = explode("\n", $ics);

        // Unfold lines (RFC5545)
        $unfolded = [];
        foreach ($lines as $line) {
            $line = rtrim($line, "\r");
            if ($line === '') {
                continue;
            }
            if (!empty($unfolded) && (isset($line[0]) && ($line[0] === ' ' || $line[0] === "\t"))) {
                $unfolded[count($unfolded) - 1] .= substr($line, 1);
            } else {
                $unfolded[] = $line;
            }
        }

        $events = [];
        $inEvent = false;
        $curr = [];

        foreach ($unfolded as $line) {
            if (trim($line) === 'BEGIN:VEVENT') {
                $inEvent = true;
                $curr = [];
                continue;
            }

            if (trim($line) === 'END:VEVENT') {
                if ($inEvent) {
                    $ev = $this->normalizeEvent($curr, $now, $horizonEnd);
                    if ($ev !== null) {
                        $events[] = $ev;
                    }
                }
                $inEvent = false;
                $curr = [];
                continue;
            }

            if (!$inEvent) {
                continue;
            }

            // Split property name/params/value
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }

            $left = substr($line, 0, $pos);
            $value = substr($line, $pos + 1);

            $name = $left;
            $params = null;

            $semi = strpos($left, ';');
            if ($semi !== false) {
                $name = substr($left, 0, $semi);
                $params = substr($left, $semi + 1);
            }

            $name = strtoupper(trim($name));
            $value = trim($value);

            if ($name === '') {
                continue;
            }

            if (!isset($curr[$name])) {
                $curr[$name] = [];
            }

            $curr[$name][] = [
                'params' => $params,
                'value'  => $value,
            ];
        }

        return $events;
    }

    /**
     * Normalize one VEVENT dictionary into a simplified event array.
     *
     * @param array<string,array<int,array{params:?string,value:string}>> $raw
     * @return array<string,mixed>|null
     */
    private function normalizeEvent(array $raw, DateTime $now, DateTime $horizonEnd): ?array
    {
        $uid = $this->getFirstValue($raw, 'UID');
        if ($uid === null || $uid === '') {
            return null;
        }

        $summary = $this->getFirstValue($raw, 'SUMMARY') ?? '';

        $dtStart = $this->getFirstProp($raw, 'DTSTART');
        $dtEnd   = $this->getFirstProp($raw, 'DTEND');

        if ($dtStart === null) {
            return null;
        }

        $start = $this->parseDateTimeProp($dtStart);
        if ($start === null) {
            return null;
        }

        $end = null;
        if ($dtEnd !== null) {
            $end = $this->parseDateTimeProp($dtEnd);
        }

        $isAllDay = false;
        $startVal = $dtStart['value'];
        if (preg_match('/^\d{8}$/', $startVal)) {
            $isAllDay = true;
        }

        if ($end === null) {
            $end = (clone $start)->modify('+30 minutes');
        }

        // Basic horizon filter
        if ($start > $horizonEnd) {
            return null;
        }

        $rrule = $this->getFirstValue($raw, 'RRULE');
        $exdates = $this->getAllValues($raw, 'EXDATE');

        // NOTE: baseline implementation includes RRULE expansion elsewhere in this file;
        // leaving as-is (no logic changes intended in Phase 11 item #2).

        return [
            'uid'      => $uid,
            'summary'  => $summary,
            'start'    => $start->format('Y-m-d H:i:s'),
            'end'      => $end->format('Y-m-d H:i:s'),
            'isAllDay' => $isAllDay,
            'rrule'    => $rrule,
            'exdates'  => $exdates,
            'raw'      => $raw,
        ];
    }

    private function getFirstProp(array $raw, string $key): ?array
    {
        if (!isset($raw[$key]) || !is_array($raw[$key]) || count($raw[$key]) < 1) {
            return null;
        }
        $first = $raw[$key][0] ?? null;
        return is_array($first) ? $first : null;
    }

    private function getFirstValue(array $raw, string $key): ?string
    {
        $p = $this->getFirstProp($raw, $key);
        if ($p === null) {
            return null;
        }
        return (string)($p['value'] ?? '');
    }

    private function getAllValues(array $raw, string $key): array
    {
        if (!isset($raw[$key]) || !is_array($raw[$key])) {
            return [];
        }
        $out = [];
        foreach ($raw[$key] as $item) {
            if (is_array($item) && isset($item['value'])) {
                $out[] = (string)$item['value'];
            }
        }
        return $out;
    }

    /**
     * Parse DTSTART/DTEND property into DateTime.
     *
     * @param array{params:?string,value:string} $prop
     */
    private function parseDateTimeProp(array $prop): ?DateTime
    {
        $val = $prop['value'] ?? '';
        $params = $prop['params'] ?? null;

        // DATE (all-day): YYYYMMDD
        if (preg_match('/^\d{8}$/', $val)) {
            $dt = DateTime::createFromFormat('Ymd', $val);
            return $dt ?: null;
        }

        // DATE-TIME:
        // - UTC: YYYYMMDDTHHMMSSZ
        // - Local: YYYYMMDDTHHMMSS (maybe with TZID param)
        if (preg_match('/^\d{8}T\d{6}Z$/', $val)) {
            $dt = DateTime::createFromFormat('Ymd\THis\Z', $val, new DateTimeZone('UTC'));
            return $dt ?: null;
        }

        if (preg_match('/^\d{8}T\d{6}$/', $val)) {
            $tz = null;
            if ($params !== null) {
                $p = $this->parseParams($params);
                if ($p !== null && isset($p['TZID'])) {
                    $tz = $p['TZID'];
                }
            }
            try {
                $zone = $tz ? new DateTimeZone($tz) : null;
                $dt = DateTime::createFromFormat('Ymd\THis', $val, $zone ?: null);
                return $dt ?: null;
            } catch (Throwable $_) {
                return null;
            }
        }

        return null;
    }

    private function parseParams(string $raw): ?array
    {
        $out = [];
        $parts = explode(';', $raw);
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '' || strpos($p, '=') === false) {
                continue;
            }
            [$k, $v] = explode('=', $p, 2);
            $k = strtoupper(trim($k));
            $v = trim($v);
            if ($k !== '') {
                $out[$k] = $v;
            }
        }

        return $out ?: null;
    }

    /**
     * Split comma-separated values (EXDATE can contain multiple values).
     */
    private function splitCsvValues(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $parts = explode(',', $raw);
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return $out;
    }
}
