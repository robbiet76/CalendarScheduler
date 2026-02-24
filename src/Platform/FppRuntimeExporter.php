<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Platform/FppRuntimeExporter.php
 * Purpose: Export FPP runtime catalog/state from REST APIs into a JSON snapshot
 * consumed by validation and diagnostics layers.
 */

namespace CalendarScheduler\Platform;

function exportFppRuntime(string $outputPath, string $baseUrl = 'http://127.0.0.1'): void
{
    $epoch = time();
    $result = [
        'schemaVersion' => 1,
        'source' => 'fpp-runtime-export-php',
        'generatedAt' => gmdate('c'),
        'generatedAtEpoch' => $epoch,
        'ok' => false,
        'errors' => [],
        'settings' => [],
        'catalog' => [
            'playlists' => [],
            'sequences' => [],
            'sequencesNormalized' => [],
            'commands' => [],
            'commandNames' => [],
            'scripts' => [],
        ],
        'holidays' => [],
    ];

    try {
        $settings = [];
        foreach (['Locale', 'TimeZone', 'Latitude', 'Longitude', 'scheduleJsonFile', 'playlistDirectory', 'mediaDirectory'] as $name) {
            $resp = fppRuntimeFetchJson($baseUrl . '/api/settings/' . rawurlencode($name));
            $value = $resp['json']['value'] ?? null;
            if (is_string($value) || is_numeric($value) || is_bool($value)) {
                $settings[$name] = $value;
            }
        }
        $result['settings'] = $settings;

        $playlists = fppRuntimeFetchJson($baseUrl . '/api/playlists')['json'] ?? [];
        if (is_array($playlists)) {
            $result['catalog']['playlists'] = array_values(array_filter($playlists, static fn($v): bool => is_string($v) && trim($v) !== ''));
        }

        $sequences = fppRuntimeFetchJson($baseUrl . '/api/files/sequences?nameOnly=1')['json'] ?? [];
        if (is_array($sequences)) {
            $seq = array_values(array_filter($sequences, static fn($v): bool => is_string($v) && trim($v) !== ''));
            $result['catalog']['sequences'] = $seq;
            $result['catalog']['sequencesNormalized'] = array_values(array_unique(array_map(
                static fn(string $v): string => preg_replace('/\.fseq$/i', '', trim($v)) ?? trim($v),
                $seq
            )));
        }

        $commands = fppRuntimeFetchJson($baseUrl . '/api/commands')['json'] ?? [];
        if (is_array($commands)) {
            $result['catalog']['commands'] = $commands;
            $names = [];
            foreach ($commands as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = $row['name'] ?? null;
                if (is_string($name) && trim($name) !== '') {
                    $names[] = trim($name);
                }
            }
            $result['catalog']['commandNames'] = array_values(array_unique($names));
        }

        $scripts = fppRuntimeFetchJson($baseUrl . '/api/scripts')['json'] ?? [];
        if (is_array($scripts)) {
            $result['catalog']['scripts'] = array_values(array_filter($scripts, static fn($v): bool => is_string($v) && trim($v) !== ''));
        }

        $locale = is_string($settings['Locale'] ?? null) ? trim((string)$settings['Locale']) : '';
        if ($locale !== '') {
            $localePath = '/opt/fpp/etc/locale/' . $locale . '.json';
            if (is_file($localePath)) {
                $raw = @file_get_contents($localePath);
                $decoded = is_string($raw) && trim($raw) !== '' ? @json_decode($raw, true) : null;
                if (is_array($decoded)) {
                    $holidays = $decoded['holidays'] ?? null;
                    if (is_array($holidays)) {
                        $result['holidays'] = $holidays;
                    }
                }
            }
        }

        $result['ok'] = true;
    } catch (\Throwable $e) {
        $result['errors'][] = $e->getMessage();
    }

    $dir = dirname($outputPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    file_put_contents(
        $outputPath,
        json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
    );
}

/**
 * @return array{code:int,json:mixed}
 */
function fppRuntimeFetchJson(string $url): array
{
    $raw = @file_get_contents($url);
    if (!is_string($raw)) {
        throw new \RuntimeException('FPP API request failed: ' . $url);
    }

    $code = 0;
    $responseHeaders = function_exists('http_get_last_response_headers')
        ? http_get_last_response_headers()
        : null;
    if (is_array($responseHeaders) && isset($responseHeaders[0])) {
        if (preg_match('/\s(\d{3})\s/', (string)$responseHeaders[0], $m) === 1) {
            $code = (int)$m[1];
        }
    }

    if ($code < 200 || $code >= 300) {
        throw new \RuntimeException('FPP API request returned HTTP ' . $code . ': ' . $url);
    }

    $decoded = json_decode($raw, true);
    if ($decoded === null && trim($raw) !== 'null') {
        throw new \RuntimeException('FPP API response is not valid JSON: ' . $url);
    }

    return ['code' => $code, 'json' => $decoded];
}
