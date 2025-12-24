<?php
/**
 * GoogleCalendarScheduler
 * content.php
 */

/*
 * ==============================================================
 * TEMP (Phase 12 UI testing only — REMOVE BEFORE MERGE)
 * ==============================================================
 */
$UI_TEST_MODE = true;

require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/FppSchedulerHorizon.php';

// Experimental scaffolding
require_once __DIR__ . '/src/experimental/ExecutionContext.php';
require_once __DIR__ . '/src/experimental/ScopedLogger.php';
require_once __DIR__ . '/src/experimental/ExecutionController.php';
require_once __DIR__ . '/src/experimental/HealthProbe.php';
require_once __DIR__ . '/src/experimental/CalendarReader.php';
require_once __DIR__ . '/src/experimental/DiffPreviewer.php';

$cfg = GcsConfig::load();

/*
 * --------------------------------------------------------------------
 * JSON ENDPOINTS
 * --------------------------------------------------------------------
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['endpoint'])) {
    header('Content-Type: application/json');

    if ($_GET['endpoint'] === 'experimental_diff') {

        if (empty($cfg['experimental']['enabled']) && !$UI_TEST_MODE) {
            echo json_encode([
                'ok'    => false,
                'error' => 'experimental_disabled',
            ]);
            exit;
        }

        if ($UI_TEST_MODE && empty($cfg['experimental']['enabled'])) {
            echo json_encode([
                'ok'   => true,
                'diff' => [
                    'creates' => ['Test Event A', 'Test Event B'],
                    'updates' => ['Updated Event C'],
                    'deletes' => ['Removed Event D'],
                ],
            ]);
            exit;
        }

        try {
            echo json_encode([
                'ok'   => true,
                'diff' => DiffPreviewer::preview($cfg),
            ]);
            exit;
        } catch (Throwable $e) {
            echo json_encode([
                'ok'    => false,
                'error' => $e->getMessage(),
            ]);
            exit;
        }
    }

    if ($_GET['endpoint'] === 'experimental_apply') {
        try {
            echo json_encode([
                'ok'      => true,
                'applied' => true,
                'result'  => DiffPreviewer::apply($cfg),
            ]);
            exit;
        } catch (Throwable $e) {
            echo json_encode([
                'ok'    => false,
                'error' => 'apply_blocked',
                'msg'   => $e->getMessage(),
            ]);
            exit;
        }
    }
}
?>

<!-- NORMAL UI BELOW -->
<div class="settings">
<h2>Google Calendar Scheduler</h2>

<button class="buttons" id="preview">Preview Changes</button>
<div id="out"></div>
<div id="applyBox" style="display:none;">
  <p><strong>Applying changes will modify the scheduler.</strong></p>
  <button class="buttons" id="apply">Apply Changes</button>
  <div id="applyOut"></div>
</div>

<script>
(function(){
'use strict';

function get(url, cb){
  fetch(url,{credentials:'same-origin'})
    .then(r=>r.text())
    .then(t=>{
      try{cb(JSON.parse(t));}
      catch(e){cb(null);}
    });
}

document.getElementById('preview').onclick=function(){
  document.getElementById('out').textContent='Loading preview…';
  get('plugin/GoogleCalendarScheduler/content.php?endpoint=experimental_diff',function(d){
    if(!d||!d.ok){document.getElementById('out').textContent='Preview unavailable.';return;}
    document.getElementById('out').textContent=JSON.stringify(d.diff,null,2);
    document.getElementById('applyBox').style.display='block';
  });
};

document.getElementById('apply').onclick=function(){
  document.getElementById('applyOut').textContent='Applying…';
  get('plugin/GoogleCalendarScheduler/content.php?endpoint=experimental_apply',function(d){
    document.getElementById('applyOut').textContent=d&&d.ok?'Apply completed or blocked.':'Apply failed.';
  });
};
})();
</script>
</div>
