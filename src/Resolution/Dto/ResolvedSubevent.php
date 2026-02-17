<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Resolution/Dto/ResolvedSubevent.php
 * Purpose: Defines the ResolvedSubevent component used by the Calendar Scheduler Resolution/Dto layer.
 */

namespace CalendarScheduler\Resolution\Dto;

/**
 * A resolved subevent is one FPP-executable entry emitted by Resolution.
 * Stage 1 keeps payload as opaque array; later stages can formalize it.
 *
 * Identity is by bundleUid + sourceEventUid + parentUid + provider + start + end.
 * Ordering uses priority; higher priority wins.
 */
final class ResolvedSubevent
{
    private string $bundleUid;
    private string $sourceEventUid;
    private string $parentUid;
    private string $provider;
    private \DateTimeImmutable $start;
    private \DateTimeImmutable $end;
    private bool $allDay;
    private ?string $timezone;
    private string $role;
    private ResolutionScope $scope;

    /**
     * Priority for ordering resolved subevents; higher values take precedence.
     */
    private int $priority;

    /** @var array<string,mixed> */
    private array $payload;

    /** @var array */
    private array $sourceTrace;

    /**
     * Weekly mask metadata (e.g. ['MO','WE','FR']) derived from RRULE BYDAY.
     * Null when not applicable.
     *
     * @var array<int,string>|null
     */
    private ?array $weeklyDays;

    /**
     * @param array<string,mixed> $payload canonical settings needed for FPP entry creation
     * @param array $sourceTrace
     */
    public function __construct(
        string $bundleUid,
        string $sourceEventUid,
        string $parentUid,
        string $provider,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        bool $allDay,
        ?string $timezone,
        string $role,
        ResolutionScope $scope,
        int $priority,
        array $payload,
        array $sourceTrace,
        ?array $weeklyDays = null
    ) {
        $this->bundleUid = $bundleUid;
        $this->sourceEventUid = $sourceEventUid;
        $this->parentUid = $parentUid;
        $this->provider = $provider;
        $this->start = $start;
        $this->end = $end;
        $this->allDay = $allDay;
        $this->timezone = $timezone;
        $this->role = $role;
        $this->scope = $scope;
        $this->priority = $priority;
        $this->payload = $payload;
        $this->sourceTrace = $sourceTrace;
        $this->weeklyDays = $weeklyDays;
    }

    public function getBundleUid(): string
    {
        return $this->bundleUid;
    }

    public function getSourceEventUid(): string
    {
        return $this->sourceEventUid;
    }

    public function getParentUid(): string
    {
        return $this->parentUid;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getStart(): \DateTimeImmutable
    {
        return $this->start;
    }

    public function getEnd(): \DateTimeImmutable
    {
        return $this->end;
    }

    public function isAllDay(): bool
    {
        return $this->allDay;
    }

    public function getTimezone(): ?string
    {
        return $this->timezone;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function isOverride(): bool
    {
        return $this->role === ResolutionRole::OVERRIDE;
    }

    public function isBase(): bool
    {
        return $this->role === ResolutionRole::BASE;
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

    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * @return array
     */
    public function getSourceTrace(): array
    {
        return $this->sourceTrace;
    }
    /**
     * @return array<int,string>|null
     */
    public function getWeeklyDays(): ?array
    {
        return $this->weeklyDays;
    }
}
