<?php
/**
 * Calendar Scheduler — V2
 *
 * Design-mode UI shell.
 * Intentional: static structure first, backend wiring next.
 */
?>
<style>
  :root {
    --cs-bg: #f3f5f8;
    --cs-surface: #ffffff;
    --cs-surface-soft: #f7f9fc;
    --cs-ink: #132437;
    --cs-ink-soft: #49627d;
    --cs-border: #d8e2ed;
    --cs-primary: #0f6fb2;
    --cs-primary-ink: #e9f5ff;
    --cs-warn: #b6540d;
    --cs-ok: #17653e;
    --cs-danger: #ad2f2f;
    --cs-radius: 14px;
    --cs-shadow: 0 10px 28px rgba(22, 41, 64, 0.08);
  }

  .cs-shell {
    margin: 14px 0;
    color: var(--cs-ink);
    font-family: "Segoe UI", "Trebuchet MS", sans-serif;
  }

  .cs-hero {
    background: linear-gradient(132deg, #0f6fb2, #1a9a84);
    color: #fff;
    border-radius: var(--cs-radius);
    box-shadow: var(--cs-shadow);
    padding: 22px 22px 18px;
    margin-bottom: 14px;
  }

  .cs-hero h2 {
    margin: 0;
    font-size: 24px;
    letter-spacing: 0.3px;
  }

  .cs-hero p {
    margin: 7px 0 0;
    opacity: 0.95;
    font-size: 14px;
  }

  .cs-grid {
    display: grid;
    grid-template-columns: 1.1fr 1fr;
    gap: 14px;
    margin-bottom: 14px;
  }

  .cs-card {
    border: 1px solid var(--cs-border);
    border-radius: var(--cs-radius);
    background: var(--cs-surface);
    box-shadow: var(--cs-shadow);
    padding: 16px;
  }

  .cs-card h3 {
    margin: 0 0 10px;
    font-size: 16px;
    letter-spacing: 0.2px;
  }

  .cs-sub {
    margin: 0 0 12px;
    color: var(--cs-ink-soft);
    font-size: 13px;
  }

  .cs-provider-row {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 12px;
  }

  .cs-chip {
    border: 1px solid var(--cs-border);
    background: var(--cs-surface-soft);
    color: var(--cs-ink);
    border-radius: 999px;
    padding: 6px 12px;
    font-size: 12px;
    font-weight: 600;
  }

  .cs-chip.is-active {
    border-color: var(--cs-primary);
    background: var(--cs-primary-ink);
    color: var(--cs-primary);
  }

  .cs-chip.is-muted {
    opacity: 0.65;
  }

  .cs-field {
    margin-bottom: 10px;
  }

  .cs-field label {
    display: block;
    margin-bottom: 6px;
    font-size: 12px;
    font-weight: 700;
    color: var(--cs-ink-soft);
    text-transform: uppercase;
    letter-spacing: 0.4px;
  }

  .cs-field input,
  .cs-field select {
    width: 100%;
    border: 1px solid var(--cs-border);
    border-radius: 10px;
    background: #fff;
    padding: 9px 10px;
    color: var(--cs-ink);
    font-size: 13px;
  }

  .cs-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 8px;
  }

  .cs-btn {
    border: 1px solid var(--cs-border);
    border-radius: 10px;
    background: #fff;
    color: var(--cs-ink);
    padding: 8px 12px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
  }

  .cs-btn.is-primary {
    background: var(--cs-primary);
    border-color: var(--cs-primary);
    color: #fff;
  }

  .cs-btn.is-danger {
    background: #fff5f5;
    border-color: #f1c2c2;
    color: var(--cs-danger);
  }

  .cs-status-bar {
    border: 1px solid var(--cs-border);
    border-radius: 10px;
    background: var(--cs-surface-soft);
    padding: 10px 11px;
    font-size: 12px;
    color: var(--cs-ink-soft);
  }

  .cs-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(90px, 1fr));
    gap: 8px;
    margin-top: 8px;
  }

  .cs-kpi {
    background: #fff;
    border: 1px solid var(--cs-border);
    border-radius: 10px;
    padding: 9px;
  }

  .cs-kpi strong {
    display: block;
    font-size: 18px;
    line-height: 1.1;
    margin-bottom: 2px;
  }

  .cs-kpi span {
    font-size: 11px;
    color: var(--cs-ink-soft);
    text-transform: uppercase;
    letter-spacing: 0.3px;
  }

  .cs-kpi.is-ok strong { color: var(--cs-ok); }
  .cs-kpi.is-warn strong { color: var(--cs-warn); }

  .cs-table-wrap {
    overflow: auto;
    border: 1px solid var(--cs-border);
    border-radius: 12px;
    background: #fff;
  }

  .cs-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
  }

  .cs-table th,
  .cs-table td {
    text-align: left;
    border-bottom: 1px solid #e7edf4;
    padding: 8px 10px;
    white-space: nowrap;
  }

  .cs-table th {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    color: var(--cs-ink-soft);
    background: #f9fbfe;
  }

  .cs-table tr:last-child td {
    border-bottom: none;
  }

  .cs-tag {
    display: inline-block;
    border-radius: 999px;
    padding: 2px 8px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    border: 1px solid transparent;
  }

  .cs-tag.create { color: #0e6f42; background: #e8fbf1; border-color: #beeace; }
  .cs-tag.update { color: #7d4e00; background: #fff6e5; border-color: #f6deb0; }
  .cs-tag.delete { color: #9a2222; background: #fff0f0; border-color: #efc0c0; }

  .cs-apply-bar {
    margin-top: 14px;
    border: 1px solid var(--cs-border);
    border-radius: var(--cs-radius);
    background: linear-gradient(180deg, #ffffff, #f8fbff);
    box-shadow: var(--cs-shadow);
    padding: 14px;
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: center;
    flex-wrap: wrap;
  }

  .cs-apply-copy {
    font-size: 13px;
    color: var(--cs-ink-soft);
  }

  .cs-details {
    margin-top: 14px;
  }

  .cs-details summary {
    cursor: pointer;
    color: var(--cs-ink-soft);
    font-weight: 700;
    font-size: 12px;
  }

  .cs-json {
    margin-top: 8px;
    border: 1px solid var(--cs-border);
    border-radius: 10px;
    background: #0f1f2f;
    color: #d6f0ff;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 11px;
    padding: 10px;
    overflow: auto;
    max-height: 220px;
  }

  @media (max-width: 960px) {
    .cs-grid {
      grid-template-columns: 1fr;
    }

    .cs-kpi-grid {
      grid-template-columns: repeat(2, minmax(100px, 1fr));
    }
  }
</style>

<div class="cs-shell" id="csShell">
  <section class="cs-hero">
    <h2>Calendar Scheduler</h2>
    <p>Connection, preview, and apply flow for calendar-to-FPP synchronization.</p>
  </section>

  <section class="cs-grid">
    <article class="cs-card">
      <h3>1) Connection Setup</h3>
      <p class="cs-sub">First-time OAuth and calendar selection. Google enabled now, Outlook planned.</p>

      <div class="cs-provider-row">
        <span class="cs-chip is-active">Google</span>
        <span class="cs-chip is-muted">Outlook (Coming Soon)</span>
      </div>

      <div class="cs-field">
        <label>Connected Account</label>
        <input type="text" value="Not connected yet" readonly>
      </div>

      <div class="cs-field">
        <label>Sync Calendar</label>
        <select disabled>
          <option>Connect account to load calendars</option>
        </select>
      </div>

      <div class="cs-actions">
        <button class="cs-btn is-primary" type="button">Connect Google</button>
        <button class="cs-btn" type="button">Resync Calendar List</button>
      </div>
    </article>

    <article class="cs-card">
      <h3>2) Sync Preview</h3>
      <p class="cs-sub">Preview auto-refreshes on page load and after each change. User chooses when to apply.</p>

      <div class="cs-status-bar">
        Preview status: <strong id="csPreviewState">Needs Review</strong> • Last refresh: <span id="csPreviewTime">Pending</span>
      </div>

      <div class="cs-kpi-grid">
        <div class="cs-kpi is-warn">
          <strong id="csKpiCalendar">0</strong>
          <span>Calendar Changes</span>
        </div>
        <div class="cs-kpi is-warn">
          <strong id="csKpiFpp">0</strong>
          <span>FPP Changes</span>
        </div>
        <div class="cs-kpi is-ok">
          <strong id="csKpiNoop">No</strong>
          <span>In Sync</span>
        </div>
        <div class="cs-kpi">
          <strong id="csKpiTotal">0</strong>
          <span>Total Actions</span>
        </div>
      </div>

      <div class="cs-actions">
        <button class="cs-btn" type="button">Refresh Preview</button>
      </div>
    </article>
  </section>

  <section class="cs-card">
    <h3>3) Pending Actions</h3>
    <p class="cs-sub">Current preview action list (placeholder data until endpoint wiring).</p>
    <div class="cs-table-wrap">
      <table class="cs-table">
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
            <td><span class="cs-tag create">Create</span></td>
            <td>calendar</td>
            <td>Sample_Event</td>
            <td>Preview placeholder: endpoint wiring pending</td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>

  <section class="cs-apply-bar">
    <div class="cs-apply-copy">
      <strong>4) Apply Changes</strong><br>
      Apply uses the latest preview and writes updates to FPP and calendar.
    </div>
    <div class="cs-actions">
      <button class="cs-btn is-danger" type="button">Apply Changes</button>
    </div>
  </section>

  <details class="cs-details">
    <summary>Diagnostics (Design Mode)</summary>
    <pre class="cs-json" id="csDiagnosticJson">{
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
    var stamp = now.toLocaleDateString() + ' ' + now.toLocaleTimeString();
    var previewTime = document.getElementById('csPreviewTime');
    if (previewTime) {
      previewTime.textContent = stamp;
    }
  }());
</script>
