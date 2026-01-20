<?php
declare(strict_types=1);

namespace GCS\Planner;

/**
 * ManifestPlanner
 *
 * Compatibility shim within v2 branch naming.
 * Internally delegates to Planner (Phase 2.2).
 */
final class ManifestPlanner
{
    private Planner $planner;

    public function __construct(?Planner $planner = null)
    {
        $this->planner = $planner ?? new Planner();
    }

    public function plan(array $manifest): PlannerResult
    {
        return $this->planner->plan($manifest);
    }
}

