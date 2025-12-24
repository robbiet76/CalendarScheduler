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
 * AJAX / JSON ENDPOINTS (FPP-supported via plugin.php?ajax=1)
 * --------------------------------------------------------------------
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['endpoint'])) {
    header('Content-Type: application/json');

    /* ---------- Diff Preview (read-only) ---------- */
    if ($_GET['endpoint'] === 'experimental_diff') {

        if (empty($cfg['experimental']['enabled']) && !$UI_TEST_MODE) {
            echo json_encode([
                'ok'    => false,
                'error' => 'experimental_disabled',
            ]);
            exit;
        }

        // UI test mode: synthetic diff
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
                'error' => 'experimental_error',
                'msg'   => $e->getMessage(),
            ]);
            exit;
        }
    }

    /* ---------- Apply (triple-guarded, Phase 11) ---------- */
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
        GcsLog::error('GoogleCalendarScheduler error', [
            'error' => $e->getMessage(),
        ]);
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

<!-- ================= Diff Preview ================= -->
<div class="gcs-diff-preview">
    <h3>Scheduler Change Preview</h3>
    <button type="button" class="buttons" id="gcs-preview-btn">
        Preview Changes
    </button>
    <div id="gcs-diff-results" style="margin-top:12px;"></div>
</div>

<hr>

<!-- ================= Apply UI ================= -->
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
.gcs-apply-preview { padding:10px; background:#fff3cd; border:1px solid #ffeeba; border-radius:6px; }
.gcs-empty { padding:10px; background:#eef5ff; border:1px solid #cfe2ff; border-radius:6px; }
</style>

<script>
(function () {
'use strict';

/*
 * FPP-approved AJAX endpoint base
 */
var ENDPOINT_BASE =
  'plugin.php?ajax=1&plugin=GoogleCalendarScheduler&page=content.php';

function getJSON(url, cb) {
    fetch(url, { credentials:'same-origin' })
        .then(function (r) { return r.text(); })
        .then(function (t) {
            try { cb(JSON.parse(t)); }
            catch (e) { cb(null); }
        });
}

function renderDiff(container, diff) {
    var c=(diff.creates||[]).length;
    var u=(diff.updates||[]).length;
    var d=(diff.deletes||[]).length;

    container.innerHTML='';

    if (c+u+d === 0) {
        container.innerHTML =
            '<div class="gcs-empty">No scheduler changes detected.</div>';
        return;
    }

    container.innerHTML =
        '<div class="gcs-diff-badges">'+
        '<span class="gcs-badge gcs-badge-create">+ '+c+' Creates</span>'+
        '<span class="gcs-badge gcs-badge-update">~ '+u+' Updates</span>'+
        '<span class="gcs-badge gcs-badge-delete">− '+d+' Deletes</span>'+
        '</div>';
}

document.getElementById('gcs-preview-btn').onclick = function () {
    var results = document.getElementById('gcs-diff-results');
    var applyBox = document.getElementById('gcs-apply-container');

    results.textContent = 'Loading preview…';
    applyBox.className = 'gcs-apply-preview gcs-hidden';

    getJSON(ENDPOINT_BASE + '&endpoint=experimental_diff', function (data) {
        if (!data || !data.ok) {
            results.textContent = 'Preview unavailable.';
            return;
        }

        renderDiff(results, data.diff || {});

        if (
            (data.diff.creates||[]).length +
            (data.diff.updates||[]).length +
            (data.diff.deletes||[]).length > 0
        ) {
            applyBox.className = 'gcs-apply-preview';
        }
    });
};

document.getElementById('gcs-apply-btn').onclick = function () {
    var out = document.getElementById('gcs-apply-result');
    out.textContent = 'Applying changes…';

    getJSON(ENDPOINT_BASE + '&endpoint=experimental_apply', function (data) {
        out.textContent = (data && data.ok)
            ? 'Apply completed (or blocked by safety guards).'
            : 'Apply failed.';
    });
};

})();
</script>
</div>
