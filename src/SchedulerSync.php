<?php

/**
 * SchedulerSync
 *
 * Phase 13 behavior:
 * - Accept resolved scheduler intents
 * - Report what *would* be created/updated/deleted
 * - Do NOT modify the scheduler yet
 *
 * This class is intentionally conservative and dry-run safe.
 */
class SchedulerSync
{
    private bool $dryRun;

    /**
     * @param bool $dryRun
     */
    public function __construct(bool $dryRun = true)
    {
        $this->dryRun = (bool)$dryRun;
    }

    /**
     * Sync resolved intents against the scheduler.
     *
     * CURRENT PHASE 13 LOGIC:
     * - Scheduler is treated as empty
     * - Each intent represents a CREATE
     * - No updates or deletes yet
     *
     * @param array<int,array<string,mixed>> $intents
     * @return array<string,mixed>
     */
    public function sync(array $intents): array
    {
        $adds = 0;

        foreach ($intents as $intent) {
            // Log every intent for visibility (dry-run safe)
            GcsLogger::instance()->info(
                $this->dryRun ? 'Scheduler intent (dry-run)' : 'Scheduler intent',
                is_array($intent) ? $intent : ['intent' => $intent]
            );

            $adds++;
        }

        /*
         * Phase 13 return schema (summary-only)
         *
         * DiffPreviewer is responsible for normalizing this
         * into UI-friendly creates/updates/deletes arrays.
         */
        return [
            'adds'         => $adds,
            'updates'      => 0,
            'deletes'      => 0,
            'dryRun'       => $this->dryRun,
            'intents_seen' => $adds,
        ];
    }
}

/**
 * Compatibility alias
 *
 * Some legacy code refers to GcsSchedulerSync.
 */
class GcsSchedulerSync extends SchedulerSync {}
