<?php
declare(strict_types=1);

namespace GCS\Planner;

/**
 * PlannedEntry
 *
 * Immutable execution unit produced by the Planner.
 * Represents exactly one FPP scheduler entry.
 */
final class PlannedEntry
{
    private string $eventId;
    private string $eventIdentityHash;
    private string $subEventIdentityHash;

    private string $type;
    private string $target;
    private array $timing;
    private bool $enabled;

    private int $eventOrder;
    private int $subEventOrder;

    public function __construct(
        string $eventId,
        string $eventIdentityHash,
        string $subEventIdentityHash,
        string $type,
        string $target,
        array $timing,
        bool $enabled,
        int $eventOrder,
        int $subEventOrder
    ) {
        $this->eventId               = $eventId;
        $this->eventIdentityHash     = $eventIdentityHash;
        $this->subEventIdentityHash  = $subEventIdentityHash;
        $this->type                  = $type;
        $this->target                = $target;
        $this->timing                = $timing;
        $this->enabled               = $enabled;
        $this->eventOrder            = $eventOrder;
        $this->subEventOrder         = $subEventOrder;
    }

    // ----------------------------
    // Accessors (read-only)
    // ----------------------------

    public function eventId(): string { return $this->eventId; }
    public function eventIdentityHash(): string { return $this->eventIdentityHash; }
    public function subEventIdentityHash(): string { return $this->subEventIdentityHash; }

    public function type(): string { return $this->type; }
    public function target(): string { return $this->target; }
    public function timing(): array { return $this->timing; }
    public function enabled(): bool { return $this->enabled; }

    public function eventOrder(): int { return $this->eventOrder; }
    public function subEventOrder(): int { return $this->subEventOrder; }
}