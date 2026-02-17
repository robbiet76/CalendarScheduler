<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Adapter/Calendar/Google/GoogleMutationResult.php
 * Purpose: Capture the result metadata for one Google Calendar mutation so
 * apply logs and diagnostics can correlate changes back to manifest identities.
 */

namespace CalendarScheduler\Adapter\Calendar\Google;

final class GoogleMutationResult
{
    // Mutation operation and target calendar context.
    public string $op;
    public string $calendarId;

    // Provider and manifest identity linkage for traceability.
    public ?string $googleEventId;
    public string $manifestEventId;
    public string $subEventHash;

    public function __construct(
        string $op,
        string $calendarId,
        ?string $googleEventId,
        string $manifestEventId,
        string $subEventHash
    )
    {
        $this->op = $op;
        $this->calendarId = $calendarId;
        $this->googleEventId = $googleEventId;
        $this->manifestEventId = $manifestEventId;
        $this->subEventHash = $subEventHash;
    }
}
