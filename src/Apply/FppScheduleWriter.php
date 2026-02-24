<?php

declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Apply/FppScheduleWriter.php
 * Purpose: Defines the FppScheduleWriter component used by the Calendar Scheduler Apply layer.
 */

namespace CalendarScheduler\Apply;

/**
 * FppScheduleWriter
 *
 * Reads/writes schedule.json via FPP REST API.
 *
 * Runtime behavior:
 * - Read via GET /api/schedule
 * - Write via POST /api/schedule
 * - Keep staged and backup artifacts in plugin staging directory
 */
final class FppScheduleWriter
{
    private const FPP_SCHEDULE_API_URL = 'http://127.0.0.1/api/schedule';

    private string $stagingDirectory;

    public function __construct(string $schedulePath, string $stagingDirectory)
    {
        $schedulePath = trim($schedulePath);
        $stagingDirectory = rtrim(trim($stagingDirectory), '/');

        if ($schedulePath === '') {
            throw new \InvalidArgumentException('schedulePath must not be empty');
        }

        if ($stagingDirectory === '') {
            throw new \InvalidArgumentException('stagingDirectory must not be empty');
        }

        $this->stagingDirectory = $stagingDirectory;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function load(): array
    {
        return $this->loadViaApi();
    }

    /**
     * Always writes the staged schedule file.
     * Does NOT touch live schedule.json.
     *
     * @param array<int,array<string,mixed>> $schedule
     */
    public function writeStaged(array $schedule): void
    {
        $json = json_encode(
            $schedule,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        $json = (string) $json;
        if (trim($json) === '') {
            throw new \RuntimeException('Refusing to write empty staged schedule JSON');
        }

        if (!is_dir($this->stagingDirectory)) {
            if (!@mkdir($this->stagingDirectory, 0775, true) && !is_dir($this->stagingDirectory)) {
                throw new \RuntimeException('Failed to create staging directory: ' . $this->stagingDirectory);
            }
        }

        $stagedPath = $this->stagingDirectory . '/schedule.staged.json';

        // Always overwrite staged file (never append)
        // LOCK_EX ensures atomic write and prevents concurrent append behavior
        if (@file_put_contents($stagedPath, $json . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write staged schedule: ' . $stagedPath);
        }
    }

    /**
     * Commits staged schedule to live schedule.json.
     *
     * Behavior:
     * - Creates a single backup: schedule.backup.json (overwritten each run)
     * - Atomically replaces live schedule.json
     */
    public function commitStaged(): void
    {
        $stagedPath = $this->stagingDirectory . '/schedule.staged.json';
        $backupPath = $this->stagingDirectory . '/schedule.backup.json';

        if (!file_exists($stagedPath)) {
            throw new \RuntimeException('No staged schedule to commit: ' . $stagedPath);
        }

        $this->commitStagedViaApi($stagedPath, $backupPath);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function loadViaApi(): array
    {
        $payload = $this->requestScheduleApi('GET');
        if ($payload === []) {
            return [];
        }

        if (array_is_list($payload)) {
            return $payload;
        }

        if (isset($payload['schedule']) && is_array($payload['schedule']) && array_is_list($payload['schedule'])) {
            return $payload['schedule'];
        }

        throw new \RuntimeException('FPP schedule API returned unexpected payload shape');
    }

    private function commitStagedViaApi(string $stagedPath, string $backupPath): void
    {
        $stagedJson = file_get_contents($stagedPath);
        if (!is_string($stagedJson) || trim($stagedJson) === '') {
            throw new \RuntimeException('Failed to read staged schedule payload: ' . $stagedPath);
        }

        $decodedStaged = json_decode($stagedJson, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decodedStaged) || !array_is_list($decodedStaged)) {
            throw new \RuntimeException('Staged schedule JSON must be a list payload');
        }

        // Preserve the same local backup artifact behavior as file-mode commit.
        $current = $this->loadViaApi();
        $backupJson = json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (@file_put_contents($backupPath, (string)$backupJson . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to create schedule backup: ' . $backupPath);
        }

        $this->requestScheduleApi('POST', $stagedJson);
    }

    /**
     * @return array<string,mixed>|array<int,array<string,mixed>>
     */
    private function requestScheduleApi(string $method, ?string $jsonBody = null): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('FPP schedule API access requires cURL');
        }

        $ch = curl_init(self::FPP_SCHEDULE_API_URL);
        if ($ch === false) {
            throw new \RuntimeException('Unable to initialize cURL for FPP schedule API');
        }

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ];

        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = (string)$jsonBody;
        } else {
            $opts[CURLOPT_HTTPGET] = true;
        }

        curl_setopt_array($ch, $opts);

        $rawBody = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (!is_string($rawBody)) {
            $err = $curlError !== '' ? $curlError : 'request failed';
            throw new \RuntimeException("FPP schedule API {$method} failed: {$err}");
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \RuntimeException("FPP schedule API {$method} failed (HTTP {$httpCode})");
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("FPP schedule API {$method} returned invalid JSON");
        }

        return $decoded;
    }
}
