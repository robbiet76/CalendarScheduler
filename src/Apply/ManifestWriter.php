<?php
declare(strict_types=1);

namespace CalendarScheduler\Apply;

/**
 * ManifestWriter (v2)
 *
 * Deterministic persistence boundary for the Manifest.
 *
 * Responsibilities:
 * - Apply Diff results (create / update / delete)
 * - Persist the final Manifest atomically
 *
 * Non-responsibilities:
 * - No hashing
 * - No identity enforcement
 * - No semantic validation
 * - No diff logic
 * - No manifest construction
 *
 * All invariants are guaranteed upstream.
 */
final class ManifestWriter
{
    /**
     * Path to the manifest.json file where the manifest will be persisted.
     */
    private string $manifestPath;

    public function __construct(string $manifestPath)
    {
        $this->manifestPath = $manifestPath;
    }

    /**
     * Persist a fully resolved target manifest.
     *
     * This is the ONLY entry point used by ApplyRunner.
     * No diff logic, no reconciliation, no validation.
     *
     * @param array<string,mixed> $targetManifest
     */
    public function applyTargetManifest(array $targetManifest): void
    {
        // Ensure root shape
        if (!isset($targetManifest['events']) || !is_array($targetManifest['events'])) {
            $targetManifest['events'] = [];
        }

        // Deterministic ordering
        ksort($targetManifest['events'], SORT_STRING);

        $targetManifest['version'] = 2;
        $targetManifest['generated_at'] = (new \DateTimeImmutable(
            'now',
            new \DateTimeZone('UTC')
        ))->format(DATE_ATOM);

        $this->writeManifest($targetManifest);
    }

    /**
     * Apply a Diff plan and persist the resulting Manifest.
     *
     * @param array $currentManifest Existing manifest (decoded JSON)
     * @param DiffResult $diffPlan   Diff plan (creates / updates / deletes / noops)
     * @param array $intents         Normalized Intents indexed by identityHash
     *
     * @return array The manifest structure that was written
     */
    public function applyDiff(
        array $currentManifest,
        DiffResult $diffPlan,
        array $intents // kept for signature stability, not used here
    ): array {
        // Normalize manifest root
        $manifest = $currentManifest;
        if (!isset($manifest['events']) || !is_array($manifest['events'])) {
            $manifest['events'] = [];
        }

        // ----------------------------
        // DELETE
        // ----------------------------
        foreach ($diffPlan->deletes() as $event) {
            if (!is_array($event) || !isset($event['identityHash'])) {
                throw new \RuntimeException(
                    'ManifestWriter: delete entry missing identityHash'
                );
            }

            $identityHash = $event['identityHash'];
            unset($manifest['events'][$identityHash]);
        }

        // ----------------------------
        // CREATE
        // ----------------------------
        foreach ($diffPlan->creates() as $event) {
            if (!is_array($event) || !isset($event['identityHash'])) {
                throw new \RuntimeException(
                    'ManifestWriter: create entry missing identityHash'
                );
            }

            $identityHash = $event['identityHash'];
            $manifest['events'][$identityHash] = $event;
        }

        // ----------------------------
        // UPDATE (replace entire event)
        // ----------------------------
        foreach ($diffPlan->updates() as $event) {
            if (!is_array($event) || !isset($event['identityHash'])) {
                throw new \RuntimeException(
                    'ManifestWriter: update entry missing identityHash'
                );
            }

            $identityHash = $event['identityHash'];
            $manifest['events'][$identityHash] = $event;
        }

        // ----------------------------
        // Manifest metadata
        // ----------------------------
        $manifest['version'] = 2;
        $manifest['generated_at'] = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->format(DATE_ATOM);

        // Deterministic ordering
        ksort($manifest['events'], SORT_STRING);

        // ----------------------------
        // Persist atomically
        // ----------------------------
        $this->writeManifest($manifest);

        return $manifest;
    }

    /**
     * Atomically write manifest JSON to disk.
     */
    private function writeManifest(array $manifest): void
    {
        $json = json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        $dir = dirname($this->manifestPath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException(
                    "ManifestWriter: failed to create directory '{$dir}'"
                );
            }
        }

        $tmpPath = $this->manifestPath . '.tmp';

        if (file_put_contents($tmpPath, $json . PHP_EOL) === false) {
            throw new \RuntimeException(
                "ManifestWriter: failed to write temp manifest '{$tmpPath}'"
            );
        }

        if (!rename($tmpPath, $this->manifestPath)) {
            throw new \RuntimeException(
                "ManifestWriter: failed to replace manifest '{$this->manifestPath}'"
            );
        }
    }
}