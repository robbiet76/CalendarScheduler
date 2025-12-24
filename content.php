<?php
/**
 * GoogleCalendarScheduler
 * content.php
 */

/*
 * ==============================================================
 * TEMP (Phase 12 UI testing only — REMOVE BEFORE MERGE)
 * Allows UI preview paths without enabling backend experimental
 * ==============================================================
 */
$UI_TEST_MODE = true;

require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/FppSchedulerHorizon.php';

// Experimental scaffolding (explicitly required)
require_once __DIR__ . '/src/experimental/ExecutionContext.php';
require_once __DIR__ . '/src/experimental/ScopedLogger.php';
require_once __DIR__ . '/src/experimental/ExecutionController.php';
require_once __DIR__ . '/src/experimental/HealthProbe.php';
require_once __DIR__ . '/src/experimental/CalendarReader.php';
require_once __DIR__ . '/src/experimental/DiffPreviewer.php';

$cfg = GcsConfig::load();

/*
 * --------------------------------------------------------------------
 * EXPERIMENTAL ENDPOINTS (11.7 / 11.8)
 * --------------------------------------------------------------------
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['endpoint'])) {
    header('Content-Type: application/json');

    if ($_GET['endpoint'] === 'experimental_diff') {

        if (empty($cfg['experimental']['enabled']) && !$UI_TEST_MODE) {
            echo json_encode([
                'ok'    => false,
                'error' => 'experimental_disabled',
            ], JSON_PRETTY_PRINT);
            exit;
        }

        try {
            if ($UI_TEST_MODE && empty($cfg['experimental']['enabled'])) {
                echo json_encode([
                    'ok'   => true,
                    'diff' => [
                        'creates' => ['Test Event A', 'Test Event B'],
                        'updates' => ['Updated Event C'],
                        'deletes' => ['Removed Event D'],
                    ],
                ], JSON_PRETTY_PRINT);
                exit;
            }

            $diff = DiffPreviewer::preview($cfg);
            echo json_encode([
                'ok'   => true,
                'diff' => $diff,
            ], JSON_PRETTY_PRINT);
            exit;

        } catch (Throwable $e) {
            echo json_encode([
                'ok'    => false,
                'error' => 'experimental_error',
                'msg'   => $e->getMessage(),
            ], JSON_PRETTY_PRINT);
            exit;
        }
    }

    if ($_GET['endpoint'] === 'experimental_apply') {
        try {
            $result = DiffPreviewer::apply($cfg);
            echo json_encode([
                'ok'      => true,
                'applied' => true,
                'result'  => $result,
            ], JSON_PRETTY_PRINT);
            exit;

        } catch (Throwable $e) {
            echo json_encode([
                'ok'    => false,
                'error' => 'apply_blocked',
                'msg'   => $e->getMessage(),
            ], JSON_PRETTY_PRINT);
            exit;
        }
    }
}

/*
 * --------------------------------------------------------------------
 * POST handling (normal UI flow)
 * --------------------------------------------------------------------
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'save') {
            $cfg['calendar']['ics_url'] = trim($_POST['ics_url'] ?? '');
            $cfg['runtime']['dry_run']  = isset($_POST['dry_run']);
            GcsConfig::save($cfg);
            $cfg = GcsConfig::load();
        }

        if ($_POST['action'] === 'sync') {
            $runner = new GcsSchedulerRunner(
                $cfg,
                GcsFppSchedulerHorizon::getDays(),
                !empty($cfg['runtime']['dry_run'])
            );
            $runner->run();
        }
    } catch (Throwable $e) {
        GcsLog::error('GoogleCalendarScheduler error', ['error' => $e->getMessage()]);
    }
}
?>

<div class="settings">
<h2>Google Calendar Scheduler</h2>

<form method="post">
    <input type="hidden" name="action" value="save">
    <div class="setting">
        <label><strong>Google Calendar ICS URL</strong></label><br>
        <input type="text" name="ics_url" size="100"
            value="<?php echo htmlspecialchars($cfg['calendar']['ics_url'] ?? '', ENT_QUOTES); ?>">
    </div>
    <div class="setting">
        <label>
            <input type="checkbox" name="dry_run"
                <?php if (!empty($cfg['runtime']['dry_run'])) echo 'checked'; ?>>
            Dry run (do not modify FPP scheduler)
        </label>
    </div>
    <button type="submit" class="buttons">Save Settings</button>
</form>

<hr>

<form method="post">
    <input type="hidden" name="action" value="sync">
    <button type="submit" class="buttons">Sync Calendar</button>
</form>

<hr>

<div class="gcs-diff-preview">
    <h3>Scheduler Change Preview</h3>
    <button type="button" class="buttons" id="gcs-preview-btn" disabled>
        Preview Changes
    </button>
    <div id="gcs-diff-results" style="margin-top:12px;"></div>
</div>

<hr>

<div class="gcs-apply-preview gcs-hidden" id="gcs-apply-container">
    <h3>Apply Scheduler Changes</h3>
    <p style="font-weight:bold; color:#856404;">
        Applying changes will modify the FPP scheduler based on the preview above.
        This action cannot be undone.
    </p>
    <button type="button" class="buttons" id="gcs-apply-btn">
        Apply Changes
    </button>
    <div id="gcs-apply-result" style="margin-top:10px;"></div>
</div>

<style>
.gcs-hidden { display:none; }
.gcs-diff-badges { display:flex; gap:10px; margin:8px 0; flex-wrap:wrap; }
.gcs-badge { padding:6px 10px; border-radius:12px; font-weight:bold; font-size:.9em; }
.gcs-badge-create { background:#e6f4ea; color:#1e7e34; }
.gcs-badge-update { background:#fff3cd; color:#856404; }
.gcs-badge-delete { background:#f8d7da; color:#721c24; }
.gcs-section { margin-top:10px; border-top:1px solid #ddd; padding-top:6px; }
.gcs-section h4 { cursor:pointer; margin:6px 0; }
.gcs-section ul { margin:6px 0 6px 18px; }
.gcs-apply-preview { padding:10px; background:#fff3cd; border:1px solid #ffeeba; border-radius:6px; }
.gcs-empty { padding:10px; background:#eef5ff; border:1px solid #cfe2ff; border-radius:6px; }
</style>

<script>
(function () {
'use strict';

var BASE =
  'plugin.php?_menu=content&plugin=GoogleCalendarScheduler&page=content.php';

function extractJson(text){
    var m=text.match(/\{[\s\S]*?\}/g); if(!m) return null;
    for(var i=m.length-1;i>=0;i--){
        try{var o=JSON.parse(m[i]); if(typeof o.ok==='boolean') return o;}catch(e){}
    }
    return null;
}
function isArr(v){return Object.prototype.toString.call(v)==='[object Array]';}

function render(results,diff){
    var c=isArr(diff.creates)?diff.creates.length:0;
    var u=isArr(diff.updates)?diff.updates.length:0;
    var d=isArr(diff.deletes)?diff.deletes.length:0;
    results.innerHTML='';
    if(c+u+d===0){
        results.innerHTML='<div class="gcs-empty">No scheduler changes detected.</div>';
        return;
    }
    results.innerHTML =
      '<div class="gcs-diff-badges">'+
      '<span class="gcs-badge gcs-badge-create">+ '+c+' Creates</span>'+
      '<span class="gcs-badge gcs-badge-update">~ '+u+' Updates</span>'+
      '<span class="gcs-badge gcs-badge-delete">− '+d+' Deletes</span>'+
      '</div>';
}

function ready(){
    var p=document.getElementById('gcs-preview-btn');
    var r=document.getElementById('gcs-diff-results');
    var a=document.getElementById('gcs-apply-container');
    var ab=document.getElementById('gcs-apply-btn');
    var ar=document.getElementById('gcs-apply-result');

    p.disabled=false;

    p.onclick=function(){
        r.textContent='Fetching diff preview…';
        a.className='gcs-apply-preview gcs-hidden';

        fetch(BASE+'&endpoint=experimental_diff',{credentials:'same-origin'})
        .then(function(x){return x.text();})
        .then(function(t){
            var d=extractJson(t);
            if(!d||!d.ok){r.textContent='Preview unavailable.';return;}
            render(r,d.diff||{});
            if((d.diff.creates||[]).length+(d.diff.updates||[]).length+(d.diff.deletes||[]).length>0)
                a.className='gcs-apply-preview';
        });
    };

    ab.onclick=function(){
        ar.textContent='Applying changes…';
        fetch(BASE+'&endpoint=experimental_apply',{credentials:'same-origin'})
        .then(function(x){return x.text();})
        .then(function(t){
            var d=extractJson(t);
            ar.textContent=d&&d.ok?'Apply completed (or blocked by guards).':'Apply failed.';
        });
    };
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',ready);else ready();
})();
</script>
</div>
