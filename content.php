<?php
/**
 * Calendar Scheduler — Primary Plugin UI
 *
 * File: content.php
 * Purpose: Render the Calendar Scheduler web interface for OAuth connection,
 * sync preview, pending actions, and user-driven apply operations.
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

  .cs-status-loading {
    background-color: #b7b7b7 !important;
    border-color: #9c9c9c !important;
  }

  #csShell .table td,
  #csShell .table th {
    padding-left: 14px;
    padding-right: 14px;
  }

  #csApplyBtn:disabled {
    opacity: 0.45;
    cursor: not-allowed;
  }

  .cs-json {
    margin: 8px 0 0;
    max-height: 220px;
    overflow: auto;
    font-size: 12px;
  }

  .cs-hidden {
    display: none !important;
  }

  .cs-help-list {
    margin-bottom: 8px;
    padding-left: 18px;
  }

  .cs-help-check-ok {
    color: #1b7f3a;
    font-weight: 600;
  }

  .cs-help-check-bad {
    color: #b02a37;
    font-weight: 600;
  }

  .cs-device-box {
    border: 1px solid #cfd3d7;
    border-radius: 4px;
    background: #f7f7f7;
    padding: 10px 12px;
    margin-top: 10px;
  }

  .cs-device-code {
    font-size: 18px;
    font-weight: 700;
    letter-spacing: 1px;
  }

  .cs-modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.45);
    z-index: 1040;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
  }

  .cs-modal {
    width: min(560px, 100%);
    background: #fff;
    border-radius: 6px;
    box-shadow: 0 12px 28px rgba(0, 0, 0, 0.3);
    overflow: hidden;
  }

  .cs-modal-header,
  .cs-modal-footer {
    padding: 10px 14px;
    border-bottom: 1px solid #dee2e6;
  }

  .cs-modal-footer {
    border-bottom: 0;
    border-top: 1px solid #dee2e6;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
  }

  .cs-modal-body {
    padding: 12px 14px;
  }
</style>

<div class="cs-page" id="csShell">
  <div class="alert alert-info d-flex justify-content-between align-items-center flex-wrap cs-top-status" role="alert" id="csTopStatusBar">
    <div>
      Status: <strong id="csPreviewState">Loading...</strong> |
      Last refresh: <span id="csPreviewTime">Pending</span>
    </div>
  </div>

  <div id="csMainBody" class="cs-hidden">
  <div class="row g-2">
    <div class="col-12">
      <div class="backdrop mb-3">
        <h4 class="cs-panel-title">1) Connection Setup</h4>
        <p class="cs-muted" id="csConnectionSubtitle">Connect to a calendar using OAuth. Select calendar provider.</p>

        <div class="mb-2">
          <span class="badge text-bg-primary" id="csProviderGoogleBadge">Google</span>
          <span class="badge text-bg-secondary" id="csProviderOutlookBadge">Outlook (Coming Soon)</span>
        </div>

        <div id="csConnectionHelp" class="cs-device-box mb-2 cs-hidden">
          <div><strong>Google OAuth Setup</strong></div>
          <ol class="cs-help-list mt-1 mb-2">
            <li>In Google Cloud Console, enable <strong>Google Calendar API</strong>.</li>
            <li>Create OAuth credentials of type <strong>TV and Limited Input</strong>.</li>
            <li>Download the client JSON and upload it below.</li>
            <li>Click <strong>Connect Provider</strong>, open <code>google.com/device</code>, and enter the code shown.</li>
          </ol>
          <div class="row g-2 align-items-end mb-2">
            <div class="col-12 col-md-8">
              <label for="csDeviceClientFile" class="form-label mb-1">Upload client secret JSON:</label>
              <input id="csDeviceClientFile" type="file" class="form-control" accept="application/json,.json">
            </div>
            <div class="col-12 col-md-4 d-flex justify-content-md-end">
              <button class="buttons btn-black" id="csUploadDeviceClientBtn" type="button">Upload Client JSON</button>
            </div>
          </div>
          <div class="mb-1"><strong>Current Setup Checks</strong></div>
          <ul id="csHelpChecks" class="mb-1"></ul>
          <div class="mb-1"><strong>Current Setup Hints</strong></div>
          <ul id="csHelpHints" class="mb-0"></ul>
        </div>

        <div class="mb-2 cs-hidden" id="csConnectedAccountGroup">
          <strong>Connected Account:</strong> <span id="csConnectedAccountValue">Loading...</span>
        </div>

        <div class="form-group mb-2 cs-hidden" id="csCalendarSelectGroup">
          <label for="csCalendarSelect">Sync Calendar</label>
          <select id="csCalendarSelect" class="form-control" disabled>
            <option>Loading calendars...</option>
          </select>
        </div>

        <div class="mt-3 mb-2 d-flex justify-content-end gap-2">
          <button class="buttons btn-black" id="csDisconnectBtn" type="button" disabled>Disconnect Provider</button>
          <button class="buttons btn-success" id="csConnectBtn" type="button">Connect Provider</button>
        </div>

      </div>
    </div>
  </div>

  <div id="csDeviceAuthModalWrap" class="cs-modal-backdrop cs-hidden" role="dialog" aria-modal="true" aria-labelledby="csDeviceAuthModalTitle">
    <div class="cs-modal">
      <div class="cs-modal-header">
        <strong id="csDeviceAuthModalTitle">Finish Google Sign-In</strong>
      </div>
      <div class="cs-modal-body">
        <div class="mb-1">1) Open: <a id="csDeviceAuthLink" href="https://www.google.com/device" target="_blank" rel="noopener noreferrer">google.com/device</a></div>
        <div class="mb-1">2) Enter code:
          <span id="csDeviceAuthCode" class="cs-device-code">-</span>
          <button id="csCopyDeviceCodeBtn" type="button" class="buttons btn-black btn-sm">Copy</button>
        </div>
        <div class="cs-muted">Waiting for Google authorization completion...</div>
      </div>
      <div class="cs-modal-footer">
        <a id="csDeviceAuthOpenBtn" class="buttons btn-black" href="https://www.google.com/device" target="_blank" rel="noopener noreferrer">Open Google Device Page</a>
        <button id="csDeviceAuthCancelBtn" type="button" class="buttons btn-black">Cancel</button>
      </div>
    </div>
  </div>

  <div class="backdrop mb-3">
    <h4 class="cs-panel-title">2) Pending Actions</h4>
    <p class="cs-muted">Primary view of all pending create/update/delete changes.</p>
    <div id="csSyncModeWrap" class="form-group mb-2 cs-hidden">
      <label for="csSyncModeSelect">Sync Mode</label>
      <select id="csSyncModeSelect" class="form-control">
        <option value="both" selected>Two-way Merge (Both)</option>
        <option value="calendar">Calendar -> FPP</option>
        <option value="fpp">FPP -> Calendar</option>
      </select>
    </div>
    <div class="table-responsive">
      <table class="table table-sm table-hover">
        <thead id="csActionsHead">
          <tr>
            <th>Action</th>
            <th>Target</th>
            <th>Event</th>
            <th>Reason</th>
          </tr>
        </thead>
        <tbody id="csActionsRows"></tbody>
      </table>
    </div>
  </div>

  <div class="backdrop mb-3">
    <h4 class="cs-panel-title">3) Apply Changes</h4>
    <p class="cs-muted">Apply uses the latest preview and writes updates to FPP and calendar.</p>
    <div class="d-flex justify-content-end">
      <button class="buttons btn-success" id="csApplyBtn" type="button" disabled>Apply Changes</button>
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
</div>

<script>
  (function () {
    // -----------------------------------------------------------------------
    // Shared state + element utilities
    // -----------------------------------------------------------------------
    var API_URL = "plugin.php?plugin=GoogleCalendarScheduler&page=ui-api.php&nopage=1";
    var providerConnected = false;
    var deviceAuthPollTimer = null;
    var deviceAuthDeadlineEpoch = 0;
    var syncMode = "both";

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

    // -----------------------------------------------------------------------
    // Global UI state helpers
    // -----------------------------------------------------------------------
    function setButtonsDisabled(disabled) {
      ["csDisconnectBtn", "csConnectBtn", "csUploadDeviceClientBtn", "csSyncModeSelect"].forEach(function (id) {
        var node = byId(id);
        if (node) {
          if (!disabled && node.dataset.locked === "1") {
            node.disabled = true;
          } else {
            node.disabled = disabled;
          }
        }
      });
    }

    function setApplyEnabled(enabled) {
      var applyBtn = byId("csApplyBtn");
      if (!applyBtn) {
        return;
      }
      applyBtn.disabled = !enabled;
    }

    function setTopBarClass(className) {
      var bar = byId("csTopStatusBar");
      if (!bar) {
        return;
      }

      ["alert-info", "alert-success", "alert-warning", "alert-danger", "cs-status-loading"].forEach(function (name) {
        bar.classList.remove(name);
      });
      bar.classList.add(className);
    }

    function setLoadingState() {
      byId("csPreviewState").textContent = "Loading";
      byId("csPreviewTime").textContent = "Updating...";
      setTopBarClass("cs-status-loading");
    }

    function setDeviceAuthVisible(visible, code, url) {
      var wrap = byId("csDeviceAuthModalWrap");
      var codeNode = byId("csDeviceAuthCode");
      var link = byId("csDeviceAuthLink");
      var openBtn = byId("csDeviceAuthOpenBtn");
      var copyBtn = byId("csCopyDeviceCodeBtn");
      if (!wrap || !codeNode || !link || !openBtn || !copyBtn) {
        return;
      }
      if (visible) {
        var authCode = code || "-";
        codeNode.textContent = authCode;
        copyBtn.disabled = authCode === "-";
        var dest = url || "https://www.google.com/device";
        link.href = dest;
        openBtn.href = dest;
        wrap.classList.remove("cs-hidden");
      } else {
        wrap.classList.add("cs-hidden");
        codeNode.textContent = "-";
        copyBtn.disabled = true;
        link.href = "https://www.google.com/device";
        openBtn.href = "https://www.google.com/device";
      }
    }

    function setError(message) {
      if (!message) {
        return;
      }
      byId("csPreviewState").textContent = "Error";
      setTopBarClass("alert-danger");
      byId("csPreviewTime").textContent = message;
    }

    function setSetupStatus(message) {
      byId("csPreviewState").textContent = "Setup Required";
      byId("csPreviewTime").textContent = message || "Connect a provider to begin calendar sync.";
      setTopBarClass("alert-warning");
    }

    function checkRow(label, ok) {
      return "<li>" + escapeHtml(label) + ": "
        + "<span class=\"" + (ok ? "cs-help-check-ok" : "cs-help-check-bad") + "\">"
        + (ok ? "OK" : "Missing")
        + "</span></li>";
    }

    function renderConnectionHelp(setup, connected) {
      var box = byId("csConnectionHelp");
      var checksNode = byId("csHelpChecks");
      var hintsNode = byId("csHelpHints");
      if (!box || !checksNode || !hintsNode) {
        return;
      }

      if (connected) {
        box.classList.add("cs-hidden");
        checksNode.innerHTML = "";
        hintsNode.innerHTML = "";
        return;
      }

      box.classList.remove("cs-hidden");
      setup = setup || {};

      checksNode.innerHTML = [
        checkRow("Device client file present", !!setup.clientFilePresent),
        checkRow("Config present", !!setup.configPresent),
        checkRow("Config valid", !!setup.configValid),
        checkRow("Token directory writable", !!setup.tokenPathWritable),
        checkRow("Device flow ready", !!setup.deviceFlowReady)
      ].join("");

      var hints = Array.isArray(setup.hints) ? setup.hints : [];
      if (hints.length === 0) {
        hintsNode.innerHTML = "<li class=\"cs-help-check-ok\">No setup issues detected.</li>";
      } else {
        hintsNode.innerHTML = hints.map(function (hint) {
          return "<li>" + escapeHtml(hint) + "</li>";
        }).join("");
      }
    }

    // -----------------------------------------------------------------------
    // API + rendering helpers
    // -----------------------------------------------------------------------
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

    // Render pending actions table and return count of non-noop rows.
    function renderActions(actions) {
      var tbody = byId("csActionsRows");
      var thead = byId("csActionsHead");
      if (!tbody) {
        return 0;
      }

      var visibleActions = Array.isArray(actions) ? actions.filter(function (a) {
        return a && a.type && a.type !== "noop";
      }) : [];

      if (visibleActions.length === 0) {
        if (thead) {
          thead.classList.add("cs-hidden");
        }
        tbody.innerHTML = "<tr><td colspan=\"4\" class=\"cs-muted\"><strong>No pending changes.</strong></td></tr>";
        return 0;
      }

      if (thead) {
        thead.classList.remove("cs-hidden");
      }

      function friendlyReason(raw, actionType, target) {
        var r = String(raw || "").trim();
        var action = String(actionType || "").toLowerCase();
        var side = String(target || "").toLowerCase() === "fpp" ? "FPP" : "calendar";
        var sideNoun = side === "FPP" ? "entry" : "event";
        if (!r) {
          return "-";
        }

        if (r.indexOf("already converged") !== -1 || r.indexOf("already matches") !== -1) {
          return "No differences detected.";
        }

        if (action === "create") {
          if (r.indexOf("calendar absent") !== -1) {
            return "Add this event in calendar to match the FPP entry.";
          }
          if (r.indexOf("fpp absent") !== -1) {
            return "Add this entry in FPP to match the calendar event.";
          }
          if (r.indexOf("present side wins") !== -1) {
            return "Add missing " + sideNoun + " on " + side + " to keep both sides in sync.";
          }
          return "Create this " + sideNoun + " on " + side + " to keep schedules aligned.";
        }

        if (action === "delete") {
          if (r.indexOf("calendar tombstone") !== -1 || r.indexOf("calendar newer") !== -1) {
            return "Delete the FPP entry to match the calendar event removal.";
          }
          if (r.indexOf("fpp tombstone") !== -1 || r.indexOf("fpp newer") !== -1) {
            return "Delete the calendar event to match the FPP entry removal.";
          }
          return "Delete this " + sideNoun + " from " + side + " to keep both sides aligned.";
        }

        if (action === "update") {
          if (r.indexOf("force format refresh update") !== -1) {
            return "Refresh calendar event formatting.";
          }
          if (r.indexOf("calendar newer") !== -1) {
            return "Update FPP entry to match newer calendar event changes.";
          }
          if (r.indexOf("fpp newer") !== -1) {
            return "Update calendar event to match newer FPP entry changes.";
          }
          if (r.indexOf("tie (") !== -1 && r.indexOf("fpp wins") !== -1) {
            return "Both changed at the same time; updating using FPP entry values.";
          }
          if (r.indexOf("tie (") !== -1 && r.indexOf("calendar wins") !== -1) {
            return "Both changed at the same time; updating using calendar event values.";
          }
          return "Update this " + sideNoun + " on " + side + " to keep both schedules synchronized.";
        }

        return r;
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
        var reason = friendlyReason(a.reason || "-", a.type || "", a.target || "");
        return "<tr>"
          + "<td><span class=\"badge " + badgeClass + "\">" + escapeHtml(String(a.type || "").toUpperCase()) + "</span></td>"
          + "<td>" + escapeHtml(a.target || "-") + "</td>"
          + "<td>" + escapeHtml(eventName) + "</td>"
          + "<td>" + escapeHtml(reason) + "</td>"
          + "</tr>";
      }).join("");

      tbody.innerHTML = html;
      return visibleActions.length;
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

      var pendingCount = renderActions(preview.actions || []);
      setApplyEnabled(pendingCount > 0);
    }

    // Pull provider state and update setup/connection controls.
    function loadStatus() {
      return fetchJson({ action: "status" }).then(function (res) {
        var google = res.google || {};
        syncMode = (typeof res.syncMode === "string" && res.syncMode) ? res.syncMode : "both";
        providerConnected = !!google.connected;
        if (providerConnected) {
          setDeviceAuthVisible(false);
          clearDeviceAuthPoll();
        } else {
          setDeviceAuthVisible(false);
        }
        renderConnectionHelp(google.setup || {}, providerConnected);
        var subtitle = byId("csConnectionSubtitle");
        if (subtitle) {
          subtitle.textContent = providerConnected
            ? "Connect to a calendar using OAuth."
            : "Connect to a calendar using OAuth. Select calendar provider.";
        }
        var account = google.account || "Not connected yet";
        var accountValue = byId("csConnectedAccountValue");
        if (accountValue) {
          accountValue.textContent = account;
        }

        var select = byId("csCalendarSelect");
        var connectedAccountGroup = byId("csConnectedAccountGroup");
        var calendarSelectGroup = byId("csCalendarSelectGroup");
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
        if (connectedAccountGroup) {
          connectedAccountGroup.classList.toggle("cs-hidden", !providerConnected);
        }
        if (calendarSelectGroup) {
          calendarSelectGroup.classList.toggle("cs-hidden", !providerConnected);
        }

        var connectBtn = byId("csConnectBtn");
        var disconnectBtn = byId("csDisconnectBtn");
        var uploadBtn = byId("csUploadDeviceClientBtn");
        var syncModeWrap = byId("csSyncModeWrap");
        var syncModeSelect = byId("csSyncModeSelect");
        var googleBadge = byId("csProviderGoogleBadge");
        var outlookBadge = byId("csProviderOutlookBadge");
        connectBtn.dataset.locked = "0";
        connectBtn.textContent = providerConnected ? "Refresh Provider" : "Connect Provider";
        disconnectBtn.dataset.locked = providerConnected ? "0" : "1";
        disconnectBtn.disabled = !providerConnected;
        uploadBtn.dataset.locked = providerConnected ? "1" : "0";
        uploadBtn.disabled = providerConnected;
        if (syncModeSelect) {
          syncModeSelect.value = syncMode;
          syncModeSelect.dataset.locked = providerConnected ? "0" : "1";
          syncModeSelect.disabled = !providerConnected;
        }
        if (syncModeWrap) {
          syncModeWrap.classList.toggle("cs-hidden", !providerConnected);
        }
        if (googleBadge) {
          googleBadge.classList.remove("cs-hidden");
        }
        if (outlookBadge) {
          outlookBadge.classList.toggle("cs-hidden", providerConnected);
        }

        var setup = google.setup || {};
        var deviceReady = !!setup.deviceFlowReady;
        if (!providerConnected && !deviceReady) {
          connectBtn.dataset.locked = "1";
          connectBtn.disabled = true;
          var hints = Array.isArray(setup.hints) ? setup.hints : [];
          var msg = hints.length > 0 ? hints.join(" | ") : "Provider setup is incomplete.";
          setSetupStatus(msg);
        } else if (!providerConnected) {
          setSetupStatus("Not connected. Click Connect Provider to start Google device sign-in.");
        }
        renderDiagnostics(res);
      });
    }

    // Execute reconciliation preview and render pending changes.
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

    // -----------------------------------------------------------------------
    // Device OAuth modal/polling flow
    // -----------------------------------------------------------------------
    function clearDeviceAuthPoll() {
      if (deviceAuthPollTimer !== null) {
        window.clearTimeout(deviceAuthPollTimer);
        deviceAuthPollTimer = null;
      }
      deviceAuthDeadlineEpoch = 0;
    }

    function pollDeviceAuth(deviceCode, intervalSeconds) {
      if (Date.now() >= deviceAuthDeadlineEpoch) {
        clearDeviceAuthPoll();
        setDeviceAuthVisible(false);
        setError("Device authorization timed out. Click Connect Provider to try again.");
        return;
      }

      fetchJson({ action: "auth_device_poll", device_code: deviceCode })
        .then(function (res) {
          var poll = res.poll || {};
          if (poll.status === "connected") {
            clearDeviceAuthPoll();
            setDeviceAuthVisible(false);
            refreshAll();
            return;
          }

          if (poll.status === "failed") {
            clearDeviceAuthPoll();
            setDeviceAuthVisible(false);
            setError("Device authorization failed (" + (poll.error || "unknown") + ").");
            return;
          }

          var nextInterval = intervalSeconds;
          if (poll.error === "slow_down") {
            nextInterval = Math.max(intervalSeconds + 2, intervalSeconds);
          }
          deviceAuthPollTimer = window.setTimeout(function () {
            pollDeviceAuth(deviceCode, nextInterval);
          }, nextInterval * 1000);
        })
        .catch(function (err) {
          clearDeviceAuthPoll();
          setDeviceAuthVisible(false);
          setError("Device authorization polling error: " + err.message + ".");
        });
    }

    function startDeviceAuthFlow() {
      setLoadingState();
      fetchJson({ action: "auth_device_start" })
        .then(function (res) {
          var device = res.device || {};
          var deviceCode = device.device_code || "";
          var userCode = device.user_code || "";
          var verificationUrl = device.verification_url_complete || device.verification_url || "https://www.google.com/device";
          var interval = Math.max(parseInt(device.interval || 5, 10), 3);
          var expiresIn = Math.max(parseInt(device.expires_in || 900, 10), 60);

          if (!deviceCode || !userCode) {
            setError("Device authorization response was incomplete.");
            return;
          }

          clearDeviceAuthPoll();
          deviceAuthDeadlineEpoch = Date.now() + (expiresIn * 1000);
          setDeviceAuthVisible(true, userCode, verificationUrl);

          deviceAuthPollTimer = window.setTimeout(function () {
            pollDeviceAuth(deviceCode, interval);
          }, interval * 1000);
        })
        .catch(function (err) {
          setDeviceAuthVisible(false);
          setError("Automatic device authorization could not start: " + err.message + ".");
        });
    }

    // -----------------------------------------------------------------------
    // Orchestration: refresh, bind events, initialize page
    // -----------------------------------------------------------------------
    var refreshInFlight = false;
    var initialRenderDone = false;
    function refreshAll() {
      if (refreshInFlight) {
        return Promise.resolve();
      }
      refreshInFlight = true;
      setButtonsDisabled(true);
      setLoadingState();
      return loadStatus()
        .then(function () {
          if (!providerConnected) {
            renderActions([]);
            setApplyEnabled(false);
            return Promise.resolve();
          }
          return runPreview();
        })
        .catch(function (err) { setError(err.message); })
        .finally(function () {
          if (!initialRenderDone) {
            var body = byId("csMainBody");
            if (body) {
              body.classList.remove("cs-hidden");
            }
            initialRenderDone = true;
          }
          refreshInFlight = false;
          setButtonsDisabled(false);
        });
    }

    byId("csConnectBtn").addEventListener("click", function () {
      if (providerConnected) {
        refreshAll();
        return;
      }
      startDeviceAuthFlow();
    });

    byId("csDisconnectBtn").addEventListener("click", function () {
      if (!providerConnected) {
        return;
      }
      if (!window.confirm("Disconnect provider and remove the local Google token from this FPP instance?")) {
        return;
      }
      setButtonsDisabled(true);
      setLoadingState();
      fetchJson({ action: "auth_disconnect" })
        .then(function () {
          setDeviceAuthVisible(false);
          clearDeviceAuthPoll();
          return refreshAll();
        })
        .catch(function (err) { setError(err.message); })
        .finally(function () { setButtonsDisabled(false); });
    });

    byId("csUploadDeviceClientBtn").addEventListener("click", function () {
      var input = byId("csDeviceClientFile");
      var file = input && input.files ? input.files[0] : null;
      if (!file) {
        setSetupStatus("Select a JSON file first, then click Upload Client JSON.");
        return;
      }
      if (file.size > 1024 * 1024) {
        setError("Client JSON is too large. Expected OAuth client JSON file.");
        return;
      }
      setButtonsDisabled(true);
      setLoadingState();
      var reader = new FileReader();
      reader.onload = function () {
        var text = typeof reader.result === "string" ? reader.result : "";
        fetchJson({
          action: "auth_upload_device_client",
          filename: file.name || "client_secret_device.json",
          json: text
        })
          .then(function () {
            if (input) {
              input.value = "";
            }
            return refreshAll();
          })
          .catch(function (err) { setError(err.message); })
          .finally(function () { setButtonsDisabled(false); });
      };
      reader.onerror = function () {
        setButtonsDisabled(false);
        setError("Failed to read selected file.");
      };
      reader.readAsText(file);
    });

    byId("csDeviceAuthCancelBtn").addEventListener("click", function () {
      clearDeviceAuthPoll();
      setDeviceAuthVisible(false);
      setSetupStatus("Sign-in canceled. Click Connect Provider to start again.");
    });

    byId("csCopyDeviceCodeBtn").addEventListener("click", function () {
      var codeNode = byId("csDeviceAuthCode");
      var value = codeNode ? (codeNode.textContent || "").trim() : "";
      if (!value || value === "-") {
        return;
      }

      var done = function () {
        setSetupStatus("Device code copied to clipboard.");
      };
      var fail = function () {
        setError("Clipboard copy failed. Check browser clipboard permissions.");
      };

      // Fallback for insecure HTTP contexts where navigator.clipboard is blocked.
      var legacyCopy = function (text) {
        var ta = document.createElement("textarea");
        ta.value = text;
        ta.setAttribute("readonly", "");
        ta.style.position = "fixed";
        ta.style.left = "-9999px";
        ta.style.top = "0";
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        ta.setSelectionRange(0, ta.value.length);
        var ok = false;
        try {
          ok = document.execCommand("copy");
        } catch (e) {
          ok = false;
        }
        document.body.removeChild(ta);
        return ok;
      };

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(value).then(done).catch(function () {
          if (legacyCopy(value)) {
            done();
          } else {
            fail();
          }
        });
        return;
      }

      if (legacyCopy(value)) {
        done();
      } else {
        fail();
      }
    });

    byId("csCalendarSelect").addEventListener("change", function () {
      var calendarId = this.value || "";
      if (!calendarId) {
        return;
      }
      setButtonsDisabled(true);
      fetchJson({ action: "set_calendar", calendar_id: calendarId })
        .then(function () { return refreshAll(); })
        .catch(function (err) { setError(err.message); })
        .finally(function () { setButtonsDisabled(false); });
    });

    byId("csSyncModeSelect").addEventListener("change", function () {
      var value = this.value || "both";
      setButtonsDisabled(true);
      fetchJson({ action: "set_sync_mode", sync_mode: value })
        .then(function (res) {
          if (res && typeof res.syncMode === "string" && res.syncMode) {
            syncMode = res.syncMode;
            byId("csSyncModeSelect").value = syncMode;
          }
          return refreshAll();
        })
        .catch(function (err) { setError(err.message); })
        .finally(function () { setButtonsDisabled(false); });
    });

    byId("csApplyBtn").addEventListener("click", function () {
      if (!window.confirm("Apply all pending changes to FPP and the connected calendar now?")) {
        return;
      }
      setButtonsDisabled(true);
      setLoadingState();
      runApply()
        .catch(function (err) { setError(err.message); })
        .finally(function () { setButtonsDisabled(false); });
    });

    window.addEventListener("focus", function () {
      refreshAll();
    });

    refreshAll();
  }());
</script>
