<?php

declare(strict_types=1);

namespace CalendarScheduler\Diff;

/**
 * Phase 4 — ReconciliationResult
 *
 * - targetManifest is the merged target state after applying authority rules.
 * - actions is a deterministic list of directional operations needed to converge sources.
 *
 * NOTE: Apply phase (later) will translate these actions into actual IO via adapters.
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
