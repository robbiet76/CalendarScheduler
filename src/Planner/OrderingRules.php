<?php
declare(strict_types=1);

namespace GCS\Planner;

/**
 * Managed ordering only.
 *
 * Unmanaged ordering (unmanaged block at top, stable order preserved) is handled later
 * during reconciliation/apply when unmanaged inventory is merged above managed output.
 */
final class OrderingRules
{
    public static function managedOrderKey(string $eventId, int $subEventIndex, string $identityId): string
    {
        $idx = str_pad((string)$subEventIndex, 6, '0', STR_PAD_LEFT);
        return "{$eventId}|{$idx}|{$identityId}";
    }
}
