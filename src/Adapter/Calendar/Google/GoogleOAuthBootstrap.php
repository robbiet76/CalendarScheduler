<?php

declare(strict_types=1);

namespace CalendarScheduler\Adapter\Calendar\Google;

/**
 * One-time interactive OAuth bootstrap for Google Calendar.
 *
 * Responsibilities:
 *  - Load client_secret.json
 *  - Guide user through consent flow
 *  - Persist token.json for future non-interactive use
 *
 * This class is ONLY used by the CLI command `google:auth`.
 * It is never invoked during normal sync, diff, or apply.
 */
final class GoogleOAuthBootstrap
{
    private GoogleConfig $config;

    public function __construct(GoogleConfig $config)
    {
        $this->config = $config;
    }

    /**
     * Execute the interactive OAuth bootstrap flow.
     *
     * This method is intentionally side-effectful and CLI-only.
     */
    public function run(): void
    {
        // TODO: implement OAuth bootstrap flow
        fwrite(STDERR, "Google OAuth bootstrap not implemented yet.\n");
        fwrite(STDERR, "Client config loaded.\n");
        exit(1);
    }
}