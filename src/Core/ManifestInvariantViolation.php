<?php
declare(strict_types=1);

namespace GCS\Core\Exception;

/**
 * Thrown when a Manifest invariant is violated.
 *
 * This indicates a programming or data integrity error.
 * It must never be silently handled.
 */
class ManifestInvariantViolation extends \RuntimeException
{
    /* -----------------------------------------------------------------
     * Identity Invariants
     * ----------------------------------------------------------------- */
    public const IDENTITY_MISSING      = 'identity_missing';
    public const IDENTITY_INCOMPLETE   = 'identity_incomplete';
    public const IDENTITY_DUPLICATE    = 'identity_duplicate';
    public const IDENTITY_MUTATION     = 'identity_mutation';
    public const IDENTITY_HASH_INVALID = 'identity_hash_invalid';

    /* -----------------------------------------------------------------
     * SubEvent Invariants
     * ----------------------------------------------------------------- */
    public const SUBEVENT_IDENTITY_ERROR = 'subevent_identity_error';

    /* -----------------------------------------------------------------
     * Manifest-Level Invariants
     * ----------------------------------------------------------------- */
    public const MANIFEST_INVALID    = 'manifest_invalid';
    public const MANIFEST_NOT_LOADED = 'manifest_not_loaded';

    private string $codeId;
    private array $context;

    public function __construct(
        string $codeId,
        string $message,
        array $context = []
    ) {
        parent::__construct("[{$codeId}] {$message}");
        $this->codeId = $codeId;
        $this->context = $context;
    }

    public function codeId(): string
    {
        return $this->codeId;
    }

    public function context(): array
    {
        return $this->context;
    }
}