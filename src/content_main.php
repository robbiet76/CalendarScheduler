<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/IcsFetcher.php';
require_once __DIR__ . '/IcsParser.php';
require_once __DIR__ . '/IntentConsolidator.php';
require_once __DIR__ . '/SchedulerSync.php';
require_once __DIR__ . '/FppSchedulerHorizon.php';

$cfg = GcsConfig::load();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sync') {
    $dryRun = !empty($cfg['runtime']['dry_run']);

    GcsLogger::instance()->info('Starting sync', ['dryRun' => $dryRun]);

    $horizonDays = FppSchedulerHorizon::getDays();
    GcsLogger::instance()->info('Using FPP scheduler horizon', ['days' => $horizonDays]);

    $now = new DateTime('now');
    $horizonEnd = (clone $now)->modify('+' . $horizonDays . ' days');

    $ics = (new IcsFetcher())->fetch($cfg['calendar']['ics_url']);

    $parser = new GcsIcsParser();
    $events = $parser->parse($ics, $now, $horizonEnd);

    // Build base scheduler intents
    $baseIntents = [];
    foreach ($events as $e) {
        $baseIntents[] = [
            'type'     => 'playlist',
            'target'   => $e['summary'],
            'start'    => $e['start'],
            'end'      => $e['end'],
            'stopType' => 'graceful',
            'repeat'   => 'none',
        ];
    }

    $consolidator = new GcsIntentConsolidator();
    $intents = $consolidator->consolidate($baseIntents);

    $sync = new GcsSchedulerSync($dryRun);
    $result = $sync->sync($intents);

    GcsLogger::instance()->info('Sync completed', $result);

    header('Location: plugin.php?plugin=GoogleCalendarScheduler');
    exit;
}
?>

<h1>Google Calendar Scheduler</h1>

<form method="post">
  <input type="hidden" name="action" value="sync" />
  <button type="submit">Sync Now (Dry-run)</button>
</form>
