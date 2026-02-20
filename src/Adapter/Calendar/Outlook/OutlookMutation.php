<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Adapter/Calendar/Outlook/OutlookMutation.php
 * Purpose: Immutable value object representing a single Outlook mutation.
 */

namespace CalendarScheduler\Adapter\Calendar\Outlook;

use RuntimeException;

final class OutlookMutation
{
    private const VALID_OPS = [
        self::OP_CREATE,
        self::OP_UPDATE,
        self::OP_DELETE,
    ];

    public const OP_CREATE = 'create';
    public const OP_UPDATE = 'update';
    public const OP_DELETE = 'delete';

    public readonly string $op;
    public readonly string $calendarId;
    public readonly ?string $outlookEventId;
    /** @var array<string,mixed> */
    public readonly array $payload;
    public readonly string $manifestEventId;
    public readonly string $subEventHash;

    /**
     * @param self::OP_* $op
     * @param array<string,mixed> $payload
     */
    public function __construct(
        string $op,
        string $calendarId,
        ?string $outlookEventId,
        array $payload,
        string $manifestEventId,
        string $subEventHash
    ) {
        if (!in_array($op, self::VALID_OPS, true)) {
            throw new RuntimeException("Invalid OutlookMutation op '{$op}'");
        }
        if ($calendarId === '') {
            throw new RuntimeException('OutlookMutation requires non-empty calendarId');
        }
        if (($op === self::OP_UPDATE || $op === self::OP_DELETE) && ($outlookEventId === null || $outlookEventId === '')) {
            throw new RuntimeException("OutlookMutation '{$op}' requires outlookEventId");
        }
        if ($op === self::OP_DELETE && $payload !== []) {
            throw new RuntimeException('OutlookMutation delete must not include payload');
        }
        if ($manifestEventId === '') {
            throw new RuntimeException('OutlookMutation requires manifestEventId');
        }
        if ($subEventHash === '') {
            throw new RuntimeException('OutlookMutation requires subEventHash');
        }

        $this->op = $op;
        $this->calendarId = $calendarId;
        $this->outlookEventId = $outlookEventId;
        $this->payload = $payload;
        $this->manifestEventId = $manifestEventId;
        $this->subEventHash = $subEventHash;
    }
}
