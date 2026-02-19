<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Intent/Intent.php
 * Purpose: Defines the Intent component used by the Calendar Scheduler Intent layer.
 */

namespace CalendarScheduler\Intent;

/**
 * Intent
 *
 * Canonical, fully-normalized, comparable scheduling intent.
 *
 * This object represents the single, authoritative shape used for:
 * - resolution
 * - diffing
 * - hashing
 * - user-visible intent
 *
 * Intent is SOURCE-AGNOSTIC.
 * Calendar data, FPP schedule data, YAML metadata, and recurrence rules
 * MUST be normalized into this shape before comparison.
 *
 * IMMUTABILITY:
 * - Intent instances are immutable by convention.
 * - No mutation is permitted after construction.
 *
 * =========================
 * LOCKED SCHEMA GUARANTEES
 * =========================
 *
 * GUARANTEES BY THE TIME AN INTENT EXISTS:
 * - All dates and times are timezone-normalized
 * - All recurrence rules have been fully expanded into subEvents
 * - All defaults have been explicitly applied
 * - No source-specific fields (calendar or FPP) remain
 * - Two Intent objects with the same identityHash are safe to treat as semantically identical
 * - Intent is the ONLY object allowed to participate in resolution or diffing
 *
 *
 * identityHash:
 * - Stable, deterministic hash derived ONLY from `identity` + `subEvents`
 * - Excludes ownership, correlation, provenance, and source metadata
 * - Two Intents with the same identityHash are semantically identical
 *
 * identity:
 * - Fully resolved, human-meaningful identity
 * - Contains NO raw calendar fields (DTSTART, RRULE, TZID, UID, etc.)
 * - Contains NO FPP-specific runtime artifacts
 * - Timezone-normalized
 * - Required keys (no extras):
 *     - type   : playlist | sequence | command
 *     - target : string (no file extensions)
 *     - timing :
 *         - start_date (hard, Y-m-d)
 *         - end_date   (hard, Y-m-d)
 *         - start_time (hard, H:i:s or symbolic)
 *         - end_time   (hard, H:i:s or symbolic)
 *         - days       (normalized FPP day mask or null)
 *     - Symbolic times are allowed only in identity.timing; subEvents.timing MUST always be hard-resolved.
 *
 * subEvents:
 * - Array of concrete scheduled executions
 * - Each subEvent represents an actual run window
 * - Order is meaningful and preserved
 * - Each subEvent MUST include:
 *     - timing   (fully resolved, no recurrence)
 *     - behavior (explicit, no defaults inferred later)
 *     - payload  (if applicable)
 * - No RRULEs, EXDATEs, or recurrence metadata allowed here
 * - Any defaulting or inference of behavior MUST occur during IntentNormalizer and is forbidden during resolution.
 *
 * ownership:
 * - Explicit ownership metadata only
 * - Used for safety and mutation rules
 * - MUST NOT affect identityHash
 *
 * correlation:
 * - Lineage and traceability only (calendar UID, FPP entry id, etc.)
 * - Used for diagnostics and UI
 * - MUST NOT affect identityHash or resolution outcome
 *
 * FORBIDDEN:
 * - Raw calendar fields
 * - Raw FPP scheduler fields
 * - Implicit defaults
 * - Heuristic inference during resolution
 *
 * Intent represents USER INTENT — not source representation
 * and not runtime materialization.
 */
final class Intent
{
    public string $identityHash;

    /** @var array */
    public array $identity;

    /** @var array */
    public array $ownership;

    /** @var array */
    public array $correlation;

    /** @var array<int,array> */
    public array $subEvents;

    /** @var string */
    public string $eventStateHash;

    public function __construct(
        string $identityHash,
        array $identity,
        array $ownership,
        array $correlation,
        array $subEvents,
        string $eventStateHash
    ) {
        $this->identityHash   = $identityHash;
        $this->identity       = $identity;
        $this->ownership      = $ownership;
        $this->correlation    = $correlation;
        $this->subEvents      = $subEvents;
        $this->eventStateHash = $eventStateHash;
    }
}