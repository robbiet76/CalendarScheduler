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
        $this->bundles = $this->sortBundles($bundles);
    }

    /**
     * @return ResolvedBundle[]
     */
    public function getBundles(): array
    {
        return $this->bundles;
    }

    /**
     * Ensure deterministic ordering of bundles for diffing / hashing.
     *
     * @param ResolvedBundle[] $bundles
     * @return ResolvedBundle[]
     */
    private function sortBundles(array $bundles): array
    {
        usort(
            $bundles,
            function (ResolvedBundle $a, ResolvedBundle $b): int {
                $aStart = $a->getSegmentScope()->getStart()->getTimestamp();
                $bStart = $b->getSegmentScope()->getStart()->getTimestamp();
                if ($aStart !== $bStart) {
                    return $aStart <=> $bStart;
                }

                $aEnd = $a->getSegmentScope()->getEnd()->getTimestamp();
                $bEnd = $b->getSegmentScope()->getEnd()->getTimestamp();
                if ($aEnd !== $bEnd) {
                    return $aEnd <=> $bEnd;
                }

                if ($a->getParentUid() !== $b->getParentUid()) {
                    return strcmp($a->getParentUid(), $b->getParentUid());
                }

                return strcmp($a->getSourceEventUid(), $b->getSourceEventUid());
            }
        );

        return $bundles;
    }
}
