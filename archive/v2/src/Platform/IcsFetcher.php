<?php
declare(strict_types=1);

namespace CalendarScheduler\Platform;

/**
 * IcsFetcher
 *
 * Low-level HTTP fetcher for calendar ICS data.
 *
 * HARD RULES:
 * - Never throws
 * - Never parses ICS content
 * - Empty string on any failure
 *
 * NOTE:
 * SSL verification is intentionally disabled for common constrained FPP environments.
 */
final class IcsFetcher
{
    public function fetch(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);

        $data = @file_get_contents($url, false, $context);
        if ($data === false) {
            return '';
        }

        return (string)$data;
    }
}