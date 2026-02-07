<?php
declare(strict_types=1);

namespace CalendarScheduler\Planner\Dto;

use CalendarScheduler\Resolution\Dto\ResolutionScope;
use DateTimeImmutable;

/**
 * PlannerIntent
 *
 * Planner-neutral executable intent emitted by Resolution.
 * This is NOT FPP-specific.
 *
 * Order matters: intents are evaluated top-down by the planner.
 *
 * @param string $role One of ResolutionRole::BASE | ResolutionRole::OVERRIDE
 * @param array<string,mixed> $payload
 * @param array $sourceTrace
 * @param DateTimeImmutable $start Start time, may represent symbolic display time and is not execution-resolved here.
 * @param DateTimeImmutable $end End time, may represent symbolic display time and is not execution-resolved here.
 */
final class PlannerIntent
{
    public string $bundleUid;
    public string $parentUid;
    public string $sourceEventUid;
    public string $provider;

    public DateTimeImmutable $start;
    public DateTimeImmutable $end;
    public bool $allDay;
    public ?string $timezone;

    /**
     * Role of the intent within its bundle.
     * One of ResolutionRole::BASE or ResolutionRole::OVERRIDE (string constants).
     */
    public string $role;
    public ResolutionScope $scope;

    public int $priority;

    /** @var array<string,mixed> */
    public array $payload;

    /** @var array */
    public array $sourceTrace;

    /**
     * @param string $role One of ResolutionRole::BASE | ResolutionRole::OVERRIDE
     * @param array<string,mixed> $payload
     * @param array $sourceTrace
     * @param DateTimeImmutable $start Start time, may represent symbolic display time and is not execution-resolved here.
     * @param DateTimeImmutable $end End time, may represent symbolic display time and is not execution-resolved here.
     */
    public function __construct(
        string $bundleUid,
        string $parentUid,
        string $sourceEventUid,
        string $provider,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        bool $allDay,
        ?string $timezone,
        string $role,
        ResolutionScope $scope,
        int $priority,
        array $payload,
        array $sourceTrace
    ) {
        $this->bundleUid = $bundleUid;
        $this->parentUid = $parentUid;
        $this->sourceEventUid = $sourceEventUid;
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
    }
}