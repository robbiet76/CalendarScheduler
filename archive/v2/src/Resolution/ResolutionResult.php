<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Resolution;

final class ResolutionResult
{
    /** @var ResolutionOperation[] */
    public array $operations = [];

    /** @var array<string,int> */
    public array $counts = [
        ResolutionOperation::CREATE   => 0,
        ResolutionOperation::UPDATE   => 0,
        ResolutionOperation::DELETE   => 0,
        ResolutionOperation::CONFLICT => 0,
        ResolutionOperation::NOOP     => 0,
    ];

    public ResolutionPolicy $policy;

    /** @var array */
    public array $context;

    public function __construct(ResolutionPolicy $policy, array $context = [])
    {
        $this->policy = $policy;
        $this->context = $context;
    }

    public function add(ResolutionOperation $op): void
    {
        $this->operations[] = $op;
        if (isset($this->counts[$op->status])) {
            $this->counts[$op->status]++;
        }
    }
}