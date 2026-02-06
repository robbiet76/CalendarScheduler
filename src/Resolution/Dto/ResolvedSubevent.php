<?php
declare(strict_types=1);

namespace CalendarScheduler\Resolution\Dto;

/**
 * A resolved subevent is one FPP-executable entry emitted by Resolution.
 * Stage 1 keeps payload as opaque array; later stages can formalize it.
 */
final class ResolvedSubevent
{
    private string $sourceEventUid;
    private string $parentUid;
    private string $role; // ResolutionRole::BASE | ResolutionRole::OVERRIDE
    private ResolutionScope $scope;

    /** @var array<string,mixed> */
    private array $payload;

    /**
     * @param array<string,mixed> $payload canonical settings needed for FPP entry creation
     */
    public function __construct(
        string $sourceEventUid,
        string $parentUid,
        string $role,
        ResolutionScope $scope,
        array $payload
    ) {
        $this->sourceEventUid = $sourceEventUid;
        $this->parentUid = $parentUid;
        $this->role = $role;
        $this->scope = $scope;
        $this->payload = $payload;
    }

    public function getSourceEventUid(): string
    {
        return $this->sourceEventUid;
    }

    public function getParentUid(): string
    {
        return $this->parentUid;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getScope(): ResolutionScope
    {
        return $this->scope;
    }

    /**
     * @return array<string,mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }
}
