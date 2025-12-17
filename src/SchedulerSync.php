<?php

class SchedulerSync
{
    private bool $dryRun;

    public function __construct(bool $dryRun = true)
    {
        $this->dryRun = $dryRun;
    }

    /**
     * @param array<int,array<string,mixed>> $intents
     * @return array<string,mixed>
     */
    public function sync(array $intents): array
    {
        $count = 0;

        foreach ($intents as $intent) {
            $count++;
            GcsLogger::instance()->info('Scheduler intent (dry-run)', $intent);
        }

        return [
            'adds'         => 0,
            'updates'      => 0,
            'deletes'      => 0,
            'dryRun'       => $this->dryRun,
            'intents_seen' => $count,
        ];
    }
}

class GcsSchedulerSync extends SchedulerSync {}
