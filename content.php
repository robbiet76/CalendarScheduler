<?php
/**
 * GoogleCalendarScheduler
 * content.php
 */

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
$dryRun = !empty($cfg['runtime']['dry_run']);
?>

<div class="settings">
<h2>Google Calendar Scheduler</h2>

<!-- =========================================================
     APPLY MODE BANNER (always visible)
     ========================================================= -->
<div class="gcs-mode-banner <?php echo $dryRun ? 'gcs-mode-dry' : 'gcs-mode-live'; ?>">
<?php if ($dryRun): ?>
    🔒 <strong>Apply mode: Dry-run</strong><br>
    Scheduler changes will <strong>NOT</strong> be written.
<?php else: ?>
    🔓 <strong>Apply mode: Live</strong><br>
    Scheduler changes <strong>WILL</strong> be written.
<?php endif; ?>
</div>

<form method="post">
    <input type="hidden" name="action" value="save">

    <div class="setting">
        <label><strong>Google Calendar ICS URL</strong></label><br>
        <input
            type="text"
            name="ics_url"
            size="100"
            value="<?php echo htmlspecialchars($cfg['calendar']['ics_url'] ?? '', ENT_QUOTES); ?>"
        >
    </div>

    <div class="setting">
        <label>
            <input type="checkbox" name="dry_run"
                <?php if ($dryRun) echo 'checked'; ?>>
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

<?php if (empty($cfg['experimental']['enabled'])): ?>
    <div class="gcs-info">
        Experimental diff preview is currently disabled.
    </div>
<?php else: ?>
    <button type="button" class="buttons" id="gcs-preview-btn">
        Preview Changes
    </button>

    <div id="gcs-diff-summary" class="gcs-hidden" style="margin-top:12px;"></div>
    <div id="gcs-diff-results" style="margin-top:10px;"></div>
<?php endif; ?>
</div>

<hr>

<div class="gcs-apply-panel gcs-hidden" id="gcs-apply-container">
    <h3>Apply Scheduler Changes</h3>

    <div class="gcs-warning">
        <strong>This will modify the FPP scheduler.</strong><br>
        Review the preview above. Applying cannot be undone.
    </div>

    <div id="gcs-apply-summary" style="margin-top:10px; font-weight:bold;"></div>

    <button
        type="button"
        class="buttons"
        id="gcs-apply-btn"
        disabled
        title="<?php echo $dryRun
            ? 'Apply is disabled while dry-run mode is enabled.'
            : 'Apply pending changes to the FPP scheduler.'; ?>">
        Apply Changes
    </button>

    <div id="gcs-apply-result" style="margin-top:10px;"></div>
</div>

<style>
.gcs-hidden { display:none; }

.gcs-mode-banner {
    padding:10px;
    border-radius:6px;
    margin-bottom:12px;
    font-weight:bold;
}
.gcs-mode-dry {
    background:#eef5ff;
    border:1px solid #cfe2ff;
}
.gcs-mode-live {
    background:#e6f4ea;
    border:1px solid #b7e4c7;
}

.gcs-info {
    padding:10px; background:#eef5ff; border:1px solid #cfe2ff; border-radius:6px;
}
.gcs-warning {
    padding:10px; background:#fff3cd; border:1px solid #ffeeba; border-radius:6px;
}
.gcs-diff-badges { display:flex; gap:10px; margin:8px 0; flex-wrap:wrap; }
.gcs-badge { padding:6px 10px; border-radius:12px; font-weight:bold; font-size:.9em; }
.gcs-badge-create { background:#e6f4ea; color:#1e7e34; }
.gcs-badge-update { background:#fff3cd; color:#856404; }
.gcs-badge-delete { background:#f8d7da; color:#721c24; }
.gcs-section { margin-top:10px; border-top:1px solid #ddd; padding-top:6px; }
.gcs-section h4 { cursor:pointer; margin:6px 0; }
.gcs-section ul { margin:6px 0 6px 18px; }
.gcs-empty {
    padding:10px; background:#eef5ff; border:1px solid #cfe2ff; border-radius:6px;
}
.gcs-apply-panel {
    padding:10px; border:1px solid #ddd; border-radius:6px;
}
</style>

<script>
(function(){
'use strict';

var ENDPOINT =
  'plugin.php?_menu=content&plugin=GoogleCalendarScheduler&page=content.php&nopage=1';

function getJSON(url, cb){
    fetch(url, {credentials:'same-origin'})
        .then(function(r){ return r.json(); })
        .then(function(j){ cb(j); })
        .catch(function(){ cb(null); });
}

function counts(d){
    return {
        c:(d.creates||[]).length,
        u:(d.updates||[]).length,
        x:(d.deletes||[]).length
    };
}

var previewBtn=document.getElementById('gcs-preview-btn');
if(!previewBtn) return;

var diffSummary=document.getElementById('gcs-diff-summary');
var diffResults=document.getElementById('gcs-diff-results');
var applyBox=document.getElementById('gcs-apply-container');
var applySummary=document.getElementById('gcs-apply-summary');
var applyBtn=document.getElementById('gcs-apply-btn');
var applyResult=document.getElementById('gcs-apply-result');

var last=null, armed=false;

previewBtn.onclick=function(){
    armed=false;
    applyBtn.disabled=true;
    applyBtn.textContent='Apply Changes';
    applyResult.textContent='';
    applyResult.className='';
    diffResults.textContent='Loading preview…';

    getJSON(ENDPOINT+'&endpoint=experimental_diff',function(d){
        if(!d||!d.ok){ diffResults.textContent='Preview unavailable.'; return; }

        var n=counts(d.diff||{}); last=n;

        diffSummary.classList.remove('gcs-hidden');
        diffSummary.innerHTML =
          '<div class="gcs-diff-badges">'+
          '<span class="gcs-badge gcs-badge-create">+ '+n.c+' Creates</span>'+
          '<span class="gcs-badge gcs-badge-update">~ '+n.u+' Updates</span>'+
          '<span class="gcs-badge gcs-badge-delete">− '+n.x+' Deletes</span>'+
          '</div>';

        diffResults.innerHTML='';
        if(n.c+n.u+n.x===0){
            diffResults.innerHTML='<div class="gcs-empty">No scheduler changes detected.</div>';
        }

        applyBox.classList.remove('gcs-hidden');
        applySummary.textContent =
            (n.c+n.u+n.x===0)
            ? 'No pending scheduler changes.'
            : (n.c+n.u+n.x)+' pending scheduler changes detected.';
        applyBtn.disabled=(n.c+n.u+n.x===0);
    });
};

applyBtn.onclick=function(){
    if(!last) return;

    if(!armed){
        armed=true;
        applyBtn.textContent='Confirm Apply';
        applyResult.textContent='Click "Confirm Apply" to proceed.';
        return;
    }

    applyBtn.disabled=true;
    applyBtn.textContent='Applying…';
    applyResult.textContent='Applying scheduler changes…';

    getJSON(ENDPOINT+'&endpoint=experimental_apply',function(r){
        if(!r){
            applyResult.textContent='Apply failed.';
            return;
        }

        if(r.status==='blocked'){
            applyBtn.textContent='Apply Blocked';
            applyResult.innerHTML =
              '<strong>Apply blocked</strong><br>'+
              'Apply is disabled because dry-run mode is ON.<br>'+
              'No scheduler changes were made.';
            return;
        }

        applyBtn.textContent='Apply Completed';
        applyResult.innerHTML =
          '<strong>Changes applied successfully</strong><br>'+
          r.counts.creates+' scheduler entries were created.<br>'+
          'Re-running preview now shows no remaining changes.';
    });
};

})();
</script>
</div>
