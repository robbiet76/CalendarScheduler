<?php

/**
 * Value object representing scheduler diff results.
 */
final class SchedulerDiffResult
{
    /** @var array<int,array<string,mixed>> */
    public array $adds;

    /** @var array<int,array<string,mixed>> */
    public array $updates;

    /** @var array<int,array<string,mixed>> */
    public array $deletes;

    /**
     * @param array<int,array<string,mixed>> $adds
     * @param array<int,array<string,mixed>> $updates
     * @param array<int,array<string,mixed>> $deletes
     */
    public function __construct(array $adds = [], array $updates = [], array $deletes = [])
    {
        $this->adds = $adds;
        $this->updates = $updates;
        $this->deletes = $deletes;
    }
}

