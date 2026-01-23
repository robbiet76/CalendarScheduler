<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Resolution;

interface EventResolver
{
    public function resolve(ResolutionInputs $inputs): ResolutionResult;
}