#!/usr/bin/env php
<?php
declare(strict_types=1);

// Phase 1: CLI wrapper that invokes the *web* exporter.
// This keeps one authoritative path for settings/locale behavior.

$url = 'http://127.0.0.1/plugin.php?plugin=GoogleCalendarScheduler&page=fpp-env-export.php&nopage=1';

$cmd = 'curl --silent --show-error --fail --max-time 5 '
     . escapeshellarg($url);

exec($cmd, $out, $rc);
if ($rc !== 0) {
    fwrite(STDERR, "ERROR: curl failed ($rc)\n");
    exit(1);
}

echo "OK\n";
exit(0);
