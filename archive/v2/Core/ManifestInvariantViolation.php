<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Core;

/**
 * ManifestInvariantViolation
 *
 * Hard-failure exception for manifest-level invariants.
 * Keep this minimal: enough structure to surface a clear error code + context.
 */
final class ManifestInvariantViolation extends \RuntimeException
{
    // Manifest-level codes (keep stable; used for diagnostics and tests)
    public const MANIFEST_UNREADABLE = 'MANIFEST_UNREADABLE';
    public const MANIFEST_JSON_INVALID = 'MANIFEST_JSON_INVALID';
    public const MANIFEST_ROOT_INVALID = 'MANIFEST_ROOT_INVALID';
    public const EVENT_MISSING_ID = 'EVENT_MISSING_ID';
    public const EVENT_DUPLICATE_ID = 'EVENT_DUPLICATE_ID';
    public const EVENT_IDENTITY_MISSING = 'EVENT_IDENTITY_MISSING';
    public const EVENT_IDENTITY_INVALID = 'EVENT_IDENTITY_INVALID';
    public const EVENT_IDENTITY_MUTATION = 'EVENT_IDENTITY_MUTATION';
    public const SUBEVENT_IDENTITY_INVALID = 'SUBEVENT_IDENTITY_INVALID';

    /** @var array<string,mixed> */
    private array $context;

    private string $invariantCode;

    /**
     * @param array<string,mixed> $context
     */
    public function __construct(string $code, string $message, array $context = [], ?\Throwable $previous = null)
    {
        $this->context = $context;
        parent::__construct($message, 0, $previous);
        // store code in Exception::getCode() isn't great (expects int), so we expose via getInvariantCode()
        $this->invariantCode = $code;
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
     * Convenience factory.
     *
     * @param array<string,mixed> $context
     */
    public static function fail(string $code, string $message, array $context = [], ?\Throwable $previous = null): self
    {
        return new self($code, $message, $context, $previous);
    }
}

