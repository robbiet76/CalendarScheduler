<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Resolution;

final class CalendarManifestResolver implements EventResolver
{
    public function resolve(ResolutionInputs $inputs): ResolutionResult
    {
        $result = new ResolutionResult($inputs->policy, $inputs->context);

        $allHashes = array_unique(array_merge(
            array_keys($inputs->sourceByHash),
            array_keys($inputs->existingByHash)
        ));
        sort($allHashes);

        foreach ($allHashes as $hash) {
            $src = $inputs->sourceByHash[$hash] ?? null;
            $dst = $inputs->existingByHash[$hash] ?? null;

            // 1) In source but not existing → CREATE
            if ($src !== null && $dst === null) {
                $result->add(new ResolutionOperation(
                    ResolutionOperation::CREATE,
                    $hash,
                    'Present in source, missing in existing manifest'
                ));
                continue;
            }

            // 2) In existing but not source → DELETE
            if ($src === null && $dst !== null) {
                $result->add(new ResolutionOperation(
                    ResolutionOperation::DELETE,
                    $hash,
                    'Missing in source, present in existing manifest'
                ));
                continue;
            }

            // 3) In both → compare
            if ($src === null || $dst === null) {
                // Should be impossible due to above guards
                continue;
            }

            $divergent = $this->isDivergent($src, $dst);

            if (!$divergent) {
                $result->add(new ResolutionOperation(
                    ResolutionOperation::NOOP,
                    $hash,
                    'Source and existing are structurally identical'
                ));
                continue;
            }

            if ($dst->isLocked()) {
                $result->add(new ResolutionOperation(
                    ResolutionOperation::CONFLICT,
                    $hash,
                    'Existing is locked and differs from source'
                ));
                continue;
            }

            $result->add(new ResolutionOperation(
                ResolutionOperation::UPDATE,
                $hash,
                'Existing differs from source'
            ));
        }

        return $result;
    }

    private function isDivergent(ResolvableEvent $a, ResolvableEvent $b): bool
    {
        // Divergence detection is structural, not semantic:
        // - subEvents count
        // - timing
        // - behavior
        // - payload
        // Identity/ownership/correlation are not used to decide divergence.

        $aSub = $a->subEvents;
        $bSub = $b->subEvents;

        if (count($aSub) !== count($bSub)) {
            return true;
        }

        // Compare subEvents pairwise by index (manifest order is meaningful)
        $n = count($aSub);
        for ($i = 0; $i < $n; $i++) {
            $as = is_array($aSub[$i] ?? null) ? $aSub[$i] : [];
            $bs = is_array($bSub[$i] ?? null) ? $bSub[$i] : [];

            if ($this->canon($as['timing'] ?? null)   !== $this->canon($bs['timing'] ?? null))   return true;
            if ($this->canon($as['behavior'] ?? null) !== $this->canon($bs['behavior'] ?? null)) return true;
            if ($this->canon($as['payload'] ?? null)  !== $this->canon($bs['payload'] ?? null))  return true;
        }

        return false;
    }

    private function canon($v): string
    {
        if ($v === null) return 'null';
        if (!is_array($v)) return json_encode($v);

        $normalized = $this->ksortRecursive($v);
        return json_encode($normalized);
    }

    private function ksortRecursive(array $a): array
    {
        foreach ($a as $k => $v) {
            if (is_array($v)) {
                $a[$k] = $this->ksortRecursive($v);
            }
        }

        // Only sort associative arrays; keep numeric order intact
        if ($this->isAssoc($a)) {
            ksort($a);
        }
        return $a;
    }

    private function isAssoc(array $a): bool
    {
        $i = 0;
        foreach (array_keys($a) as $k) {
            if ($k !== $i) return true;
            $i++;
        }
        return false;
    }
}