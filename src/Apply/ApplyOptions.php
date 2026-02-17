<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Apply/ApplyOptions.php
 * Purpose: Encode apply-mode policy, target writability, and blocked-action
 * behavior used to evaluate and execute reconciliation actions.
 */

namespace CalendarScheduler\Apply;

use CalendarScheduler\Diff\ReconciliationAction;
use CalendarScheduler\Apply\ApplyTargets;

/**
 * ApplyOptions
 *
 * Immutable policy object controlling apply behavior.
 */
final class ApplyOptions
{
    // Operational modes for preview versus write execution.
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
        // Persist immutable apply policy state.
        $this->mode = $mode;
        $this->dryRun = $dryRun;
        $this->writableTargets = $writableTargets;
        $this->failOnBlockedActions = $failOnBlockedActions;
    }

    // ----------------------------
    // Named constructors
    // ----------------------------

    public static function plan(array $writableTargets = []): self
    {
        return new self(
            self::MODE_PLAN,
            false,
            self::normalizeTargets($writableTargets),
            false
        );
    }

    public static function dryRun(array $writableTargets): self
    {
        return new self(
            self::MODE_APPLY,
            true,
            self::normalizeTargets($writableTargets),
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
            self::normalizeTargets($writableTargets),
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
        // Enforce target validity before checking writability.
        if (!ApplyTargets::isValid($target)) {
            throw new \RuntimeException(
                "ApplyOptions::canWrite called with invalid target '{$target}'"
            );
        }

        return ($this->writableTargets[$target] ?? false) === true;
    }

    /**
     * @param array<int,string> $targets
     * @return array<string,bool>
     */
    private static function normalizeTargets(array $targets): array
    {
        // Normalize target list into a fast lookup map with validation.
        $out = [];
        foreach ($targets as $t) {
            if (!is_string($t) || $t === '') {
                continue;
            }
            if (!ApplyTargets::isValid($t)) {
                throw new \RuntimeException("ApplyOptions: invalid writable target '{$t}'");
            }
            $out[$t] = true;
        }
        return $out;
    }
}
