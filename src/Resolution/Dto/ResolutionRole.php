<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Resolution/Dto/ResolutionRole.php
 * Purpose: Define canonical role labels used to distinguish base and override
 * resolved subevents throughout the resolution and planning pipeline.
 */

namespace CalendarScheduler\Resolution\Dto;

/**
 * Avoid PHP enums for maximum runtime compatibility.
 */
final class ResolutionRole
{
    // Base schedule segment emitted from a source event.
    public const BASE = 'base';

    // Override segment emitted from an override definition.
    public const OVERRIDE = 'override';

    private function __construct()
    {
        // static only
    }
}
