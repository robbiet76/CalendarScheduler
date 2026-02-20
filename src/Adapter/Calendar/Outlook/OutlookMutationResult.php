<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Adapter/Calendar/Outlook/OutlookMutationResult.php
 * Purpose: Capture result metadata for one Outlook mutation.
 */

namespace CalendarScheduler\Adapter\Calendar\Outlook;

final class OutlookMutationResult
{
    public string $op;
    public string $calendarId;
    public ?string $outlookEventId;
    public string $manifestEventId;
    public string $subEventHash;

    public function __construct(
        string $op,
        string $calendarId,
        ?string $outlookEventId,
        string $manifestEventId,
        string $subEventHash
    ) {
        $this->op = $op;
        $this->calendarId = $calendarId;
        $this->outlookEventId = $outlookEventId;
        $this->manifestEventId = $manifestEventId;
        $this->subEventHash = $subEventHash;
    }
}
