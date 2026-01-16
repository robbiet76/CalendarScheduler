<?php
declare(strict_types=1);

namespace GCS\Core;

use GCS\Core\Exception\ManifestInvariantViolation;

/**
 * ManifestStore
 *
 * The sole authoritative persistence and mutation boundary
 * for the Manifest.
 *
 * All invariants MUST be enforced here.
 */
interface ManifestStore
{
    /**
     * Load the manifest from persistent storage.
     *
     * Invariants:
     * - Returned manifest MUST be valid
     * - Schema version MUST be compatible
     *
     * @throws ManifestInvariantViolation
     */
    public function load(): array;

    /**
     * Persist the manifest atomically.
     *
     * Invariants:
     * - Entire write succeeds or nothing is written
     * - Manifest MUST validate before save
     *
     * @throws ManifestInvariantViolation
     */
    public function save(array $manifest): void;

    /**
     * Insert or merge a ManifestEvent.
     *
     * Behavior:
     * - If identity does not exist → insert
     * - If identity exists → merge into existing event
     *
     * Invariants:
     * - Identity MUST be complete and valid
     * - Identity MUST NOT be mutated
     * - Identity uniqueness MUST be enforced
     *
     * @throws ManifestInvariantViolation
     */
    public function upsertEvent(array $event): void;

    /**
     * Remove a ManifestEvent by identity hash.
     *
     * Rules:
     * - Only managed events may be removed
     * - Removal MUST be explicit
     *
     * @throws ManifestInvariantViolation
     */
    public function removeEvent(string $identityHash): void;

    /**
     * Return an immutable snapshot of the manifest.
     *
     * Purpose:
     * - Planner input
     * - Diff baseline
     *
     * Invariants:
     * - Snapshot MUST be valid
     * - Caller MUST NOT mutate returned data
     *
     * @throws ManifestInvariantViolation
     */
    public function snapshot(): array;

    /**
     * Validate the entire manifest.
     *
     * Checks:
     * - Structural integrity
     * - Identity invariants
     * - SubEvent integrity
     * - DatePattern / TimeToken correctness
     *
     * @throws ManifestInvariantViolation
     */
    public function validate(array $manifest): void;

    /**
     * Create an immutable backup snapshot.
     *
     * Purpose:
     * - One-level undo / revert
     *
     * Invariants:
     * - Backup MUST represent a valid manifest
     *
     * @throws ManifestInvariantViolation
     */
    public function snapshotBackup(): void;

    /**
     * Revert to the last valid backup snapshot.
     *
     * Rules:
     * - Exactly one level of revert supported
     * - Revert MUST be atomic
     *
     * @throws ManifestInvariantViolation
     */
    public function revert(): void;
}