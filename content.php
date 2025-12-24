<?php
/**
 * GoogleCalendarScheduler
 * content.php
 */

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

/*
 * Diff preview endpoint (read-only)
 * GET ?endpoint=experimental_diff
 */
if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && isset($_GET['endpoint'])
    && $_GET['endpoint'] === 'experimental_diff'
) {
    header('Content-Type: application/json');

    if (empty($cfg['experimental']['enabled'])) {
        echo json_encode([
            'ok'    => false,
            'error' => 'experimental_disabled',
        ], JSON_PRETTY_PRINT);
        exit;
    }

    try {
        $diff = DiffPreviewer::preview($cfg);

        echo json_encode([
            'ok'                  => true,
            'experimentalEnabled' => true,
            'diff'                => $diff,
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

/*
 * Apply endpoint (11.8 — triple-guarded)
 * GET ?endpoint=experimental_apply
 */
if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && isset($_GET['endpoint'])
    && $_GET['endpoint'] === 'experimental_apply'
) {
    header('Content-Type: application/json');

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

/*
 * --------------------------------------------------------------------
 * POST handling (normal UI flow)
 * --------------------------------------------------------------------
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string)$_POST['action'];

    try {

        if ($action === 'save') {
            $cfg['calendar']['ics_url'] = trim($_POST['ics_url'] ?? '');
            $cfg['runtime']['dry_run']  = isset($_POST['dry_run']);

            GcsConfig::save($cfg);
            $cfg = GcsConfig::load();

            GcsLog::info('Settings saved', [
                'dryRun' => $cfg['runtime']['dry_run'],
            ]);
        }

        if ($action === 'sync') {
            $dryRun = !empty($cfg['runtime']['dry_run']);

            GcsLog::info('Starting sync', [
                'dryRun' => $dryRun,
            ]);

            $horizonDays = GcsFppSchedulerHorizon::getDays();
            GcsLog::info('Using FPP scheduler horizon', [
                'days' => $horizonDays,
            ]);

            $runner = new GcsSchedulerRunner($cfg, $horizonDays, $dryRun);
            $result = $runner->run();

            GcsLog::info('Sync completed', $result);
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
            <input
                type="text"
                name="ics_url"
                size="100"
                value="<?php echo htmlspecialchars($cfg['calendar']['ics_url'] ?? '', ENT_QUOTES); ?>"
            >
        </div>

        <div class="setting">
            <label>
                <input
                    type="checkbox"
                    name="dry_run"
                    <?php if (!empty($cfg['runtime']['dry_run'])) echo 'checked'; ?>
                >
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

    <!-- =============================== -->
    <!-- Phase 12.1 Step B: UI + Read-only fetch -->
    <!-- GET-only diff preview (no apply, no writes) -->
    <!-- No calls on page load -->
    <!-- =============================== -->

    <div class="gcs-diff-preview">
        <h3>Scheduler Change Preview</h3>

        <p class="description">
            Preview proposed scheduler changes (read-only). Click to fetch a diff summary.
        </p>

        <button type="button" class="buttons" id="gcs-preview-btn" disabled>
            Preview Changes
        </button>

        <div id="gcs-diff-results" style="margin-top: 10px;">
            <!-- Step B: results rendered here -->
        </div>
    </div>

    <script>
    (function () {
        'use strict';

        // Step B guarantee: no network calls on page load.
        // We only enable the button and bind click handler.

        function el(tag) {
            return document.createElement(tag);
        }

        function clear(node) {
            while (node.firstChild) node.removeChild(node.firstChild);
        }

        function setText(node, text) {
            node.textContent = String(text);
        }

        function appendLine(container, label, value) {
            var p = el('p');
            p.style.margin = '4px 0';
            var strong = el('strong');
            setText(strong, label + ': ');
            p.appendChild(strong);
            p.appendChild(document.createTextNode(String(value)));
            container.appendChild(p);
        }

        function isArray(v) {
            return Array.isArray(v);
        }

        function isObject(v) {
            return v !== null && typeof v === 'object' && !isArray(v);
        }

        // Try multiple common shapes without assuming a single schema.
        function getCount(diff, keys) {
            if (!isObject(diff)) return null;

            for (var i = 0; i < keys.length; i++) {
                var k = keys[i];
                if (Object.prototype.hasOwnProperty.call(diff, k)) {
                    var v = diff[k];
                    if (isArray(v)) return v.length;
                    if (typeof v === 'number') return v;
                    // Sometimes nested like { items: [...] } or { count: N }
                    if (isObject(v)) {
                        if (isArray(v.items)) return v.items.length;
                        if (typeof v.count === 'number') return v.count;
                        if (typeof v.total === 'number') return v.total;
                    }
                }
            }

            return null;
        }

        function renderMessage(results, title, msg) {
            clear(results);
            var h = el('h4');
            h.style.margin = '6px 0';
            setText(h, title);
            results.appendChild(h);

            var p = el('p');
            p.style.margin = '4px 0';
            setText(p, msg);
            results.appendChild(p);
        }

        function renderSummary(results, payload) {
            clear(results);

            var h = el('h4');
            h.style.margin = '6px 0';
            setText(h, 'Diff Summary');
            results.appendChild(h);

            var diff = payload && payload.diff ? payload.diff : null;

            var creates = getCount(diff, ['creates', 'create', 'toCreate', 'add', 'adds', 'new', 'insert', 'inserts']);
            var updates = getCount(diff, ['updates', 'update', 'toUpdate', 'modify', 'modifies', 'changed', 'changes']);
            var deletes = getCount(diff, ['deletes', 'delete', 'toDelete', 'remove', 'removes', 'del', 'drops']);

            // If we couldn't find counts, render a safe message (still read-only).
            if (creates === null && updates === null && deletes === null) {
                renderMessage(
                    results,
                    'Diff Summary',
                    'Diff received, but counts could not be inferred from the response format.'
                );

                // Show a compact, safe JSON preview (text only) to help us align schema in next step.
                var pre = el('pre');
                pre.style.whiteSpace = 'pre-wrap';
                pre.style.wordBreak = 'break-word';
                pre.style.background = '#f5f5f5';
                pre.style.padding = '8px';
                pre.style.borderRadius = '4px';
                pre.style.maxHeight = '240px';
                pre.style.overflow = 'auto';
                setText(pre, JSON.stringify(payload, null, 2));
                results.appendChild(pre);
                return;
            }

            // Default missing values to 0 if some were inferred and others not.
            creates = (creates === null) ? 0 : creates;
            updates = (updates === null) ? 0 : updates;
            deletes = (deletes === null) ? 0 : deletes;

            appendLine(results, 'Creates', creates);
            appendLine(results, 'Updates', updates);
            appendLine(results, 'Deletes', deletes);

            var note = el('p');
            note.style.marginTop = '8px';
            note.style.fontStyle = 'italic';
            setText(note, 'Read-only preview. No scheduler changes are applied.');
            results.appendChild(note);
        }

        function onReady() {
            var btn = document.getElementById('gcs-preview-btn');
            var results = document.getElementById('gcs-diff-results');

            if (!btn || !results) return;

            // Enable button now that JS is loaded (still no network calls).
            btn.disabled = false;

            btn.addEventListener('click', function () {
                // GET-only request; no page-load calls.
                btn.disabled = true;

                renderMessage(results, 'Loading…', 'Fetching diff preview (read-only)…');

                var url = window.location.pathname + '?endpoint=experimental_diff';

                fetch(url, { method: 'GET', credentials: 'same-origin' })
                    .then(function (resp) {
                        return resp.json()
                            .catch(function () {
                                throw new Error('Invalid JSON response');
                            });
                    })
                    .then(function (data) {
                        // Experimental disabled friendly message
                        if (!data || data.ok !== true) {
                            if (data && data.error === 'experimental_disabled') {
                                renderMessage(
                                    results,
                                    'Experimental Preview Disabled',
                                    'Experimental diff preview is currently disabled in configuration.'
                                );
                                return;
                            }

                            var msg = (data && (data.msg || data.error)) ? (data.msg || data.error) : 'Unknown error';
                            renderMessage(results, 'Preview Error', msg);
                            return;
                        }

                        renderSummary(results, data);
                    })
                    .catch(function (err) {
                        renderMessage(results, 'Network Error', err && err.message ? err.message : 'Request failed');
                    })
                    .finally(function () {
                        btn.disabled = false;
                    });
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', onReady);
        } else {
            onReady();
        }
    })();
    </script>

    <!-- =============================== -->
    <!-- End Phase 12.1 Step B -->
    <!-- =============================== -->

</div>
