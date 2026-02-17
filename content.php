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
    display: none;
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

        <div id="csConnectionHelp" class="cs-device-box mb-2">
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

        <div class="form-group mb-2" id="csConnectedAccountGroup">
          <label for="csConnectedAccount">Connected Account</label>
          <input id="csConnectedAccount" type="text" class="form-control" value="Loading..." readonly>
        </div>

        <div class="form-group mb-2" id="csCalendarSelectGroup">
          <label for="csCalendarSelect">Sync Calendar</label>
          <select id="csCalendarSelect" class="form-control" disabled>
            <option>Loading calendars...</option>
          </select>
        </div>

        <div class="mt-3 d-flex justify-content-end gap-2">
          <button class="buttons btn-black" id="csDisconnectBtn" type="button" disabled>Disconnect Provider</button>
          <button class="buttons btn-success" id="csConnectBtn" type="button">Connect Provider</button>
        </div>

        <div id="csDeviceAuthBox" class="cs-device-box cs-hidden">
          <div><strong>Finish Google Sign-In</strong></div>
          <div class="mt-1">1) Open: <a id="csDeviceAuthLink" href="https://www.google.com/device" target="_blank" rel="noopener noreferrer">google.com/device</a></div>
          <div class="mt-1">2) Enter code: <span id="csDeviceAuthCode" class="cs-device-code">-</span></div>
          <div class="mt-1 cs-muted">Waiting for Google authorization completion...</div>
        </div>
      </div>
    </div>
  </div>

  <div class="backdrop mb-3">
    <h4 class="cs-panel-title">2) Pending Actions</h4>
    <p class="cs-muted">Primary view of all pending create/update/delete changes.</p>
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

<script>
  (function () {
    var API_URL = "plugin.php?plugin=GoogleCalendarScheduler&page=ui-api.php&nopage=1";
    var providerConnected = false;
    var deviceAuthPollTimer = null;
    var deviceAuthDeadlineEpoch = 0;

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
      ["csDisconnectBtn", "csConnectBtn", "csUploadDeviceClientBtn"].forEach(function (id) {
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
      var box = byId("csDeviceAuthBox");
      var codeNode = byId("csDeviceAuthCode");
      var link = byId("csDeviceAuthLink");
      if (!box || !codeNode || !link) {
        return;
      }
      if (visible) {
        codeNode.textContent = code || "-";
        link.href = url || "https://www.google.com/device";
        box.classList.remove("cs-hidden");
      } else {
        box.classList.add("cs-hidden");
        codeNode.textContent = "-";
        link.href = "https://www.google.com/device";
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
      setTopBarClass("alert-info");
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
      var thead = byId("csActionsHead");
      if (!tbody) {
        return 0;
      }

      var visibleActions = Array.isArray(actions) ? actions.filter(function (a) {
        return a && a.type && a.type !== "noop";
      }) : [];

      if (visibleActions.length === 0) {
        if (thead) {
          thead.style.display = "none";
        }
        tbody.innerHTML = "<tr><td colspan=\"4\" class=\"cs-muted\"><strong>No pending changes.</strong></td></tr>";
        return 0;
      }

      if (thead) {
        thead.style.display = "";
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

    function loadStatus() {
      return fetchJson({ action: "status" }).then(function (res) {
        var google = res.google || {};
        providerConnected = !!google.connected;
        if (providerConnected) {
          setDeviceAuthVisible(false);
          clearDeviceAuthPoll();
        } else {
          setDeviceAuthVisible(false);
        }
        renderConnectionHelp(google.setup || {}, providerConnected);
        var account = google.account || "Not connected yet";
        byId("csConnectedAccount").value = account;

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
        connectBtn.dataset.locked = "0";
        connectBtn.textContent = providerConnected ? "Refresh Provider" : "Connect Provider";
        disconnectBtn.dataset.locked = providerConnected ? "0" : "1";
        disconnectBtn.disabled = !providerConnected;
        uploadBtn.dataset.locked = providerConnected ? "1" : "0";
        uploadBtn.disabled = providerConnected;

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
          window.open(verificationUrl, "_blank");

          deviceAuthPollTimer = window.setTimeout(function () {
            pollDeviceAuth(deviceCode, interval);
          }, interval * 1000);
        })
        .catch(function (err) {
          setDeviceAuthVisible(false);
          setError("Automatic device authorization could not start: " + err.message + ".");
        });
    }

    var refreshInFlight = false;
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
