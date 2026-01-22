<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Platform;

use DateTime;
use Throwable;

/**
 * CalendarTranslator
 *
 * Translates an ICS snapshot into canonical Manifest event objects.
 *
 * HARD RULES:
 * - Snapshot only (no identity assignment)
 * - 1 VEVENT -> 1 Manifest Event (mirrors FPP adoption semantics)
 * - Calendar SUMMARY -> manifest target
 * - Command options/args are opaque and preserved when present in YAML metadata
 *
 * NOTE:
 * This is intentionally minimal. Full recurrence grouping into subEvents can come later.
 */
final class CalendarTranslator
{
    private IcsFetcher $fetcher;
    private IcsParser $parser;

    public function __construct(?IcsFetcher $fetcher = null, ?IcsParser $parser = null)
    {
        $this->fetcher = $fetcher ?? new IcsFetcher();
        $this->parser  = $parser  ?? new IcsParser();
    }

    /**
     * @return array<int,array<string,mixed>> Manifest event objects
     */
    public function translateIcsSourceToManifestEvents(string $icsSource): array
    {
        $ics = $this->loadIcs($icsSource);
        if ($ics === '') {
            return [];
        }

        $records = $this->parser->parse($ics);
        $out     = [];

        $nowIso = (new DateTime('now'))->format(DATE_ATOM);

        foreach ($records as $r) {
            $uid         = (string)($r['uid'] ?? '');
            $target      = (string)($r['summary'] ?? '');
            $description = $r['description'] ?? null;

            $yaml = $this->extractYamlMetadata(is_string($description) ? $description : '');

            // Determine type + behavior/payload from YAML when present.
            [$type, $behavior, $payload] = $this->deriveTypeBehaviorPayload($yaml);

            // Timing: map DTSTART/DTEND to hard date/time fields (no symbolic)
            $start = (string)($r['start'] ?? '');
            $end   = (string)($r['end'] ?? '');
            $timing = $this->buildTimingFromDateTimes($start, $end);

            $event = [
                'id'   => null,
                'type' => $type,
                'target' => $target,

                'ownership' => [
                    'managed'    => true,
                    'controller' => 'calendar',
                    'locked'     => false,
                ],

                'correlation' => [
                    'source'     => 'ics',
                    'externalId' => ($uid !== '' ? $uid : null),
                ],

                'provenance' => [
                    'source'      => 'calendar',
                    'provider'    => 'ics',
                    'imported_at' => $nowIso,
                ],

                'subEvents' => [
                    array_filter([
                        'timing'   => $timing,
                        'behavior' => $behavior,
                        // payload should be absent unless type=command (per your rule)
                        'payload'  => ($type === 'command') ? $payload : null,
                    ], static fn($v) => $v !== null),
                ],
            ];

            $out[] = $event;
        }

        return $out;
    }

    private function loadIcs(string $source): string
    {
        $source = trim($source);
        if ($source === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $source)) {
            return $this->fetcher->fetch($source);
        }

        // Local file path
        $data = @file_get_contents($source);
        if ($data === false) {
            return '';
        }

        return (string)$data;
    }

    /**
     * Extract fenced YAML from DESCRIPTION:
     * ```yaml
     * key: value
     * nested:
     *   k: v
     * ```
     *
     * @return array<string,mixed>
     */
    private function extractYamlMetadata(string $description): array
    {
        if ($description === '') {
            return [];
        }

        if (!preg_match('/```yaml\s*(.*?)\s*```/s', $description, $m)) {
            return [];
        }

        $yamlText = trim($m[1]);
        if ($yamlText === '') {
            return [];
        }

        return $this->parseMinimalYaml($yamlText);
    }

    /**
     * Minimal YAML subset parser (maps only, 2-space indents).
     * This is intentionally conservative to avoid bringing in dependencies.
     *
     * @return array<string,mixed>
     */
    private function parseMinimalYaml(string $yaml): array
    {
        $lines = preg_split('/\r?\n/', $yaml) ?: [];
        $root  = [];
        $stack = [ [&$root, -1] ]; // [ref, indent]

        foreach ($lines as $line) {
            $line = rtrim($line);
            if ($line === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }

            $indent = strlen($line) - strlen(ltrim($line, ' '));
            $trim   = ltrim($line, ' ');

            if (!str_contains($trim, ':')) {
                continue;
            }

            [$k, $v] = explode(':', $trim, 2);
            $key = trim($k);
            $val = trim($v);

            while (!empty($stack) && $indent <= $stack[count($stack) - 1][1]) {
                array_pop($stack);
            }

            $parentRef = &$stack[count($stack) - 1][0];

            if ($val === '') {
                // start nested map
                $parentRef[$key] = [];
                $ref = &$parentRef[$key];
                $stack[] = [&$ref, $indent];
                continue;
            }

            $parentRef[$key] = $this->coerceYamlScalar($val);
        }

        return $root;
    }

    private function coerceYamlScalar(string $v)
    {
        $v = trim($v);

        if ($v === 'true')  return true;
        if ($v === 'false') return false;

        if (preg_match('/^-?\d+$/', $v)) {
            return (int)$v;
        }

        if (preg_match('/^-?\d+\.\d+$/', $v)) {
            return (float)$v;
        }

        return $v;
    }

    /**
     * @return array{0:string,1:array<string,int>,2:array<string,mixed>}
     */
    private function deriveTypeBehaviorPayload(array $yaml): array
    {
        // Defaults
        $type = 'playlist';

        // Behavior
        $behavior = [
            'enabled'  => 1,
            'repeat'   => 0,
            'stopType' => 0,
        ];

        // Extract known behavior keys if present
        if (isset($yaml['enabled']))  { $behavior['enabled']  = (int)$yaml['enabled']; }
        if (isset($yaml['repeat']))   { $behavior['repeat']   = (int)$yaml['repeat']; }
        if (isset($yaml['stopType'])) { $behavior['stopType'] = (int)$yaml['stopType']; }

        // Determine type
        if (isset($yaml['type']) && is_string($yaml['type'])) {
            $t = strtolower(trim($yaml['type']));
            if (in_array($t, ['playlist', 'sequence', 'command'], true)) {
                $type = $t;
            }
        } elseif (isset($yaml['command'])) {
            $type = 'command';
        }

        // Payload is opaque, but ONLY if type=command.
        $payload = [];
        if ($type === 'command') {
            foreach ($yaml as $k => $v) {
                // Keep command/options/args opaque
                $payload[(string)$k] = $v;
            }
        }

        return [$type, $behavior, $payload];
    }

    /**
     * Build manifest timing from normalized "Y-m-d H:i:s" strings.
     *
     * Calendar snapshot is hard-only and uses no symbolic date/time.
     *
     * @return array<string,mixed>
     */
    private function buildTimingFromDateTimes(string $start, string $end): array
    {
        $sDate = null; $sTime = null;
        $eDate = null; $eTime = null;

        try {
            if ($start !== '') {
                $dt = new DateTime($start);
                $sDate = $dt->format('Y-m-d');
                $sTime = $dt->format('H:i:s');
            }
            if ($end !== '') {
                $dt = new DateTime($end);
                $eDate = $dt->format('Y-m-d');
                $eTime = $dt->format('H:i:s');
            }
        } catch (Throwable) {
            // leave nulls
        }

        return [
            'start_date' => [
                'symbolic' => null,
                'hard'     => $sDate,
            ],
            'end_date' => [
                'symbolic' => null,
                'hard'     => $eDate,
            ],
            'start_time' => [
                'symbolic' => null,
                'hard'     => $sTime,
                'offset'   => 0,
            ],
            'end_time' => [
                'symbolic' => null,
                'hard'     => $eTime,
                'offset'   => 0,
            ],
            // For snapshot (no recurrence expansion), default to "all days" mask used in your examples.
            'days' => 7,
        ];
    }
}