<?php
declare(strict_types=1);

namespace CalendarScheduler\Apply;

use CalendarScheduler\Diff\ReconciliationAction;
use CalendarScheduler\Diff\ReconciliationResult;
use RuntimeException;

/**
 * ApplyRunner
 *
 * The ONLY layer allowed to mutate external state.
 *
 * Current policy:
 * - Calendar is NOT writable yet (no OAuth / write-back).
 * - FPP writes are not implemented yet in Apply.
 *
 * Therefore:
 * - --dry-run reports executable vs preview-only actions separately
 * - --apply refuses if any non-noop actions exist (to avoid writing a "future" manifest)
 *   until actual writer(s) are implemented.
 */
final class ApplyRunner
{
    public function __construct(
        private readonly ManifestWriter $manifestWriter
    ) {}

    /**
     * Produce a capability-aware summary of reconciliation actions.
     *
     * @return array{
     *   executable: array{fpp: array{create:int,update:int,delete:int}},
     *   previewOnly: array{calendar: array{create:int,update:int,delete:int}},
     *   blocked: array<int,string>
     * }
     */
    public function summarize(ReconciliationResult $result): array
    {
        $exec = ['fpp' => ['create' => 0, 'update' => 0, 'delete' => 0]];
        $prev = ['calendar' => ['create' => 0, 'update' => 0, 'delete' => 0]];
        $blocked = [];

        foreach ($result->actions() as $a) {
            if ($a->type === ReconciliationAction::TYPE_NOOP) {
                continue;
            }

            if (!in_array($a->type, [
                ReconciliationAction::TYPE_CREATE,
                ReconciliationAction::TYPE_UPDATE,
                ReconciliationAction::TYPE_DELETE,
                ReconciliationAction::TYPE_BLOCK,
            ], true)) {
                $blocked[] = "Unknown action type '{$a->type}' for identity {$a->identityHash}";
                continue;
            }

            if ($a->type === ReconciliationAction::TYPE_BLOCK) {
                $blocked[] = "Blocked: {$a->identityHash} ({$a->reason})";
                continue;
            }

            if ($a->target === ReconciliationAction::TARGET_FPP) {
                $exec['fpp'][$a->type]++;
                continue;
            }

            if ($a->target === ReconciliationAction::TARGET_CALENDAR) {
                // calendar writeback not supported yet: preview-only
                $prev['calendar'][$a->type]++;
                continue;
            }

            $blocked[] = "Unknown target '{$a->target}' for identity {$a->identityHash}";
        }

        return [
            'executable' => $exec,
            'previewOnly' => $prev,
            'blocked' => $blocked,
        ];
    }

    /**
     * Apply reconciliation result.
     *
     * Current phase policy:
     * - Refuse to apply if ANY non-noop actions exist, because we cannot mutate sources yet.
     * - This prevents writing a manifest that does not reflect reality.
     */
    public function apply(ReconciliationResult $result): void
    {
        $summary = $this->summarize($result);

        if ($summary['blocked'] !== []) {
            throw new RuntimeException('Apply blocked: ' . implode('; ', $summary['blocked']));
        }

        $execCounts = $summary['executable']['fpp'];
        $prevCounts = $summary['previewOnly']['calendar'];

        $hasExecutable = ($execCounts['create'] + $execCounts['update'] + $execCounts['delete']) > 0;
        $hasPreview = ($prevCounts['create'] + $prevCounts['update'] + $prevCounts['delete']) > 0;

        if ($hasPreview) {
            throw new RuntimeException(
                'Apply refused: calendar write-back is not supported yet (preview-only actions exist)'
            );
        }

        if ($hasExecutable) {
            throw new RuntimeException(
                'Apply refused: FPP Apply is not implemented yet (executable actions exist)'
            );
        }

        // No actions to apply => safe to persist "current state" manifest (idempotent).
        $this->manifestWriter->applyTargetManifest($result->targetManifest());
    }
}