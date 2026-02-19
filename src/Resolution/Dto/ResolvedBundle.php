<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Resolution/Dto/ResolvedBundle.php
 * Purpose: Defines the ResolvedBundle component used by the Calendar Scheduler Resolution/Dto layer.
 */

namespace CalendarScheduler\Resolution\Dto;

/**
 * A bundle is an atomic ordered group of subevents that FPP evaluates top-down.
 * Overrides sit above base; base is always last.
 */
final class ResolvedBundle
{
    private string $bundleUid;
    private string $sourceEventUid;
    private string $parentUid;
    private ResolutionScope $segmentScope;

    /** @var ResolvedSubevent[] */
    private array $subevents;

    /**
     * @param ResolvedSubevent[] $subevents ordered top-down (most specific first), base last.
     */
    public function __construct(
        string $bundleUid,
        string $sourceEventUid,
        string $parentUid,
        ResolutionScope $segmentScope,
        array $subevents
    ) {
        $this->bundleUid = $bundleUid;
        $this->sourceEventUid = $sourceEventUid;
        $this->parentUid = $parentUid;
        $this->segmentScope = $segmentScope;
        $this->subevents = $subevents;
    }

    public function getBundleUid(): string
    {
        return $this->bundleUid;
    }

    public function getSourceEventUid(): string
    {
        return $this->sourceEventUid;
    }

    public function getParentUid(): string
    {
        return $this->parentUid;
    }

    public function getSegmentScope(): ResolutionScope
    {
        return $this->segmentScope;
    }

    /**
     * @return ResolvedSubevent[]
     */
    public function getSubevents(): array
    {
        return $this->subevents;
    }

    /**
     * Base subevent (always last in evaluation order).
     */
    public function getBaseSubevent(): ResolvedSubevent
    {
        return $this->subevents[count($this->subevents) - 1];
    }

    /**
     * @return ResolvedSubevent[] override subevents only (top-down order)
     */
    public function getOverrides(): array
    {
        if (count($this->subevents) <= 1) {
            return [];
        }

        return array_slice($this->subevents, 0, -1);
    }

    /**
     * Canonical override signature for bundle comparison.
     *
     * This exposes override subevents in their evaluation order and is used
     * by Resolution to determine whether adjacent bundles can be safely merged.
     *
     * @return ResolvedSubevent[]
     */
    public function getOverrideSignature(): array
    {
        return $this->getOverrides();
    }
}
