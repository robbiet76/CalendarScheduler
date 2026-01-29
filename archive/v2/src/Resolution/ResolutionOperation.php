<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Resolution;

final class ResolutionOperation
{
    public const CREATE   = 'CREATE';
    public const UPDATE   = 'UPDATE';
    public const DELETE   = 'DELETE';
    public const CONFLICT = 'CONFLICT';
    public const NOOP     = 'NOOP';

    public string $status;
    public string $identityHash;
    public string $reason;

    /** @var array|null future DiffIntent payload */
    public ?array $diffIntent;

    public function __construct(string $status, string $identityHash, string $reason, ?array $diffIntent = null)
    {
        $this->status = $status;
        $this->identityHash = $identityHash;
        $this->reason = $reason;
        $this->diffIntent = $diffIntent;
    }
}