<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Manifest;

use GoogleCalendarScheduler\Intent\Intent;
use GoogleCalendarScheduler\Diff\DiffResult;

/**
 * ManifestWriter (v2)
 *
 * Deterministic persistence boundary for the Manifest.
 *
 * Responsibilities:
 * - Apply Diff results (create / update / delete)
 * - Materialize Manifest Events from normalized Intents
 * - Persist the final Manifest atomically
 * - Write canonical manifest.json (v2)
 *
 * Non-responsibilities:
 * - No hashing
 * - No identity enforcement
 * - No semantic validation
 * - No diff logic
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
     * Build an in-memory "next" manifest structure from Intents for diffing.
     *
     * This method does not perform any I/O. It deterministically builds
     * a manifest array as would be persisted, suitable for diffing.
     *
     * @param array $intents Array of normalized Intents indexed by identityHash
     * @return array Manifest structure (not persisted)
     */
    public function buildManifestFromIntents(array $intents): array
    {
        $manifest = ['events' => []];
        foreach ($intents as $identityHash => $intent) {
            if (!($intent instanceof \GoogleCalendarScheduler\Intent\Intent)) {
                throw new \InvalidArgumentException(
                    "ManifestWriter: intent at key '{$identityHash}' must be an instance of Intent"
                );
            }

            $event = $this->renderEvent($intent);

            if (
                !isset($event['identityHash']) ||
                !is_string($event['identityHash']) ||
                $event['identityHash'] === ''
            ) {
                throw new \RuntimeException(
                    "ManifestWriter: rendered event missing required identityHash"
                );
            }

            $manifest['events'][$event['identityHash']] = $event;
        }
        ksort($manifest['events'], SORT_STRING);
        $manifest['version'] = 2;
        $manifest['generated_at'] = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->format(DATE_ATOM);
        return $manifest;
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
        array $intents
    ): array {
        // Normalize manifest root
        $manifest = $currentManifest;
        if (!isset($manifest['events']) || !is_array($manifest['events'])) {
            $manifest['events'] = [];
        }

        // ----------------------------
        // DELETE
        // ----------------------------
        foreach ($diffPlan->deletes() as $identityHash) {
            unset($manifest['events'][$identityHash]);
        }

        // ----------------------------
        // CREATE
        // ----------------------------
        foreach ($diffPlan->creates() as $identityHash) {
            if (!isset($intents[$identityHash])) {
                throw new \RuntimeException(
                    "ManifestWriter: missing Intent for create '{$identityHash}'"
                );
            }

            $manifest['events'][$identityHash] =
                $this->renderEvent($intents[$identityHash]);
        }

        // ----------------------------
        // UPDATE (replace entire event)
        // ----------------------------
        foreach ($diffPlan->updates() as $identityHash) {
            if (!isset($intents[$identityHash])) {
                throw new \RuntimeException(
                    "ManifestWriter: missing Intent for update '{$identityHash}'"
                );
            }

            $manifest['events'][$identityHash] =
                $this->renderEvent($intents[$identityHash]);
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
     * Render a Manifest Event from a normalized Intent.
     *
     * Pure transform — no mutation, no inference.
     */
    private function renderEvent(Intent $intent): array
    {
        $subEvents = [];

        // Each subEvent MUST already include a stateHash computed during normalization.
        // ManifestWriter does not compute or repair state.
        // SubEvents MUST already be in canonical, deterministic order.
        // Ordering is defined upstream by IntentNormalizer and MUST NOT change here.
        // Event-level stateHash depends on this ordering being stable across sources.
        foreach ($intent->subEvents as $sub) {
            if (!isset($sub['stateHash']) || !is_string($sub['stateHash'])) {
                throw new \RuntimeException(
                    'ManifestWriter: subEvent missing required stateHash'
                );
            }
            $subEvents[] = [
                'stateHash' => $sub['stateHash'],
                'timing'    => $sub['timing'],
                'behavior'  => [
                    'enabled'  => $sub['payload']['enabled'] ?? true,
                    'repeat'   => $sub['payload']['repeat'] ?? 'none',
                    'stopType' => $sub['payload']['stopType'] ?? 'graceful',
                ],
                'payload'   => $this->renderPayload($sub['payload']),
            ];
        }

        // Event-level stateHash is a deterministic aggregation of ordered subEvent state hashes.
        // Aggregate event-level state hash from subEvent state hashes
        $eventStateHash = hash(
            'sha256',
            implode('|', array_map(
                static fn(array $s) => $s['stateHash'],
                $subEvents
            ))
        );

        // Invariant: managed events must have at least one subEvent contributing state.
        if (($intent->ownership['managed'] ?? false) && $subEvents === []) {
            throw new \RuntimeException(
                'ManifestWriter: managed event must contain at least one subEvent'
            );
        }

        // Invariant: event-level stateHash must always be non-empty for managed events.
        if (($intent->ownership['managed'] ?? false) && $eventStateHash === '') {
            throw new \RuntimeException(
                'ManifestWriter: managed event missing required stateHash'
            );
        }

        return [
            'id'           => $intent->identityHash,
            'identityHash' => $intent->identityHash,
            'stateHash'    => $eventStateHash,

            'identity'     => $intent->identity,
            'ownership'    => $intent->ownership,
            'correlation'  => $intent->correlation,
            'provenance'   => $intent->provenance ?? null,

            'subEvents'    => $subEvents,
        ];
    }

    /**
     * Render execution payload.
     *
     * Keeps payload stable and explicit.
     */
    private function renderPayload(array $payload): array
    {
        // Remove behavior fields already lifted to behavior block
        $out = $payload;

        unset(
            $out['enabled'],
            $out['repeat'],
            $out['stopType']
        );

        return $out;
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