<?php
declare(strict_types=1);

namespace CalendarScheduler\Adapter\Calendar\Google;

final class GoogleConfig
{
    /** @var array<string,mixed> */
    private array $data;
    private string $path;

    /**
     * @param array<string,mixed> $data
     */
    public function __construct(string $path, array $data)
    {
        $this->path = $path;
        $this->data = $data;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public static function load(string $path): self
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("GoogleConfig: failed to read config: {$path}");
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('GoogleConfig: invalid json');
        }
        return new self($path, $data);
    }

    public function save(): void
    {
        $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('GoogleConfig: failed to encode json');
        }
        if (@file_put_contents($this->path, $json . "\n") === false) {
            throw new \RuntimeException('GoogleConfig: failed to write config: ' . $this->path);
        }
    }

    public function getClientId(): string
    {
        return (string) ($this->data['client_id'] ?? '');
    }

    public function getClientSecret(): string
    {
        return (string) ($this->data['client_secret'] ?? '');
    }

    public function getCalendarId(): string
    {
        // single-calendar-at-a-time model; user can switch which calendar is active
        return (string) ($this->data['calendar_id'] ?? 'primary');
    }

    /**
     * @return array<string,mixed>
     */
    public function getTokens(): array
    {
        return (array) ($this->data['tokens'] ?? []);
    }

    /**
     * @param array<string,mixed> $tokens
     */
    public function setTokens(array $tokens): void
    {
        $this->data['tokens'] = $tokens;
    }
}
