<?php
declare(strict_types=1);

namespace GCS\Core;

/**
 * ManifestStore
 *
 * Authoritative persistence + invariant enforcement boundary for the Manifest.
 *
 * Philosophy (v2):
 * - Fail hard on invariant violations.
 * - Keep enforcement minimal and focused on provider-originated data risk.
 * - Do not build elaborate "repair" systems; only minimal normalization when intent is unambiguous.
 *
 * Storage model:
 * - Manifest is an array/JSON document persisted to disk by FileManifestStore.
 * - Store is the only writer.
 */
interface ManifestStore
{
    /**
     * Load manifest from storage into memory.
     *
     * Hard-fail if the manifest is unreadable or violates invariants.
     */
    public function load(): array;

    /**
     * Persist the in-memory manifest back to storage.
     *
     * Hard-fail if invariants do not hold.
     */
    public function save(array $manifest): void;

    /**
     * Upsert a Manifest Event (calendar event -> manifest event).
     *
     * Expected shape (high-level, spec-driven):
     * - event.id (string) stable manifest event id
     * - event.uid (provider UID object or string)
     * - event.identity (IdentityObject; semantic identity only)
     * - event.intent (IntentObject; execution intent)
     * - event.subEvents (array of subEvent objects; each subEvent has its own identity hash/id)
     *
     * Hard-fail on invariant violations.
     *
     * @return array Updated manifest (store may also update derived fields like identity hash/id).
     */
    public function upsertEvent(array $manifest, array $event): array;
}

