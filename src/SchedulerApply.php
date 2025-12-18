<?php

class SchedulerApply
{
    public static function dryRun(SchedulerDiffResult $diff): void
    {
        foreach ($diff->create as $entry) {
            Logger::info('[DRY-RUN] CREATE', ['uid' => $entry->uid]);
        }

        foreach ($diff->update as $uid => $pair) {
            Logger::info('[DRY-RUN] UPDATE', ['uid' => $uid]);
        }

        foreach ($diff->delete as $entry) {
            Logger::info('[DRY-RUN] DELETE', ['uid' => $entry->uid]);
        }
    }

    public static function execute(SchedulerDiffResult $diff): void
    {
        if (!Settings::schedulerApplyEnabled()) {
            Logger::warn('Scheduler apply disabled — skipping writes');
            return;
        }

        // DELETE
        foreach ($diff->delete as $entry) {
            // TODO: call FPP scheduler delete API
            Logger::info('DELETE applied', ['uid' => $entry->uid]);
        }

        // UPDATE
        foreach ($diff->update as $uid => $pair) {
            // TODO: call FPP scheduler update API
            Logger::info('UPDATE applied', ['uid' => $uid]);
        }

        // CREATE
        foreach ($diff->create as $entry) {
            // TODO: call FPP scheduler create API
            Logger::info('CREATE applied', ['uid' => $entry->uid]);
        }
    }
}
