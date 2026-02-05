<?php
declare(strict_types=1);

namespace CalendarScheduler\Diff;

final class ReconciliationAction
{
    private string $op;
    private $event;
    private ?string $googleEventId;

    // Existing constructor and other methods...

    public function getOp(): string
    {
        return $this->op;
    }

    public function getEvent()
    {
        return $this->event;
    }

    public function getGoogleEventId(): ?string
    {
        return $this->googleEventId ?? null;
    }
}
