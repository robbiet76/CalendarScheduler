<?php
declare(strict_types=1);

namespace CalendarScheduler\Apply;

use CalendarScheduler\Diff\ReconciliationAction;

/**
 * ApplyEvaluation
 *
 * Result of evaluating reconciliation actions against ApplyOptions.
 */
final class ApplyEvaluation
{
    /** @var ReconciliationAction[] */
    public array $allowed = [];

    /** @var ReconciliationAction[] */
    public array $blocked = [];

    /** @var ReconciliationAction[] */
    public array $noops = [];
}