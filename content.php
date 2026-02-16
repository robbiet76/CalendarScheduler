<?php
/**
 * Calendar Scheduler
 *
 * Design-mode UI shell aligned to native FPP styling.
 */
?>
<style>
  .cs-page {
    margin-top: 10px;
  }

  .cs-panel-title {
    margin-bottom: 6px;
  }

  .cs-muted {
    color: #6c757d;
  }

  .cs-kpi {
    margin-bottom: 8px;
  }

  .cs-kpi .badge {
    min-width: 34px;
  }

  .cs-json {
    margin: 8px 0 0;
    max-height: 220px;
    overflow: auto;
    font-size: 12px;
  }
</style>

<div class="cs-page" id="csShell">
  <div class="alert alert-info" role="alert">
    Connection, preview, and apply flow for calendar-to-FPP synchronization.
  </div>

  <div class="row g-2">
    <div class="col-lg-6">
      <div class="backdrop mb-3">
        <h4 class="cs-panel-title">1) Connection Setup</h4>
        <p class="cs-muted">First-time OAuth and calendar selection. Additional providers can be added over time.</p>

        <div class="mb-2">
          <span class="badge text-bg-primary">Google</span>
          <span class="badge text-bg-secondary">Outlook (Coming Soon)</span>
        </div>

        <div class="form-group mb-2">
          <label for="csConnectedAccount">Connected Account</label>
          <input id="csConnectedAccount" type="text" class="form-control" value="Not connected yet" readonly>
        </div>

        <div class="form-group mb-2">
          <label for="csCalendarSelect">Sync Calendar</label>
          <select id="csCalendarSelect" class="form-control" disabled>
            <option>Connect account to load calendars</option>
          </select>
        </div>

        <div class="mt-3">
          <button class="buttons btn-success" type="button">Connect Provider</button>
          <button class="buttons btn-detract" type="button">Resync Calendar List</button>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="backdrop mb-3">
        <h4 class="cs-panel-title">2) Sync Preview</h4>
        <p class="cs-muted">Preview auto-refreshes on page load and after each change. User chooses when to apply.</p>

        <div class="alert alert-warning" role="alert">
          Preview status: <strong id="csPreviewState">Needs Review</strong> |
          Last refresh: <span id="csPreviewTime">Pending</span>
        </div>

        <div class="cs-kpi">
          <span class="badge text-bg-warning" id="csKpiCalendar">0</span>
          Calendar Changes
        </div>
        <div class="cs-kpi">
          <span class="badge text-bg-warning" id="csKpiFpp">0</span>
          FPP Changes
        </div>
        <div class="cs-kpi">
          <span class="badge text-bg-success" id="csKpiNoop">No</span>
          In Sync
        </div>
        <div class="cs-kpi">
          <span class="badge text-bg-secondary" id="csKpiTotal">0</span>
          Total Actions
        </div>

        <div class="mt-3">
          <button class="buttons btn-detract" type="button">Refresh Preview</button>
        </div>
      </div>
    </div>
  </div>

  <div class="backdrop mb-3">
    <h4 class="cs-panel-title">3) Pending Actions</h4>
    <p class="cs-muted">Current preview action list (placeholder data until endpoint wiring).</p>
    <div class="table-responsive">
      <table class="table table-sm table-hover">
        <thead>
          <tr>
            <th>Action</th>
            <th>Target</th>
            <th>Event</th>
            <th>Reason</th>
          </tr>
        </thead>
        <tbody id="csActionsRows">
          <tr>
            <td><span class="badge text-bg-success">Create</span></td>
            <td>calendar</td>
            <td>Sample_Event</td>
            <td>Preview placeholder: endpoint wiring pending</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="alert alert-danger d-flex justify-content-between align-items-center flex-wrap" role="alert">
    <div>
      <strong>4) Apply Changes</strong><br>
      Apply uses the latest preview and writes updates to FPP and calendar.
    </div>
    <div class="mt-2 mt-md-0">
      <button class="buttons btn-danger" type="button">Apply Changes</button>
    </div>
  </div>

  <details>
    <summary><strong>Diagnostics (Design Mode)</strong></summary>
    <pre class="form-control cs-json" id="csDiagnosticJson">{
  "mode": "design-shell",
  "notes": [
    "Backend wiring not connected yet.",
    "Preview is conceptually automatic on load.",
    "Dry-run remains internal for backend testing."
  ]
}</pre>
  </details>
</div>

<script>
  (function () {
    var now = new Date();
    var stamp = now.toLocaleDateString() + " " + now.toLocaleTimeString();
    var previewTime = document.getElementById("csPreviewTime");
    if (previewTime) {
      previewTime.textContent = stamp;
    }
  }());
</script>
