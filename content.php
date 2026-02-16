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

        <div class="mt-3 d-flex justify-content-end">
          <button class="buttons btn-success" id="csConnectBtn" type="button">Connect Provider</button>
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
    var manualAuthWatchTimer = null;
    var manualAuthInProgress = false;
    var manualCodePrompted = false;

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
      ["csConnectBtn"].forEach(function (id) {
        var node = byId(id);
        if (node) {
          node.disabled = disabled;
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
          clearManualAuthState();
        }
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

        var connectBtn = byId("csConnectBtn");
        connectBtn.dataset.authUrl = google.authUrl || "";
        connectBtn.textContent = providerConnected ? "Refresh Provider" : "Connect Provider";
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

    function clearManualAuthWatch() {
      if (manualAuthWatchTimer !== null) {
        window.clearInterval(manualAuthWatchTimer);
        manualAuthWatchTimer = null;
      }
    }

    function clearManualAuthState() {
      manualAuthInProgress = false;
      manualCodePrompted = false;
      clearManualAuthWatch();
    }

    function extractQueryParam(urlString, key) {
      try {
        var url = new URL(urlString);
        return url.searchParams.get(key);
      } catch (e) {
        return null;
      }
    }

    function extractCodeFromUserInput(input) {
      if (!input) {
        return "";
      }
      var text = String(input).trim();
      if (!text) {
        return "";
      }

      var urlCode = extractQueryParam(text, "code");
      if (urlCode) {
        return urlCode;
      }

      var match = text.match(/[?&]code=([^&]+)/);
      if (match && match[1]) {
        try {
          return decodeURIComponent(match[1]);
        } catch (e) {
          return match[1];
        }
      }

      return text;
    }

    function completeManualCodeExchange(code) {
      if (!code) {
        return;
      }
      setLoadingState();
      fetchJson({ action: "auth_exchange_code", code: code })
        .then(function () {
          clearManualAuthState();
          return refreshAll();
        })
        .catch(function (err) {
          manualCodePrompted = false;
          setError("OAuth code exchange failed: " + err.message);
        });
    }

    function maybePromptForManualCode() {
      if (!manualAuthInProgress || providerConnected || manualCodePrompted) {
        return;
      }
      manualCodePrompted = true;
      var pasted = window.prompt(
        "Paste the FULL Google return URL (preferred) or just the 'code' value to complete sign-in:"
      );
      var code = extractCodeFromUserInput(pasted);
      if (code) {
        completeManualCodeExchange(code);
        return;
      }
      manualCodePrompted = false;
    }

    function watchManualAuthPopup(popupWindow) {
      clearManualAuthWatch();
      manualAuthWatchTimer = window.setInterval(function () {
        if (!popupWindow || popupWindow.closed) {
          clearManualAuthWatch();
          maybePromptForManualCode();
          return;
        }

        var href = null;
        try {
          href = popupWindow.location.href;
        } catch (e) {
          return;
        }

        if (!href) {
          return;
        }

        var code = extractQueryParam(href, "code");
        var err = extractQueryParam(href, "error");
        if (err) {
          clearManualAuthWatch();
          manualAuthInProgress = false;
          setError("OAuth authorization failed: " + err);
          return;
        }
        if (code) {
          clearManualAuthWatch();
          try {
            popupWindow.close();
          } catch (e) {
            // ignore
          }
          completeManualCodeExchange(code);
        }
      }, 1000);
    }

    function fallbackManualAuth(message, popupWindow) {
      var url = byId("csConnectBtn").dataset.authUrl || "";
      if (!url) {
        setError(message + " Manual OAuth URL is unavailable.");
        return;
      }
      manualAuthInProgress = true;
      manualCodePrompted = false;
      setError(message + " Falling back to manual OAuth URL.");
      if (popupWindow && !popupWindow.closed) {
        popupWindow.location.href = url;
      } else {
        popupWindow = window.open(url, "_blank");
      }
      if (popupWindow) {
        watchManualAuthPopup(popupWindow);
      }
    }

    function pollDeviceAuth(deviceCode, intervalSeconds, popupWindow) {
      if (Date.now() >= deviceAuthDeadlineEpoch) {
        clearDeviceAuthPoll();
        fallbackManualAuth("Device authorization timed out.", popupWindow);
        return;
      }

      fetchJson({ action: "auth_device_poll", device_code: deviceCode })
        .then(function (res) {
          var poll = res.poll || {};
          if (poll.status === "connected") {
            clearDeviceAuthPoll();
            refreshAll();
            return;
          }

          if (poll.status === "failed") {
            clearDeviceAuthPoll();
            fallbackManualAuth("Device authorization failed (" + (poll.error || "unknown") + ").", popupWindow);
            return;
          }

          var nextInterval = intervalSeconds;
          if (poll.error === "slow_down") {
            nextInterval = Math.max(intervalSeconds + 2, intervalSeconds);
          }
          deviceAuthPollTimer = window.setTimeout(function () {
            pollDeviceAuth(deviceCode, nextInterval, popupWindow);
          }, nextInterval * 1000);
        })
        .catch(function (err) {
          clearDeviceAuthPoll();
          fallbackManualAuth("Device authorization polling error: " + err.message + ".", popupWindow);
        });
    }

    function startDeviceAuthFlow(popupWindow) {
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
            fallbackManualAuth("Device authorization response was incomplete.", popupWindow);
            return;
          }

          clearDeviceAuthPoll();
          deviceAuthDeadlineEpoch = Date.now() + (expiresIn * 1000);

          if (popupWindow && !popupWindow.closed) {
            popupWindow.location.href = verificationUrl;
          } else {
            window.open(verificationUrl, "_blank");
          }
          window.alert(
            "Complete Google sign-in in the opened tab.\n\n" +
            "If prompted, enter code: " + userCode + "\n\n" +
            "This page will auto-detect completion."
          );

          deviceAuthPollTimer = window.setTimeout(function () {
            pollDeviceAuth(deviceCode, interval, popupWindow);
          }, interval * 1000);
        })
        .catch(function (err) {
          fallbackManualAuth("Automatic device authorization could not start: " + err.message + ".", popupWindow);
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
        .then(function () { return runPreview(); })
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
      var popupWindow = window.open("about:blank", "_blank");
      if (!popupWindow) {
        setError("Popup was blocked. Allow popups for this FPP page and try again.");
        return;
      }
      startDeviceAuthFlow(popupWindow);
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
      refreshAll().finally(function () {
        maybePromptForManualCode();
      });
    });

    refreshAll();
  }());
</script>
