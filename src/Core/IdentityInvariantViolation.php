<?php
declare(strict_types=1);

namespace GCS\Core;

/**
 * IdentityInvariantViolation
 *
 * Hard-failure exception for identity-level invariants.
 * Used when identity is missing, malformed, or non-canonical.
 */
final class IdentityInvariantViolation extends \RuntimeException
{
    // Identity invariant codes (keep stable)
    public const IDENTITY_MISSING = 'IDENTITY_MISSING';
    public const IDENTITY_REQUIRED_FIELD_MISSING = 'IDENTITY_REQUIRED_FIELD_MISSING';
    public const IDENTITY_TIMING_INVALID = 'IDENTITY_TIMING_INVALID';
    public const IDENTITY_FORBIDDEN_FIELD_PRESENT = 'IDENTITY_FORBIDDEN_FIELD_PRESENT';
    public const IDENTITY_TYPE_INVALID = 'IDENTITY_TYPE_INVALID';
    public const IDENTITY_CANONICALIZATION_FAILED = 'IDENTITY_CANONICALIZATION_FAILED';
    public const IDENTITY_HASH_INVALID = 'IDENTITY_HASH_INVALID';
    public const IDENTITY_DUPLICATE = 'IDENTITY_DUPLICATE';
    public const IDENTITY_MUTATION = 'IDENTITY_MUTATION';
    public const IDENTITY_INCOMPLETE = 'IDENTITY_INCOMPLETE';
    public const IDENTITY_INVALID_TYPE = 'IDENTITY_INVALID_TYPE';

    /** @var array<string,mixed> */
    private array $context;

    private string $invariantCode;

    /**
     * @param array<string,mixed> $context
     */
    public function __construct(string $code, string $message, array $context = [], ?\Throwable $previous = null)
    {
        $this->context = $context;
        $this->invariantCode = $code;
        parent::__construct($message, 0, $previous);
    }

    public function getInvariantCode(): string
    {
        return $this->invariantCode;
    }

    /**
     * @return array<string,mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * @param array<string,mixed> $context
     */
    public static function fail(string $code, string $message, array $context = [], ?\Throwable $previous = null): self
    {
        return new self($code, $message, $context, $previous);
    }
}
