<?php
declare(strict_types=1);

namespace CalendarScheduler\Adapter\Calendar\Google;

use CalendarScheduler\Diff\ReconciliationAction;
use RuntimeException;

/**
 * GoogleMutation
 *
 * Immutable value object representing a single Google Calendar API mutation.
 */
final class GoogleMutation
{
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
    public readonly string $calendarId;
    public readonly ?string $googleEventId;
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
    ) {
        $this->op = $op;
        $this->calendarId = $calendarId;
        $this->googleEventId = $googleEventId;
        $this->manifestEventId = $manifestEventId;
        $this->subEventHash = $subEventHash;
    }
}

final class GoogleApplyExecutor
{
    private GoogleApiClient $client;
    private GoogleEventMapper $mapper;

    public function __construct(GoogleApiClient $client, GoogleEventMapper $mapper)
    {
        $this->client = $client;
        $this->mapper = $mapper;
    }

    /**
     * @param ReconciliationAction[] $actions
     * @return GoogleMutationResult[]
     */
    public function applyActions(array $actions): array
    {
        $mutations = [];
        foreach ($actions as $action) {
            $mapped = $this->mapper->mapAction($action, $this->client->getConfig());
            foreach ($mapped as $mutation) {
                $mutations[] = $mutation;
            }
        }

        $this->mapper->emitDiagnosticsSummary();
        $results = $this->apply($mutations);
        $this->client->emitDiagnosticsSummary();
        return $results;
    }

    /**
     * @param GoogleMutation[] $mutations
     * @return GoogleMutationResult[]
     */
    public function apply(array $mutations): array
    {
        $results = [];
        foreach ($mutations as $mutation) {
            $results[] = $this->applyOne($mutation);
        }
        return $results;
    }

    private function applyOne(GoogleMutation $mutation): GoogleMutationResult
    {
        switch ($mutation->op) {
            case GoogleMutation::OP_CREATE:
                $eventId = $this->client->createEvent($mutation->calendarId, $mutation->payload);
                return new GoogleMutationResult(
                    $mutation->op,
                    $mutation->calendarId,
                    $eventId,
                    $mutation->manifestEventId,
                    $mutation->subEventHash
                );

            case GoogleMutation::OP_UPDATE:
                $this->client->updateEvent($mutation->calendarId, $mutation->googleEventId, $mutation->payload);
                return new GoogleMutationResult(
                    $mutation->op,
                    $mutation->calendarId,
                    $mutation->googleEventId,
                    $mutation->manifestEventId,
                    $mutation->subEventHash
                );

            case GoogleMutation::OP_DELETE:
                $this->client->deleteEvent($mutation->calendarId, $mutation->googleEventId);
                return new GoogleMutationResult(
                    $mutation->op,
                    $mutation->calendarId,
                    $mutation->googleEventId,
                    $mutation->manifestEventId,
                    $mutation->subEventHash
                );

            default:
                throw new RuntimeException('GoogleApplyExecutor: unsupported mutation op ' . $mutation->op);
        }
    }
}
