<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Resolution/Dto/ResolutionRole.php
 * Purpose: Defines the ResolutionRole component used by the Calendar Scheduler Resolution/Dto layer.
 */

namespace CalendarScheduler\Resolution\Dto;

/**
 * Avoid PHP enums for maximum runtime compatibility.
 */
final class ResolutionRole
{
    public const BASE = 'base';
    public const OVERRIDE = 'override';

    private function __construct()
    {
        // static only
    }
}
