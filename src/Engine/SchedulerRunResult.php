<?php
declare(strict_types=1);

namespace CalendarScheduler\Engine;

use CalendarScheduler\Diff\DiffResult;
use CalendarScheduler\Diff\ReconciliationResult;
use CalendarScheduler\Diff\ReconciliationAction;

/**
 * SchedulerRunResult
 *
 * Canonical, immutable output of a full scheduler run.
 *
 * This object represents:
 *  - what the scheduler observed
 *  - what it planned
 *  - what actions would be taken
 *
 * HARD RULES:
 *  - No I/O
 *  - No side effects
 *  - No CLI or UI awareness
 *  - No provider-specific logic
 *
 * This is the single source of truth for:
 *  - noop detection
 *  - action counting
 *  - UI / CLI rendering
 */
final class SchedulerRunResult
{
    /** @var array<string,mixed> */
    private array $currentManifest;

    /** @var array<string,mixed> */
    private array $calendarManifest;

    /** @var array<string,mixed> */
    private array $fppManifest;

    private DiffResult $diffResult;

    private ReconciliationResult $reconciliationResult;

    private int $calendarSnapshotEpoch;

    private int $fppSnapshotEpoch;

    private \DateTimeImmutable $generatedAt;

    /**
     * @param array<string,mixed> $currentManifest
     * @param array<string,mixed> $calendarManifest
     * @param array<string,mixed> $fppManifest
     */
    public function __construct(
        array $currentManifest,
        array $calendarManifest,
        array $fppManifest,
        DiffResult $diffResult,
        ReconciliationResult $reconciliationResult,
        int $calendarSnapshotEpoch,
        int $fppSnapshotEpoch,
        ?\DateTimeImmutable $generatedAt = null
    ) {
        $this->currentManifest       = $currentManifest;
        $this->calendarManifest      = $calendarManifest;
        $this->fppManifest           = $fppManifest;
        $this->diffResult            = $diffResult;
        $this->reconciliationResult  = $reconciliationResult;
        $this->calendarSnapshotEpoch = $calendarSnapshotEpoch;
        $this->fppSnapshotEpoch      = $fppSnapshotEpoch;
        $this->generatedAt           = $generatedAt
            ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    // ---------------------------------------------------------------------
    // Raw accessors
    // ---------------------------------------------------------------------

    /** @return array<string,mixed> */
    public function currentManifest(): array
    {
        return $this->currentManifest;
    }

    /** @return array<string,mixed> */
    public function calendarManifest(): array
    {
        return $this->calendarManifest;
    }

    /** @return array<string,mixed> */
    public function fppManifest(): array
    {
        return $this->fppManifest;
    }

    public function diffResult(): DiffResult
    {
        return $this->diffResult;
    }

    public function reconciliationResult(): ReconciliationResult
    {
        return $this->reconciliationResult;
    }

    public function calendarSnapshotEpoch(): int
    {
        return $this->calendarSnapshotEpoch;
    }

    public function fppSnapshotEpoch(): int
    {
        return $this->fppSnapshotEpoch;
    }

    public function generatedAt(): \DateTimeImmutable
    {
        return $this->generatedAt;
    }

    // ---------------------------------------------------------------------
    // Action accessors
    // ---------------------------------------------------------------------

    /**
     * @return ReconciliationAction[]
     */
    public function actions(): array
    {
        return $this->reconciliationResult->actions();
    }

    public function actionCount(): int
    {
        return $this->reconciliationResult->actionCount();
    }

    // ---------------------------------------------------------------------
    // Derived semantics (single source of truth)
    // ---------------------------------------------------------------------

    /**
     * True if the run results in no executable changes.
     *
     * NOOP and BLOCK actions do not count as changes.
     */
    public function isNoop(): bool
    {
        foreach ($this->actions() as $action) {
            if (
                $action->type !== ReconciliationAction::TYPE_NOOP
                && $action->type !== ReconciliationAction::TYPE_BLOCK
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * True if any BLOCK actions are present.
     */
    public function hasBlockedActions(): bool
    {
        foreach ($this->actions() as $action) {
            if ($action->type === ReconciliationAction::TYPE_BLOCK) {
                return true;
            }
        }
        return false;
    }

    /**
     * Count executable actions by target and type.
     *
     * NOOP and BLOCK actions are excluded.
     *
     * @return array{
     *   fpp: array{create:int,update:int,delete:int},
     *   calendar: array{create:int,update:int,delete:int}
     * }
     */
    public function countsByTarget(): array
    {
        $out = [
            'fpp' => ['create' => 0, 'update' => 0, 'delete' => 0],
            'calendar' => ['create' => 0, 'update' => 0, 'delete' => 0],
        ];

        foreach ($this->actions() as $action) {
            if (
                $action->type === ReconciliationAction::TYPE_NOOP
                || $action->type === ReconciliationAction::TYPE_BLOCK
            ) {
                continue;
            }

            $target = $action->target;
            $type   = $action->type;

            if (isset($out[$target][$type])) {
                $out[$target][$type]++;
            }
        }

        return $out;
    }

    /**
     * Flat totals across all targets.
     *
     * @return array{create:int,update:int,delete:int}
     */
    public function totalCounts(): array
    {
        $totals = ['create' => 0, 'update' => 0, 'delete' => 0];

        foreach ($this->countsByTarget() as $targetCounts) {
            foreach ($totals as $k => $_) {
                $totals[$k] += $targetCounts[$k];
            }
        }

        return $totals;
    }
}