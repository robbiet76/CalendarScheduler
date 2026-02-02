<?php
declare(strict_types=1);

namespace CalendarScheduler\Apply;

use RuntimeException;

/**
 * ApplyRunner
 *
 * The ONLY layer allowed to mutate external state.
 *
 * Phase D1: Persist target manifest as the new canonical manifest.
 * Future: Apply reconciliation actions to FPP and Calendar.
 */
final class ApplyRunner
{
    public function __construct(
        private readonly string $manifestPath
    ) {}

    /**
     * @param array<string,mixed> $targetManifest
     */
    public function applyManifest(array $targetManifest): void
    {
        $dir = dirname($this->manifestPath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('Failed to create manifest directory: ' . $dir);
            }
        }

        $json = json_encode($targetManifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        $tmp = $this->manifestPath . '.tmp';
        if (file_put_contents($tmp, $json) === false) {
            throw new RuntimeException('Failed to write manifest temp file: ' . $tmp);
        }

        // Atomic replace
        if (!rename($tmp, $this->manifestPath)) {
            @unlink($tmp);
            throw new RuntimeException('Failed to replace manifest: ' . $this->manifestPath);
        }
    }
}