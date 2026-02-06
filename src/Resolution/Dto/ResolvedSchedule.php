<?php
declare(strict_types=1);

namespace CalendarScheduler\Resolution\Dto;

/**
 * Top-level output of Resolution.
 *
 * @psalm-type ResolvedBundles = list<ResolvedBundle>
 */
final class ResolvedSchedule
{
    /** @var ResolvedBundle[] */
    private array $bundles;

    /**
     * @param ResolvedBundle[] $bundles
     */
    public function __construct(array $bundles)
    {
        $this->bundles = $bundles;
    }

    /**
     * @return ResolvedBundle[]
     */
    public function getBundles(): array
    {
        return $this->bundles;
    }
}
