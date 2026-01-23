<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Resolution;

/**
 * One atomic event-level decision.
 * Apply phase later decides how to enact it.
 */
final class ResolutionOperation
{
    public const UPSERT   = 'UPSERT';
    public const DELETE   = 'DELETE';
    public const NOOP     = 'NOOP';
    public const CONFLICT = 'CONFLICT';
    public const REVIEW  = 'REVIEW';

    public string $op;
    public string $identityHash;

    /** Full manifest event (required for UPSERT) */
    public ?array $desiredEvent;

    /** Short machine-readable reason */
    public string $reason;

    /** Optional structured debug payload */
    public array $details;

    public function __construct(
        string $op,
        string $identityHash,
        ?array $desiredEvent,
        string $reason,
        array $details = []
    ) {
        $this->op            = $op;
        $this->identityHash  = $identityHash;
        $this->desiredEvent  = $desiredEvent;
        $this->reason        = $reason;
        $this->details       = $details;
    }
}