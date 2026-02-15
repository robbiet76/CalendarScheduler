<?php
declare(strict_types=1);

namespace CalendarScheduler\Adapter\Calendar\Google;

final class GoogleMutationResult
{
    public string $op;
    public string $calendarId;
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
