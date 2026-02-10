<?php
declare(strict_types=1);

namespace CalendarScheduler\Adapter\Calendar\Google;

use RuntimeException;

/**
 * GoogleMutation
 *
 * Immutable value object representing a single Google Calendar API mutation.
 *
 * This is the ONLY object that GoogleApplyExecutor is allowed to execute.
 *
 * Invariants:
 * - One mutation == one Google API call
 * - Fully resolved (no symbolic time, no recurrence rules)
 * - No side effects, no I/O
 * - Safe to serialize, log, diff, or replay
 */
final class GoogleMutation
{
    /**
     * List of valid operations for GoogleMutation.
     */
    private const VALID_OPS = [
        self::OP_CREATE,
        self::OP_UPDATE,
        self::OP_DELETE,
    ];
    public const OP_CREATE = 'create';
    public const OP_UPDATE = 'update';
    public const OP_DELETE = 'delete';

    /** @var self::OP_* */
    public readonly string $op;

    /** @var string */
    public readonly string $calendarId;

    /** @var string|null Google eventId (required for update/delete) */
    public readonly ?string $googleEventId;

    /** @var array<string,mixed> Google API payload (empty for delete) */
    public readonly array $payload;

    /** @var string Manifest-level identityHash */
    public readonly string $manifestEventId;

    /** @var string SubEvent identityHash */
    public readonly string $subEventHash;

    /**
     * @param self::OP_* $op
     * @param string $calendarId
     * @param string|null $googleEventId
     * @param array<string,mixed> $payload
     * @param string $manifestEventId
     * @param string $subEventHash
     */
    public function __construct(
        string $op,
        string $calendarId,
        ?string $googleEventId,
        array $payload,
        string $manifestEventId,
        string $subEventHash
    ) {
        if (!in_array($op, self::VALID_OPS, true)) {
            throw new RuntimeException("Invalid GoogleMutation op '{$op}'");
        }

        if ($calendarId === '') {
            throw new RuntimeException('GoogleMutation requires non-empty calendarId');
        }

        if (
            ($op === self::OP_UPDATE || $op === self::OP_DELETE)
            && ($googleEventId === null || $googleEventId === '')
        ) {
            throw new RuntimeException("GoogleMutation '{$op}' requires googleEventId");
        }

        if ($op === self::OP_DELETE && $payload !== []) {
            throw new RuntimeException('GoogleMutation delete must not include payload');
        }

        if ($manifestEventId === '') {
            throw new RuntimeException('GoogleMutation requires manifestEventId');
        }

        if ($subEventHash === '') {
            throw new RuntimeException('GoogleMutation requires subEventHash');
        }

        $this->op = $op;
        $this->calendarId = $calendarId;
        $this->googleEventId = $googleEventId;
        $this->payload = $payload;
        $this->manifestEventId = $manifestEventId;
        $this->subEventHash = $subEventHash;
    }
}