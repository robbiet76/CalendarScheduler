<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Resolution;

final class ResolutionResult
{
    /** @var ResolutionOperation[] */
    public array $operations = [];

    public array $warnings = [];

    public array $stats = [
        'UPSERT'   => 0,
        'DELETE'   => 0,
        'NOOP'     => 0,
        'CONFLICT' => 0,
        'REVIEW'   => 0,
    ];

    public function addOperation(ResolutionOperation $op): void
    {
        $this->operations[] = $op;
        if (isset($this->stats[$op->op])) {
            $this->stats[$op->op]++;
        }
    }
}