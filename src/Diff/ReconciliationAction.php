<?php

declare(strict_types=1);

namespace CalendarScheduler\Diff;

/**
 * Phase 4 — ReconciliationAction
 *
 * This is a *directional* plan element produced by Reconciler.
 *
 * It is still "Diff-layer": it operates purely on manifest events + timestamps,
 * and has no awareness of raw FPP schedule.json rows or calendar provider API shapes.
 */
final class ReconciliationAction
{
    public const TYPE_CREATE = 'create';
    public const TYPE_UPDATE = 'update';
    public const TYPE_DELETE = 'delete';
    public const TYPE_NOOP = 'noop';
    /**
     * Terminal planning result.
     *
     * TYPE_BLOCK actions represent unmanaged collisions or explicit policy
     * refusals. They MUST NOT be forwarded to provider-specific Apply layers.
     */
    public const TYPE_BLOCK = 'block'; // unmanaged/locked collision or policy refusal

    public const TARGET_FPP = 'fpp';
    public const TARGET_CALENDAR = 'calendar';

    public const AUTHORITY_FPP = 'fpp';
    public const AUTHORITY_CALENDAR = 'calendar';

    /** @var string */
    public string $type;

    /** @var string */
    public string $target; // fpp|calendar

    /** @var string */
    public string $authority; // fpp|calendar

    /** @var string */
    public string $identityHash;

    /** @var string */
    public string $reason;

    /**
     * Winning Manifest Event state for create/update, or the event to delete for deletes.
     *
     * This payload ALWAYS represents the full Manifest Event envelope,
     * including all embedded subevents. Apply layers MUST treat this
     * as the authoritative event envelope and are responsible for
     * collapsing subevents into provider-specific representations.
     *
     * @var array<string,mixed>|null
     */
    public ?array $event;

    /**
     * @param array<string,mixed>|null $event
     */
    public function __construct(
        string $type,
        string $target,
        string $authority,
        string $identityHash,
        string $reason,
        ?array $event
    ) {
        $this->type = $type;
        $this->target = $target;
        $this->authority = $authority;
        $this->identityHash = $identityHash;
        $this->reason = $reason;
        $this->event = $event;

        if (
            in_array($type, [self::TYPE_CREATE, self::TYPE_UPDATE, self::TYPE_DELETE], true)
            && $event === null
        ) {
            throw new \InvalidArgumentException(
                "ReconciliationAction '{$type}' requires a non-null event payload"
            );
        }
    }
}
