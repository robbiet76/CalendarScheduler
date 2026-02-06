<?php
declare(strict_types=1);

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
