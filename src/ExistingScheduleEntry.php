<?php

final class GcsExistingScheduleEntry
{
    /** @var array<string,mixed> */
    private array $raw;

    /**
     * @param array<string,mixed> $raw
     */
    public function __construct(array $raw)
    {
        $this->raw = $raw;
    }

    /**
     * Extract canonical GCS identity key for this entry.
     *
     * Phase 17.2:
     * - Prefer canonical v1 args tag (uid+range+days)
     * - Legacy tag field supported for uid-only entries
     */
    public function getGcsKey(): ?string
    {
        return GcsSchedulerIdentity::extractKey($this->raw);
    }

    /**
     * Extract GCS UID (best-effort).
     *
     * Kept for compatibility with older code paths that only care about UID.
     */
    public function getGcsUid(): ?string
    {
        return GcsSchedulerIdentity::extractUid($this->raw);
    }

    /**
     * @return array<string,mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
