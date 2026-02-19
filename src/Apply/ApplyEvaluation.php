<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Apply/ApplyEvaluation.php
 * Purpose: Hold reconciliation actions grouped by apply policy outcome
 * (allowed, blocked, and no-op) for apply-stage execution decisions.
 */

namespace CalendarScheduler\Apply;

use CalendarScheduler\Diff\ReconciliationAction;

/**
 * ApplyEvaluation
 *
 * Result of evaluating reconciliation actions against ApplyOptions.
 */
final class ApplyEvaluation
{
    // Actions permitted by current ApplyOptions.
    /** @var ReconciliationAction[] */
    public array $allowed = [];

    // Actions rejected by target writability or mode constraints.
    /** @var ReconciliationAction[] */
    public array $blocked = [];

    // Actions intentionally ignored as operational no-ops.
    /** @var ReconciliationAction[] */
    public array $noops = [];
}
