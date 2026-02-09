<?php
declare(strict_types=1);

namespace CalendarScheduler\Apply;

use CalendarScheduler\Diff\ReconciliationAction;

/**
 * ApplyOptions
 *
 * Immutable policy object controlling apply behavior.
 */
final class ApplyOptions
{
    public const MODE_PLAN = 'plan';
    public const MODE_APPLY = 'apply';

    /** @var string */
    private string $mode;

    /** @var bool */
    private bool $dryRun;

    /** @var array<string,bool> */
    private array $writableTargets;

    public bool $failOnBlockedActions;

    private function __construct(
        string $mode,
        bool $dryRun,
        array $writableTargets,
        bool $failOnBlockedActions
    ) {
        $this->mode = $mode;
        $this->dryRun = $dryRun;
        $this->writableTargets = $writableTargets;
        $this->failOnBlockedActions = $failOnBlockedActions;
    }

    // ----------------------------
    // Named constructors
    // ----------------------------

    public static function plan(): self
    {
        return new self(
            self::MODE_PLAN,
            false,
            [],
            false
        );
    }

    public static function dryRun(array $writableTargets): self
    {
        return new self(
            self::MODE_APPLY,
            true,
            $writableTargets,
            false
        );
    }

    public static function apply(
        array $writableTargets,
        bool $failOnBlockedActions = true
    ): self {
        return new self(
            self::MODE_APPLY,
            false,
            $writableTargets,
            $failOnBlockedActions
        );
    }

    // ----------------------------
    // Introspection
    // ----------------------------

    public function isPlan(): bool
    {
        return $this->mode === self::MODE_PLAN;
    }

    public function isApply(): bool
    {
        return $this->mode === self::MODE_APPLY;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function canWrite(string $target): bool
    {
        return ($this->writableTargets[$target] ?? false) === true;
    }
}