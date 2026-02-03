<?php
declare(strict_types=1);

namespace CalendarScheduler\Intent;

/**
 * FppRawEvent
 *
 * Raw scheduler entry as read directly from FPP schedule.json.
 *
 * PURPOSE
 * -------
 * Represents the factual truth of a single FPP scheduler entry
 * exactly as stored by Falcon Player.
 *
 * This is a RAW boundary object.
 *
 * It intentionally does NOT:
 * - infer scheduling intent
 * - normalize semantics
 * - correct invalid combinations
 * - resolve identity
 * - expand recurrence
 * - apply planner defaults or policy
 *
 * Any interpretation or transformation MUST occur downstream
 * in IntentNormalizer.
 *
 *
 * GUARANTEES
 * ----------
 * The following guarantees are enforced by construction:
 *
 * - data
 *   Contains the scheduler entry exactly as read from schedule.json,
 *   with no mutation, inference, or normalization.
 *
 *   This includes (but is not limited to):
 *   - enabled flags
 *   - sequence / playlist indicators
 *   - playlist or sequence filename
 *   - startDate / endDate
 *   - startTime / endTime
 *   - repeat
 *   - stopType
 *   - day bitmask
 *
 * - Fidelity
 *   All values reflect what FPP is actually using at runtime,
 *   including historically inconsistent or invalid combinations.
 *
 *
 * HARD NON-GUARANTEES
 * ------------------
 * FppRawEvent explicitly does NOT guarantee:
 *
 * - Correctness relative to calendar data
 * - Valid semantic combinations (e.g. playlist vs sequence mismatch)
 * - Stable identity or hashing
 * - Human scheduling intent
 * - Compatibility with manifest schema
 *
 *
 * ARCHITECTURAL ROLE
 * ------------------
 * FppRawEvent exists to:
 * - Preserve FPP ground truth
 * - Prevent accidental semantic drift
 * - Act as the sole input to IntentNormalizer::fromFpp()
 *
 * If you find yourself wanting to:
 * - normalize types (playlist vs sequence)
 * - strip file extensions
 * - apply defaults
 * - resolve symbolic vs hard times
 * - compute identity hashes
 *
 * you are in the wrong layer.
 */
final class FppRawEvent
{
    /** @var array */
    public array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }
}