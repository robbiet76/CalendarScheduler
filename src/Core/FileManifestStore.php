<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Core;

use GoogleCalendarScheduler\Core\ManifestStore;
use GoogleCalendarScheduler\Core\ManifestInvariantViolation;
use GoogleCalendarScheduler\Core\IdentityInvariantViolation;
use GoogleCalendarScheduler\Core\IdentityCanonicalizer;
use GoogleCalendarScheduler\Core\IdentityHasher;

/**
 * FileManifestStore
 *
 * JSON file-backed Manifest store.
 *
 * Minimal enforcement strategy:
 * - load(): validate structure is readable and identities are valid (hard fail)
 * - upsertEvent(): validate identity invariants + prevent identity mutation (hard fail)
 * - save(): validate again before writing (hard fail)
 *
 * This store does not attempt to "repair" complex cases.
 */
final class FileManifestStore implements ManifestStore
{
    private string $path;
    private IdentityHasher $hasher;

    public function __construct(string $path, IdentityHasher $hasher)
    {
        $this->path = $path;
        $this->hasher = $hasher;
    }

    public function load(): array
    {
        if (!file_exists($this->path)) {
            // Treat missing as empty manifest for now (still a valid state)
            return ['events' => []];
        }

        $raw = @file_get_contents($this->path);
        if ($raw === false) {
            throw ManifestInvariantViolation::fail(
                ManifestInvariantViolation::MANIFEST_UNREADABLE,
                'Manifest file could not be read',
                ['path' => $this->path]
            );
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw ManifestInvariantViolation::fail(
                ManifestInvariantViolation::MANIFEST_JSON_INVALID,
                'Manifest JSON is invalid or not an object',
                ['path' => $this->path]
            );
        }

        $this->assertManifestRoot($decoded);
        $this->assertAllIdentityInvariants($decoded);

        return $decoded;
    }

    public function loadDraft(): array
    {
        if (!file_exists($this->path)) {
            // Treat missing as empty manifest for now (still a valid state)
            return ['events' => []];
        }

        $raw = @file_get_contents($this->path);
        if ($raw === false) {
            throw ManifestInvariantViolation::fail(
                ManifestInvariantViolation::MANIFEST_UNREADABLE,
                'Manifest file could not be read',
                ['path' => $this->path]
            );
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw ManifestInvariantViolation::fail(
                ManifestInvariantViolation::MANIFEST_JSON_INVALID,
                'Manifest JSON is invalid or not an object',
                ['path' => $this->path]
            );
        }

        $this->assertManifestRoot($decoded);

        return $decoded;
    }

    public function save(array $manifest): void
    {
        $this->assertManifestRoot($manifest);
        $this->assertAllIdentityInvariants($manifest);

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw ManifestInvariantViolation::fail(
                ManifestInvariantViolation::MANIFEST_JSON_INVALID,
                'Failed to encode manifest to JSON',
                ['path' => $this->path]
            );
        }

        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $ok = @file_put_contents($this->path, $json . PHP_EOL);
        if ($ok === false) {
            throw ManifestInvariantViolation::fail(
                ManifestInvariantViolation::MANIFEST_UNREADABLE,
                'Failed to write manifest file',
                ['path' => $this->path]
            );
        }
    }

    public function saveDraft(array $manifest): void
    {
        $this->assertManifestRoot($manifest);

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw ManifestInvariantViolation::fail(
                ManifestInvariantViolation::MANIFEST_JSON_INVALID,
                'Failed to encode manifest to JSON',
                ['path' => $this->path]
            );
        }

        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $ok = @file_put_contents($this->path, $json . PHP_EOL);
        if ($ok === false) {
            throw ManifestInvariantViolation::fail(
                ManifestInvariantViolation::MANIFEST_UNREADABLE,
                'Failed to write manifest file',
                ['path' => $this->path]
            );
        }
    }

    public function upsertEvent(array $manifest, array $event): array
    {
        $this->assertManifestRoot($manifest);

        if (!isset($manifest['events']) || !is_array($manifest['events'])) {
            $manifest['events'] = [];
        }

        // Require event.id for now (keeps store deterministic and simple).
        $eventId = $event['id'] ?? null;
        if (!is_string($eventId) || $eventId === '') {
            throw ManifestInvariantViolation::fail(
                ManifestInvariantViolation::EVENT_MISSING_ID,
                'Event.id is required for upsert',
                ['event' => $event]
            );
        }

        // Identity must exist.
        if (!isset($event['identity']) || !is_array($event['identity'])) {
            throw ManifestInvariantViolation::fail(
                ManifestInvariantViolation::EVENT_IDENTITY_MISSING,
                'Event.identity is required',
                ['eventId' => $eventId]
            );
        }

        // Derive identity hash (event-level).
        $canonical = IdentityCanonicalizer::canonicalize($event['identity']);
        $eventIdentityHash = $this->hasher->hash($canonical);
        $event['identity_hash'] = $eventIdentityHash;

        // SubEvents: each must have identity; each gets its own hash.
        if (isset($event['subEvents'])) {
            if (!is_array($event['subEvents'])) {
                throw ManifestInvariantViolation::fail(
                    ManifestInvariantViolation::SUBEVENT_IDENTITY_INVALID,
                    'Event.subEvents must be an array if present',
                    ['eventId' => $eventId]
                );
            }
            foreach ($event['subEvents'] as $idx => $sub) {
                if (!is_array($sub) || !isset($sub['identity']) || !is_array($sub['identity'])) {
                    throw ManifestInvariantViolation::fail(
                        ManifestInvariantViolation::SUBEVENT_IDENTITY_INVALID,
                        'Each subEvent must include an identity object',
                        ['eventId' => $eventId, 'subIndex' => $idx]
                    );
                }
                $subCanonical = IdentityCanonicalizer::canonicalize($sub['identity']);
                $subHash = $this->hasher->hash($subCanonical);
                $event['subEvents'][$idx]['identity_hash'] = $subHash;
            }
        } else {
            // Always present as array for simplicity downstream.
            $event['subEvents'] = [];
        }

        // Detect identity mutation if event exists.
        $existingIndex = $this->findEventIndexById($manifest['events'], $eventId);
        if ($existingIndex !== null) {
            $existing = $manifest['events'][$existingIndex];
            $existingHash = $existing['identity_hash'] ?? null;
            if (is_string($existingHash) && $existingHash !== $eventIdentityHash) {
                throw ManifestInvariantViolation::fail(
                    ManifestInvariantViolation::EVENT_IDENTITY_MUTATION,
                    'Identity mutation detected for existing event id',
                    ['eventId' => $eventId, 'from' => $existingHash, 'to' => $eventIdentityHash]
                );
            }
            $manifest['events'][$existingIndex] = $event;
        } else {
            // Ensure no duplicate IDs.
            if ($this->findEventIndexById($manifest['events'], $eventId) !== null) {
                throw ManifestInvariantViolation::fail(
                    ManifestInvariantViolation::EVENT_DUPLICATE_ID,
                    'Duplicate event.id detected',
                    ['eventId' => $eventId]
                );
            }
            $manifest['events'][] = $event;
        }

        // Enforce global identity rules after mutation.
        $this->assertAllIdentityInvariants($manifest);

        return $manifest;
    }

    public function appendEvent(array $manifest, array $event): array
    {
        $this->assertManifestRoot($manifest);

        if (!isset($manifest['events']) || !is_array($manifest['events'])) {
            $manifest['events'] = [];
        }

        if (isset($event['id']) && $event['id'] !== null) {
            throw ManifestInvariantViolation::fail(
                ManifestInvariantViolation::EVENT_IDENTITY_MUTATION,
                'appendEvent must not be used with identified events',
                ['eventId' => $event['id']]
            );
        }

        $manifest['events'][] = $event;

        return $manifest;
    }

    // ---------------------------------------------------------------------
    // Invariant enforcement (minimal, hard-fail)
    // ---------------------------------------------------------------------

    private function assertManifestRoot(array $manifest): void
    {
        // For v2, we only require that 'events' is an array if present.
        if (isset($manifest['events']) && !is_array($manifest['events'])) {
            throw ManifestInvariantViolation::fail(
                ManifestInvariantViolation::MANIFEST_ROOT_INVALID,
                "Manifest root 'events' must be an array"
            );
        }
    }

    private function assertAllIdentityInvariants(array $manifest): void
    {
        $events = $manifest['events'] ?? [];
        if (!is_array($events)) {
            throw ManifestInvariantViolation::fail(
                ManifestInvariantViolation::MANIFEST_ROOT_INVALID,
                "Manifest root 'events' must be an array"
            );
        }

        // Event id uniqueness.
        $seenEventIds = [];
        $seenEventIdentityHashes = [];
        foreach ($events as $i => $event) {
            if (!is_array($event)) {
                throw ManifestInvariantViolation::fail(
                    ManifestInvariantViolation::MANIFEST_ROOT_INVALID,
                    'Manifest.events must contain objects/arrays',
                    ['index' => $i]
                );
            }

            $eventId = $event['id'] ?? null;
            if (!is_string($eventId) || $eventId === '') {
                throw ManifestInvariantViolation::fail(
                    ManifestInvariantViolation::EVENT_MISSING_ID,
                    'Manifest event missing id',
                    ['index' => $i]
                );
            }
            if (isset($seenEventIds[$eventId])) {
                throw ManifestInvariantViolation::fail(
                    ManifestInvariantViolation::EVENT_DUPLICATE_ID,
                    'Duplicate manifest event id',
                    ['eventId' => $eventId]
                );
            }
            $seenEventIds[$eventId] = true;

            // Identity validation.
            if (!isset($event['identity']) || !is_array($event['identity'])) {
                throw ManifestInvariantViolation::fail(
                    ManifestInvariantViolation::EVENT_IDENTITY_MISSING,
                    'Manifest event missing identity',
                    ['eventId' => $eventId]
                );
            }

            $canonical = IdentityCanonicalizer::canonicalize($event['identity']);
            $computed = $this->hasher->hash($canonical);

            // If stored, it must match computed.
            if (isset($event['identity_hash']) && is_string($event['identity_hash']) && $event['identity_hash'] !== $computed) {
                throw IdentityInvariantViolation::fail(
                    IdentityInvariantViolation::IDENTITY_HASH_INVALID,
                    'Stored event identity_hash does not match computed hash',
                    ['eventId' => $eventId, 'stored' => $event['identity_hash'], 'computed' => $computed]
                );
            }

            // Identity hash uniqueness at event level (two events must not share identity hash).
            if (isset($seenEventIdentityHashes[$computed])) {
                throw IdentityInvariantViolation::fail(
                    IdentityInvariantViolation::IDENTITY_DUPLICATE,
                    'Duplicate event identity detected (hash collision or duplicate identity)',
                    ['eventId' => $eventId, 'identity_hash' => $computed]
                );
            }
            $seenEventIdentityHashes[$computed] = true;

            // SubEvents identity checks.
            $subEvents = $event['subEvents'] ?? [];
            if (!is_array($subEvents)) {
                throw ManifestInvariantViolation::fail(
                    ManifestInvariantViolation::SUBEVENT_IDENTITY_INVALID,
                    'Event.subEvents must be an array',
                    ['eventId' => $eventId]
                );
            }

            foreach ($subEvents as $j => $sub) {
                if (!is_array($sub) || !isset($sub['identity']) || !is_array($sub['identity'])) {
                    throw ManifestInvariantViolation::fail(
                        ManifestInvariantViolation::SUBEVENT_IDENTITY_INVALID,
                        'SubEvent missing identity',
                        ['eventId' => $eventId, 'subIndex' => $j]
                    );
                }
                $subCanonical = IdentityCanonicalizer::canonicalize($sub['identity']);
                $subHash = $this->hasher->hash($subCanonical);
                if (isset($sub['identity_hash']) && is_string($sub['identity_hash']) && $sub['identity_hash'] !== $subHash) {
                    throw IdentityInvariantViolation::fail(
                        IdentityInvariantViolation::IDENTITY_HASH_INVALID,
                        'Stored subEvent identity_hash does not match computed hash',
                        ['eventId' => $eventId, 'subIndex' => $j, 'stored' => $sub['identity_hash'], 'computed' => $subHash]
                    );
                }
            }
        }
    }

    /**
     * @param array<int,array> $events
     */
    private function findEventIndexById(array $events, string $eventId): ?int
    {
        foreach ($events as $i => $event) {
            if (is_array($event) && isset($event['id']) && $event['id'] === $eventId) {
                return (int)$i;
            }
        }
        return null;
    }
}

