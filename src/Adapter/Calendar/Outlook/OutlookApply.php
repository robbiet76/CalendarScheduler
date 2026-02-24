<?php
declare(strict_types=1);

namespace CalendarScheduler\Adapter\Calendar\Outlook;

use CalendarScheduler\Diff\ReconciliationAction;
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

final class OutlookApplyExecutor
{
    private OutlookApiClient $client;
    private OutlookEventMapper $mapper;

    public function __construct(OutlookApiClient $client, OutlookEventMapper $mapper)
    {
        $this->client = $client;
        $this->mapper = $mapper;
    }

    /**
     * @param ReconciliationAction[] $actions
     * @return OutlookMutationResult[]
     */
    public function applyActions(array $actions): array
    {
        $mutations = [];
        foreach ($actions as $action) {
            foreach ($this->mapper->mapAction($action, $this->client->getConfig()) as $mutation) {
                $mutations[] = $mutation;
            }
        }

        $this->mapper->emitDiagnosticsSummary();
        $results = $this->apply($mutations);
        $this->client->emitDiagnosticsSummary();
        return $results;
    }

    /**
     * @param OutlookMutation[] $mutations
     * @return OutlookMutationResult[]
     */
    public function apply(array $mutations): array
    {
        $results = [];
        foreach ($mutations as $mutation) {
            $results[] = $this->applyOne($mutation);
        }
        return $results;
    }

    private function applyOne(OutlookMutation $mutation): OutlookMutationResult
    {
        switch ($mutation->op) {
            case OutlookMutation::OP_CREATE:
                $eventId = $this->client->createEvent($mutation->calendarId, $mutation->payload);
                return new OutlookMutationResult(
                    $mutation->op,
                    $mutation->calendarId,
                    $eventId,
                    $mutation->manifestEventId,
                    $mutation->subEventHash
                );

            case OutlookMutation::OP_UPDATE:
                $this->client->updateEvent($mutation->calendarId, (string)$mutation->outlookEventId, $mutation->payload);
                return new OutlookMutationResult(
                    $mutation->op,
                    $mutation->calendarId,
                    $mutation->outlookEventId,
                    $mutation->manifestEventId,
                    $mutation->subEventHash
                );

            case OutlookMutation::OP_DELETE:
                $this->client->deleteEvent($mutation->calendarId, (string)$mutation->outlookEventId);
                return new OutlookMutationResult(
                    $mutation->op,
                    $mutation->calendarId,
                    $mutation->outlookEventId,
                    $mutation->manifestEventId,
                    $mutation->subEventHash
                );

            default:
                throw new RuntimeException('OutlookApplyExecutor: unsupported mutation op ' . $mutation->op);
        }
    }
}
