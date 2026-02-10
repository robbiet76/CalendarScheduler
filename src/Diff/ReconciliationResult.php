<?php

declare(strict_types=1);

namespace CalendarScheduler\Diff;

/**
 * Phase 4 — ReconciliationResult
 *
 * - targetManifest is the merged target state after applying authority rules.
 * - actions is a deterministic list of directional operations needed to converge sources.
 *
 * NOTE:
 * - TYPE_NOOP actions are informational only and must never cause IO.
 * - TYPE_BLOCK actions represent explicit policy denial and must never be executed.
 * - Apply layer must only execute actions returned by executableActions().
 * - providerState mutation is NOT handled here; it must be reflected in targetManifest upstream.
 */
final class ReconciliationResult
{
    /** @var array<string,mixed> */
    private array $targetManifest;

    /** @var array<int,ReconciliationAction> */
    private array $actions;

    /**
     * @param array<string,mixed> $targetManifest
     * @param array<int,ReconciliationAction> $actions
     */
    public function __construct(array $targetManifest, array $actions)
    {
        $this->targetManifest = $targetManifest;

        // deterministic ordering (identityHash, then target, then type)
        usort($actions, static function (ReconciliationAction $a, ReconciliationAction $b): int {
            $k1 = $a->identityHash . '|' . $a->target . '|' . $a->type;
            $k2 = $b->identityHash . '|' . $b->target . '|' . $b->type;
            return $k1 <=> $k2;
        });

        $this->actions = array_values($actions);
    }

    /** @return array<string,mixed> */
    public function targetManifest(): array
    {
        return $this->targetManifest;
    }

    /** @return array<int,ReconciliationAction> */
    public function actions(): array
    {
        return $this->actions;
    }

    /**
     * @return array<int,ReconciliationAction>
     * Actions that are eligible for execution by the Apply layer.
     * Excludes NOOP and BLOCK actions.
     *
     * These three sets (executableActions, blockedActions, noopActions) are disjoint and together cover all actions.
     */
    public function executableActions(): array
    {
        return array_values(array_filter(
            $this->actions,
            static fn (ReconciliationAction $a): bool =>
                $a->type !== ReconciliationAction::TYPE_NOOP
                && $a->type !== ReconciliationAction::TYPE_BLOCK
        ));
    }

    /**
     * @return array<int,ReconciliationAction>
     * Actions that are explicitly blocked by reconciliation policy.
     * These must never be executed and are provided for diagnostics only.
     *
     * These three sets (executableActions, blockedActions, noopActions) are disjoint and together cover all actions.
     */
    public function blockedActions(): array
    {
        return array_values(array_filter(
            $this->actions,
            static fn (ReconciliationAction $a): bool =>
                $a->type === ReconciliationAction::TYPE_BLOCK
        ));
    }

    /**
     * @return array<int,ReconciliationAction>
     * Actions that are informational only and must never cause IO.
     *
     * These three sets (executableActions, blockedActions, noopActions) are disjoint and together cover all actions.
     */
    public function noopActions(): array
    {
        return array_values(array_filter(
            $this->actions,
            static fn (ReconciliationAction $a): bool =>
                $a->type === ReconciliationAction::TYPE_NOOP
        ));
    }

    /**
     * @param string $target
     * @return array<int,ReconciliationAction>
     * All actions (including NOOP / BLOCK) scoped to a single target.
     */
    public function actionsForTarget(string $target): array
    {
        return array_values(array_filter(
            $this->actions,
            static fn (ReconciliationAction $a): bool =>
                $a->target === $target
        ));
    }

    public function actionCount(): int
    {
        return count($this->actions);
    }

    public function countByTarget(string $target): int
    {
        $n = 0;
        foreach ($this->actions as $a) {
            if ($a->target === $target && $a->type !== ReconciliationAction::TYPE_NOOP) {
                $n++;
            }
        }
        return $n;
    }

    public function countByType(string $type): int
    {
        $n = 0;
        foreach ($this->actions as $a) {
            if ($a->type === $type) {
                $n++;
            }
        }
        return $n;
    }
}
