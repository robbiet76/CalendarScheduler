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

  .cs-top-status,
  .cs-top-status strong,
  .cs-top-status span {
    color: #111 !important;
  }

  .cs-json {
    margin: 8px 0 0;
    max-height: 220px;
    overflow: auto;
    font-size: 12px;
  }

  .cs-hidden {
    display: none;
  }
</style>

<div class="cs-page" id="csShell">
  <div class="alert alert-info d-flex justify-content-between align-items-center flex-wrap cs-top-status" role="alert" id="csTopStatusBar">
    <div>
      Status: <strong id="csPreviewState">Loading...</strong> |
      Last refresh: <span id="csPreviewTime">Pending</span>
    </div>
  </div>

  <div class="row g-2">
    <div class="col-12">
      <div class="backdrop mb-3">
        <h4 class="cs-panel-title">1) Connection Setup</h4>
        <p class="cs-muted">First-time OAuth and calendar selection. Additional providers can be added over time.</p>

        <div class="mb-2">
          <span class="badge text-bg-primary">Google</span>
          <span class="badge text-bg-secondary">Outlook (Coming Soon)</span>
        </div>

        <div class="form-group mb-2">
          <label for="csConnectedAccount">Connected Account</label>
          <input id="csConnectedAccount" type="text" class="form-control" value="Loading..." readonly>
        </div>

        <div class="form-group mb-2">
          <label for="csCalendarSelect">Sync Calendar</label>
          <select id="csCalendarSelect" class="form-control" disabled>
            <option>Loading calendars...</option>
          </select>
        </div>

        <div class="mt-3">
          <button class="buttons btn-success" id="csConnectBtn" type="button">Connect Provider</button>
          <button class="buttons btn-detract" id="csResyncCalendarsBtn" type="button">Resync Calendar List</button>
        </div>
      </div>
    </div>
  </div>

  <div class="backdrop mb-3">
    <h4 class="cs-panel-title">2) Pending Actions</h4>
    <p class="cs-muted">Primary view of all pending create/update/delete changes.</p>
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
      <strong>3) Apply Changes</strong><br>
      Apply uses the latest preview and writes updates to FPP and calendar.
    </div>
    <div class="mt-2 mt-md-0">
      <button class="buttons btn-danger" id="csApplyBtn" type="button">Apply Changes</button>
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
    var API_URL = "plugin.php?plugin=GoogleCalendarScheduler&page=ui-api.php&nopage=1";

    function byId(id) {
      return document.getElementById(id);
    }

    function escapeHtml(value) {
      return String(value)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/\"/g, "&quot;")
        .replace(/'/g, "&#039;");
    }

    function setButtonsDisabled(disabled) {
      ["csConnectBtn", "csResyncCalendarsBtn", "csApplyBtn"].forEach(function (id) {
        var node = byId(id);
        if (node) {
          node.disabled = disabled;
        }
      });
    }

    function setTopBarClass(className) {
      var bar = byId("csTopStatusBar");
      if (!bar) {
        return;
      }

      ["alert-info", "alert-success", "alert-warning", "alert-danger"].forEach(function (name) {
        bar.classList.remove(name);
      });
      bar.classList.add(className);
    }

    function setError(message) {
      if (!message) {
        return;
      }
      byId("csPreviewState").textContent = "Error";
      setTopBarClass("alert-danger");
      byId("csPreviewTime").textContent = message;
    }

    function renderDiagnostics(payload) {
      var out = byId("csDiagnosticJson");
      if (!out) {
        return;
      }
      out.textContent = JSON.stringify(payload, null, 2);
    }

    function fetchJson(payload) {
      return fetch(API_URL, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "same-origin",
        body: JSON.stringify(payload)
      }).then(function (res) {
        return res.json().then(function (json) {
          if (!res.ok || !json.ok) {
            var msg = json && json.error ? json.error : ("Request failed (" + res.status + ")");
            throw new Error(msg);
          }
          return json;
        });
      });
    }

    function renderActions(actions) {
      var tbody = byId("csActionsRows");
      if (!tbody) {
        return;
      }

      var visibleActions = Array.isArray(actions) ? actions.filter(function (a) {
        return a && a.type && a.type !== "noop";
      }) : [];

      if (visibleActions.length === 0) {
        tbody.innerHTML = "<tr><td colspan=\"4\" class=\"cs-muted\">No pending changes.</td></tr>";
        return;
      }

      var html = visibleActions.map(function (a) {
        var badgeClass = "text-bg-secondary";
        if (a.type === "create") {
          badgeClass = "text-bg-success";
        } else if (a.type === "update") {
          badgeClass = "text-bg-dark";
        } else if (a.type === "delete" || a.type === "block") {
          badgeClass = "text-bg-danger";
        }
        var eventName = a.event && a.event.target ? a.event.target : "-";
        var reason = a.reason || "-";
        return "<tr>"
          + "<td><span class=\"badge " + badgeClass + "\">" + escapeHtml(String(a.type || "").toUpperCase()) + "</span></td>"
          + "<td>" + escapeHtml(a.target || "-") + "</td>"
          + "<td>" + escapeHtml(eventName) + "</td>"
          + "<td>" + escapeHtml(reason) + "</td>"
          + "</tr>";
      }).join("");

      tbody.innerHTML = html;
    }

    function renderPreview(preview) {
      var stamp = preview.generatedAtUtc ? new Date(preview.generatedAtUtc).toLocaleString() : "Unknown";
      byId("csPreviewTime").textContent = stamp;
      byId("csPreviewState").textContent = preview.noop ? "In Sync" : "Needs Review";

      var c = preview.counts || {};

      if (preview.noop) {
        setTopBarClass("alert-success");
      } else {
        setTopBarClass("alert-warning");
      }

      renderActions(preview.actions || []);
    }

    function loadStatus() {
      return fetchJson({ action: "status" }).then(function (res) {
        var google = res.google || {};
        var account = google.account || "Not connected yet";
        byId("csConnectedAccount").value = account;

        var select = byId("csCalendarSelect");
        var calendars = Array.isArray(google.calendars) ? google.calendars : [];
        if (calendars.length === 0) {
          select.innerHTML = "<option>Connect account to load calendars</option>";
          select.disabled = true;
        } else {
          select.innerHTML = calendars.map(function (c) {
            var selected = c.id === google.selectedCalendarId ? " selected" : "";
            var label = c.primary ? (c.summary + " (Primary)") : c.summary;
            return "<option value=\"" + escapeHtml(c.id) + "\"" + selected + ">" + escapeHtml(label) + "</option>";
          }).join("");
          select.disabled = false;
        }

        byId("csConnectBtn").dataset.authUrl = google.authUrl || "";
        renderDiagnostics(res);
      });
    }

    function runPreview() {
      return fetchJson({ action: "preview" }).then(function (res) {
        renderPreview(res.preview || {});
        renderDiagnostics(res);
      });
    }

    function runApply() {
      return fetchJson({ action: "apply" }).then(function (res) {
        renderPreview(res.preview || {});
        renderDiagnostics(res);
      });
    }

    byId("csConnectBtn").addEventListener("click", function () {
      var url = this.dataset.authUrl || "";
      if (!url) {
        setError("OAuth URL is unavailable. Check Google client configuration.");
        return;
      }
      window.open(url, "_blank");
      setError("After completing OAuth in the new tab, click 'Resync Calendar List'.");
    });

    byId("csResyncCalendarsBtn").addEventListener("click", function () {
      setButtonsDisabled(true);
      loadStatus()
        .then(function () { return runPreview(); })
        .catch(function (err) { setError(err.message); })
        .finally(function () { setButtonsDisabled(false); });
    });

    byId("csCalendarSelect").addEventListener("change", function () {
      var calendarId = this.value || "";
      if (!calendarId) {
        return;
      }
      setButtonsDisabled(true);
      fetchJson({ action: "set_calendar", calendar_id: calendarId })
        .then(function () { return runPreview(); })
        .catch(function (err) { setError(err.message); })
        .finally(function () { setButtonsDisabled(false); });
    });

    byId("csApplyBtn").addEventListener("click", function () {
      setButtonsDisabled(true);
      runApply()
        .catch(function (err) { setError(err.message); })
        .finally(function () { setButtonsDisabled(false); });
    });

    setButtonsDisabled(true);
    loadStatus()
      .then(function () { return runPreview(); })
      .catch(function (err) { setError(err.message); })
      .finally(function () { setButtonsDisabled(false); });
  }());
</script>
