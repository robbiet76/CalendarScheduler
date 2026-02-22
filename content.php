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

  .cs-top-status {
    position: sticky;
    top: var(--cs-sticky-top, 8px);
    z-index: 10;
    padding: 8px 12px;
    margin-bottom: 12px;
    width: 100%;
    box-sizing: border-box;
    transition: padding 0.15s ease, font-size 0.15s ease, opacity 0.15s ease, width 0.15s ease;
  }

  .cs-top-status.cs-top-status-compact {
    padding: 4px 10px;
    font-size: 12px;
    opacity: 0.95;
    width: min(560px, calc(100vw - 24px));
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

  .cs-panel-header {
    display: flex;
    justify-content: flex-start;
    align-items: center;
    gap: 8px;
  }

  .cs-connection-close-wrap {
    margin-left: auto;
  }

  .cs-connection-close-btn {
    padding: 2px 10px;
    font-size: 12px;
    line-height: 1.2;
  }

  .cs-connection-summary {
    margin-top: -2px;
    margin-bottom: 8px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .cs-connection-modify-wrap {
    display: flex;
    justify-content: flex-end;
    margin-top: 4px;
  }

  .cs-provider-tags {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }

  .cs-provider-tag {
    border: 1px solid #212529;
    background: #fff;
    color: #212529;
    cursor: pointer;
    user-select: none;
  }

  .cs-provider-tag.cs-provider-tag-active {
    background: #212529;
    color: #fff;
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
        <div class="cs-panel-header">
          <h4 class="cs-panel-title">1) Connection Setup</h4>
          <div class="cs-connection-close-wrap" id="csConnectionCloseWrap">
            <button class="buttons btn-black cs-connection-close-btn" id="csConnectionCloseBtn" type="button" aria-label="Close Connection Setup">Close</button>
          </div>
        </div>
        <p class="cs-muted cs-connection-summary cs-hidden" id="csConnectionSummary"></p>
        <div id="csConnectionPanelBody">
        <p class="cs-muted" id="csConnectionSubtitle">Connect to a calendar using OAuth. Select calendar provider.</p>

        <div class="mb-2">
          <div class="cs-provider-tags" role="tablist" aria-label="Calendar Provider">
            <button type="button" class="badge cs-provider-tag" id="csProviderGoogleBadge" data-provider="google">Google</button>
            <button type="button" class="badge cs-provider-tag" id="csProviderOutlookBadge" data-provider="outlook">Outlook</button>
          </div>
        </div>

        <div id="csConnectionHelpGoogle" class="cs-device-box mb-2 cs-hidden">
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

        <div id="csConnectionHelpOutlook" class="cs-device-box mb-2 cs-hidden">
          <div><strong>Outlook OAuth Setup</strong></div>
          <ol class="cs-help-list mt-1 mb-2">
            <li>Create an Azure app registration with delegated Graph scopes including <code>Calendars.ReadWrite</code> and <code>offline_access</code>.</li>
            <li>Enter tenant/client details below.</li>
            <li>Click <strong>Connect Provider</strong>, complete consent, then paste the callback URL/code.</li>
          </ol>
          <div class="row g-2">
            <div class="col-12 col-md-6">
              <label for="csOutlookTenantId" class="form-label mb-1">Tenant ID</label>
              <input id="csOutlookTenantId" class="form-control" placeholder="common">
            </div>
            <div class="col-12 col-md-6">
              <label for="csOutlookCalendarId" class="form-label mb-1">Calendar ID</label>
              <input id="csOutlookCalendarId" class="form-control" placeholder="primary">
            </div>
            <div class="col-12 col-md-6">
              <label for="csOutlookClientId" class="form-label mb-1">Client ID</label>
              <input id="csOutlookClientId" class="form-control" placeholder="Application (client) ID">
            </div>
            <div class="col-12 col-md-6">
              <label for="csOutlookClientSecret" class="form-label mb-1">Client Secret</label>
              <input id="csOutlookClientSecret" type="password" class="form-control" placeholder="Client secret">
            </div>
            <div class="col-12">
              <label for="csOutlookRedirectUri" class="form-label mb-1">Redirect URI</label>
              <input id="csOutlookRedirectUri" class="form-control" placeholder="http://127.0.0.1:8765/oauth2callback">
            </div>
            <div class="col-12">
              <label for="csOutlookScopes" class="form-label mb-1">Scopes (space separated)</label>
              <input id="csOutlookScopes" class="form-control" placeholder="offline_access openid profile User.Read Calendars.ReadWrite">
            </div>
          </div>
          <div class="mb-1 mt-2"><strong>Current Setup Hints</strong></div>
          <ul id="csOutlookHelpHints" class="mb-0"></ul>
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
          <button class="buttons btn-success" id="csConnectBtn" type="button">Connect Provider</button>
        </div>
        </div>
        <div class="cs-connection-modify-wrap cs-hidden" id="csConnectionModifyWrap">
          <button class="buttons btn-black" id="csConnectionModifyBtn" type="button" aria-label="Modify Connection Setup">Modify</button>
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

  <div id="csOutlookAuthModalWrap" class="cs-modal-backdrop cs-hidden" role="dialog" aria-modal="true" aria-labelledby="csOutlookAuthModalTitle">
    <div class="cs-modal">
      <div class="cs-modal-header">
        <strong id="csOutlookAuthModalTitle">Finish Outlook Sign-In</strong>
      </div>
      <div class="cs-modal-body">
        <div class="mb-2">1) Open Microsoft sign-in and consent page:</div>
        <div class="mb-2">
          <a id="csOutlookAuthLink" href="#" target="_blank" rel="noopener noreferrer">Open Outlook Consent Page</a>
        </div>
        <div class="mb-2">2) Paste full callback URL or authorization code:</div>
        <div class="d-flex gap-2 align-items-center">
          <input id="csOutlookAuthCodeInput" class="form-control" placeholder="Paste callback URL or code here">
          <button id="csOutlookAuthPasteBtn" type="button" class="buttons btn-black">Paste</button>
        </div>
        <div id="csOutlookAuthInlineMsg" class="cs-muted mt-2">Waiting for Microsoft consent completion...</div>
      </div>
      <div class="cs-modal-footer">
        <a id="csOutlookAuthOpenBtn" class="buttons btn-black" href="#" target="_blank" rel="noopener noreferrer">Open Outlook Consent Page</a>
        <button id="csOutlookAuthCompleteBtn" type="button" class="buttons btn-success">Complete Sign-In</button>
        <button id="csOutlookAuthCancelBtn" type="button" class="buttons btn-black">Cancel</button>
      </div>
    </div>
  </div>

  <div class="backdrop mb-3" id="csPendingPanel">
    <h4 class="cs-panel-title">2) Pending Actions</h4>
    <p class="cs-muted">View of all pending create/update/delete changes. Choose sync mode.</p>
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

  <div class="backdrop mb-3" id="csApplyPanel">
    <h4 class="cs-panel-title">3) Apply Changes</h4>
    <p class="cs-muted" id="csApplySubtitle">Apply writes changes shown under Pending Actions.</p>
    <div class="d-flex justify-content-end">
      <button class="buttons btn-success" id="csApplyBtn" type="button" disabled>Apply Changes</button>
    </div>
  </div>

  <details>
    <summary><strong>Diagnostics</strong></summary>
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
    var qs = new URLSearchParams(window.location.search || "");
    var pluginName = qs.get("plugin") || "CalendarScheduler";
    var API_URL = "plugin.php?plugin=" + encodeURIComponent(pluginName) + "&page=ui-api.php&nopage=1";
    var activeProvider = "google";
    var providerConnected = false;
    var deviceAuthPollTimer = null;
    var deviceAuthDeadlineEpoch = 0;
    var syncMode = "both";
    var connectionCollapsed = false;
    var connectionCollapsedLoaded = false;
    var applyConfirmArmed = false;
    var applyConfirmTimer = null;
    var applyInFlight = false;
    var lastPendingCount = 0;

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
      ["csConnectBtn", "csUploadDeviceClientBtn", "csSyncModeSelect", "csProviderGoogleBadge", "csProviderOutlookBadge"].forEach(function (id) {
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
      applyBtn.disabled = !enabled || applyInFlight;
      if (!enabled || applyInFlight) {
        resetApplyConfirm();
      }
    }

    function clearApplyConfirmTimer() {
      if (applyConfirmTimer !== null) {
        window.clearTimeout(applyConfirmTimer);
        applyConfirmTimer = null;
      }
    }

    function resetApplyConfirm() {
      var applyBtn = byId("csApplyBtn");
      clearApplyConfirmTimer();
      applyConfirmArmed = false;
      if (applyBtn) {
        applyBtn.textContent = "Apply Changes";
        applyBtn.classList.remove("btn-danger");
        applyBtn.classList.add("btn-success");
      }
    }

    function armApplyConfirm() {
      var applyBtn = byId("csApplyBtn");
      if (!applyBtn || applyBtn.disabled) {
        return;
      }
      clearApplyConfirmTimer();
      applyConfirmArmed = true;
      applyBtn.textContent = "CONFIRM";
      applyBtn.classList.remove("btn-success");
      applyBtn.classList.add("btn-danger");
      applyConfirmTimer = window.setTimeout(function () {
        resetApplyConfirm();
      }, 5000);
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

    function updateApplySubtitle() {
      var node = byId("csApplySubtitle");
      if (!node) {
        return;
      }
      if (syncMode === "calendar") {
        node.textContent = "Apply writes changes shown under Pending Actions to FPP.";
        return;
      }
      if (syncMode === "fpp") {
        node.textContent = "Apply writes changes shown under Pending Actions to calendar.";
        return;
      }
      node.textContent = "Apply writes changes shown under Pending Actions.";
    }

    function setConnectionCollapsed(collapsed) {
      var body = byId("csConnectionPanelBody");
      var summary = byId("csConnectionSummary");
      var closeWrap = byId("csConnectionCloseWrap");
      var modifyWrap = byId("csConnectionModifyWrap");
      if (!body || !summary || !closeWrap || !modifyWrap) {
        return;
      }
      connectionCollapsed = !!collapsed;
      body.classList.toggle("cs-hidden", connectionCollapsed);
      summary.classList.toggle("cs-hidden", !connectionCollapsed);
      closeWrap.classList.toggle("cs-hidden", connectionCollapsed);
      modifyWrap.classList.toggle("cs-hidden", !connectionCollapsed);
    }

    function updateTopStatusCompact() {
      var bar = byId("csTopStatusBar");
      if (!bar) {
        return;
      }
      bar.classList.toggle("cs-top-status-compact", window.scrollY > 120);
    }

    function updateTopStatusAnchor() {
      // Mirror FPP anchored controls: if global header is fixed, pin below it.
      var header = document.querySelector(".header");
      var top = 8;
      if (header) {
        var style = window.getComputedStyle(header);
        if (style && style.position === "fixed") {
          top = Math.max(8, Math.round(header.getBoundingClientRect().height) + 8);
        }
      }
      document.documentElement.style.setProperty("--cs-sticky-top", String(top) + "px");
    }

    function setLoadingState() {
      byId("csPreviewState").textContent = "Loading";
      byId("csPreviewTime").textContent = "Updating...";
      setTopBarClass("cs-status-loading");
    }

    function setDeviceAuthVisible(visible, code, url) {
      var wrap = byId("csDeviceAuthModalWrap");
      var titleNode = byId("csDeviceAuthModalTitle");
      var codeNode = byId("csDeviceAuthCode");
      var link = byId("csDeviceAuthLink");
      var openBtn = byId("csDeviceAuthOpenBtn");
      var copyBtn = byId("csCopyDeviceCodeBtn");
      if (!wrap || !titleNode || !codeNode || !link || !openBtn || !copyBtn) {
        return;
      }
      var isOutlook = activeProvider === "outlook";
      var defaultUrl = isOutlook ? "https://microsoft.com/devicelogin" : "https://www.google.com/device";
      titleNode.textContent = isOutlook ? "Finish Outlook Sign-In" : "Finish Google Sign-In";
      openBtn.textContent = isOutlook ? "Open Microsoft Device Page" : "Open Google Device Page";
      if (visible) {
        var authCode = code || "-";
        codeNode.textContent = authCode;
        copyBtn.disabled = authCode === "-";
        var dest = url || defaultUrl;
        link.href = dest;
        openBtn.href = dest;
        link.textContent = dest.replace(/^https?:\/\//, "");
        wrap.classList.remove("cs-hidden");
      } else {
        wrap.classList.add("cs-hidden");
        codeNode.textContent = "-";
        copyBtn.disabled = true;
        link.href = defaultUrl;
        openBtn.href = defaultUrl;
        link.textContent = defaultUrl.replace(/^https?:\/\//, "");
      }
    }

    function setOutlookAuthVisible(visible, url) {
      var wrap = byId("csOutlookAuthModalWrap");
      var link = byId("csOutlookAuthLink");
      var openBtn = byId("csOutlookAuthOpenBtn");
      var input = byId("csOutlookAuthCodeInput");
      var completeBtn = byId("csOutlookAuthCompleteBtn");
      var inlineMsg = byId("csOutlookAuthInlineMsg");
      if (!wrap || !link || !openBtn || !input || !completeBtn || !inlineMsg) {
        return;
      }

      if (visible) {
        var dest = url || "#";
        link.href = dest;
        openBtn.href = dest;
        wrap.classList.remove("cs-hidden");
        input.classList.remove("is-invalid");
        inlineMsg.textContent = "Waiting for Microsoft consent completion...";
        inlineMsg.classList.remove("text-danger");
        inlineMsg.classList.add("cs-muted");
        completeBtn.disabled = false;
        input.focus();
      } else {
        wrap.classList.add("cs-hidden");
        link.href = "#";
        openBtn.href = "#";
        input.value = "";
        input.classList.remove("is-invalid");
        inlineMsg.textContent = "";
        inlineMsg.classList.remove("text-danger");
        inlineMsg.classList.add("cs-muted");
        completeBtn.disabled = false;
      }
    }

    function setOutlookAuthMessage(message, isError) {
      var inlineMsg = byId("csOutlookAuthInlineMsg");
      if (!inlineMsg) {
        return;
      }
      inlineMsg.textContent = String(message || "");
      inlineMsg.classList.toggle("text-danger", !!isError);
      inlineMsg.classList.toggle("cs-muted", !isError);
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
      var googleBox = byId("csConnectionHelpGoogle");
      var outlookBox = byId("csConnectionHelpOutlook");
      var checksNode = byId("csHelpChecks");
      var hintsNode = byId("csHelpHints");
      var outlookHintsNode = byId("csOutlookHelpHints");
      if (!googleBox || !outlookBox || !checksNode || !hintsNode || !outlookHintsNode) {
        return;
      }

      if (connected) {
        googleBox.classList.add("cs-hidden");
        outlookBox.classList.add("cs-hidden");
        checksNode.innerHTML = "";
        hintsNode.innerHTML = "";
        outlookHintsNode.innerHTML = "";
        return;
      }
      setup = setup || {};
      if (activeProvider === "outlook") {
        googleBox.classList.add("cs-hidden");
        outlookBox.classList.remove("cs-hidden");
        checksNode.innerHTML = "";
        hintsNode.innerHTML = "";
        var outlookHints = Array.isArray(setup.hints) ? setup.hints : [];
        if (outlookHints.length === 0) {
          outlookHintsNode.innerHTML = "<li class=\"cs-help-check-ok\">No setup issues detected.</li>";
        } else {
          outlookHintsNode.innerHTML = outlookHints.map(function (hint) {
            return "<li>" + escapeHtml(hint) + "</li>";
          }).join("");
        }
        return;
      }

      outlookBox.classList.add("cs-hidden");
      googleBox.classList.remove("cs-hidden");
      outlookHintsNode.innerHTML = "";

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

    function allSetupChecksOk(setup) {
      setup = setup || {};
      if (activeProvider === "outlook") {
        return !!setup.configPresent
          && !!setup.configValid
          && !!setup.tokenPathWritable
          && !!setup.oauthConfigured;
      }
      return !!setup.clientFilePresent
        && !!setup.configPresent
        && !!setup.configValid
        && !!setup.tokenPathWritable
        && !!setup.deviceFlowReady;
    }

    function outlookFormReady() {
      var clientId = (byId("csOutlookClientId").value || "").trim();
      var clientSecret = (byId("csOutlookClientSecret").value || "").trim();
      var redirectUri = (byId("csOutlookRedirectUri").value || "").trim();
      return !!clientId && !!clientSecret && !!redirectUri;
    }

    // -----------------------------------------------------------------------
    // API + rendering helpers
    // -----------------------------------------------------------------------
    function renderDiagnostics(payload) {
      var out = byId("csDiagnosticJson");
      if (!out) {
        return;
      }
      var body = (payload && payload.diagnostics) ? payload.diagnostics : payload;
      out.textContent = JSON.stringify(body || {}, null, 2);
    }

    function refreshDiagnostics() {
      return fetchJson({ action: "diagnostics", sync_mode: syncMode })
        .then(function (res) {
          renderDiagnostics(res);
        })
        .catch(function () {
          // Diagnostics should not block primary UX flow.
        });
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
          if (r.indexOf("order changed") !== -1) {
            if (r.indexOf("calendar newer") !== -1) {
              return "Update FPP entry order to match newer calendar ordering.";
            }
            if (r.indexOf("fpp newer") !== -1) {
              return "Update calendar event order to match newer FPP ordering.";
            }
            return "Update ordering so both sides execute entries in the same sequence.";
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
        var manifestIdentity = a.manifestEvent && a.manifestEvent.identity ? a.manifestEvent.identity : null;
        var eventName = (manifestIdentity && manifestIdentity.target)
          ? manifestIdentity.target
          : ((a.event && a.event.target) ? a.event.target : "-");
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
      lastPendingCount = pendingCount;
      setApplyEnabled(pendingCount > 0);
    }

    function updateConnectionSummary(providerData, selectedLabel) {
      var node = byId("csConnectionSummary");
      if (!node) {
        return;
      }
      var connected = !!(providerData && providerData.connected);
      if (!connected) {
        node.textContent = "Not connected.";
        return;
      }

      var label = String(selectedLabel || "").trim();
      if (label === "") {
        label = "No calendar selected";
      }
      node.textContent = "Connected to " + (activeProvider === "outlook" ? "Outlook" : "Google") + " calendar: " + label;
    }

    // Pull provider state and update setup/connection controls.
    function loadStatus() {
      return fetchJson({ action: "status" }).then(function (res) {
        activeProvider = (typeof res.provider === "string" && res.provider) ? res.provider : "google";
        var google = res.google || {};
        var outlook = res.outlook || {};
        var providerData = activeProvider === "outlook" ? outlook : google;
        syncMode = (typeof res.syncMode === "string" && res.syncMode) ? res.syncMode : "both";
        providerConnected = !!providerData.connected;
        if (!connectionCollapsedLoaded) {
          var uiPrefs = (res && typeof res.ui === "object" && res.ui) ? res.ui : {};
          setConnectionCollapsed(!!uiPrefs.connectionCollapsed);
          connectionCollapsedLoaded = true;
        }
        if (providerConnected) {
          setDeviceAuthVisible(false);
          setOutlookAuthVisible(false);
          clearDeviceAuthPoll();
        } else {
          setDeviceAuthVisible(false);
          setOutlookAuthVisible(false);
        }
        renderConnectionHelp(providerData.setup || {}, providerConnected);
        var subtitle = byId("csConnectionSubtitle");
        if (subtitle) {
          subtitle.textContent = providerConnected
            ? "Connect to a calendar using OAuth."
            : "Connect to a calendar using OAuth. Select calendar provider.";
        }
        var account = providerData.account || "Not connected yet";
        var accountValue = byId("csConnectedAccountValue");
        if (accountValue) {
          accountValue.textContent = account;
        }

        var select = byId("csCalendarSelect");
        var connectedAccountGroup = byId("csConnectedAccountGroup");
        var calendarSelectGroup = byId("csCalendarSelectGroup");
        var calendars = Array.isArray(providerData.calendars) ? providerData.calendars : [];
        if (calendars.length === 0) {
          select.innerHTML = "<option>Connect account to load calendars</option>";
          select.disabled = true;
          updateConnectionSummary(providerData, "");
        } else {
          var selectedLabel = "";
          select.innerHTML = calendars.map(function (c) {
            var selected = c.id === providerData.selectedCalendarId ? " selected" : "";
            var label = c.primary ? (c.summary + " (Primary)") : c.summary;
            if (c.id === providerData.selectedCalendarId) {
              selectedLabel = label;
            }
            return "<option value=\"" + escapeHtml(c.id) + "\"" + selected + ">" + escapeHtml(label) + "</option>";
          }).join("");
          if (!selectedLabel && select.options.length > 0) {
            selectedLabel = select.options[select.selectedIndex >= 0 ? select.selectedIndex : 0].text || "";
          }
          updateConnectionSummary(providerData, selectedLabel);
          select.disabled = false;
        }
        if (connectedAccountGroup) {
          connectedAccountGroup.classList.toggle("cs-hidden", !providerConnected);
        }
        if (calendarSelectGroup) {
          calendarSelectGroup.classList.toggle("cs-hidden", !providerConnected);
        }

        var connectBtn = byId("csConnectBtn");
        var uploadBtn = byId("csUploadDeviceClientBtn");
        var syncModeWrap = byId("csSyncModeWrap");
        var syncModeSelect = byId("csSyncModeSelect");
        var googleBadge = byId("csProviderGoogleBadge");
        var outlookBadge = byId("csProviderOutlookBadge");
        var pendingPanel = byId("csPendingPanel");
        var applyPanel = byId("csApplyPanel");
        var uploadBtnWrap = byId("csUploadDeviceClientBtn");
        connectBtn.dataset.locked = "0";
        connectBtn.textContent = providerConnected ? "Disconnect Provider" : "Connect Provider";
        connectBtn.classList.toggle("btn-success", !providerConnected);
        connectBtn.classList.toggle("btn-black", providerConnected);
        if (activeProvider === "google") {
          uploadBtn.dataset.locked = providerConnected ? "1" : "0";
          uploadBtn.disabled = providerConnected;
          if (uploadBtnWrap) {
            uploadBtnWrap.classList.remove("cs-hidden");
          }
        } else {
          uploadBtn.dataset.locked = "1";
          uploadBtn.disabled = true;
          if (uploadBtnWrap) {
            uploadBtnWrap.classList.add("cs-hidden");
          }
        }
        if (syncModeSelect) {
          syncModeSelect.value = syncMode;
          syncModeSelect.dataset.locked = providerConnected ? "0" : "1";
          syncModeSelect.disabled = !providerConnected;
        }
        updateApplySubtitle();
        if (syncModeWrap) {
          syncModeWrap.classList.toggle("cs-hidden", !providerConnected);
        }
        if (pendingPanel) {
          pendingPanel.classList.toggle("cs-hidden", !providerConnected);
        }
        if (applyPanel) {
          applyPanel.classList.toggle("cs-hidden", !providerConnected);
        }
        if (googleBadge) {
          googleBadge.classList.toggle("cs-provider-tag-active", activeProvider === "google");
          googleBadge.setAttribute("aria-selected", activeProvider === "google" ? "true" : "false");
        }
        if (outlookBadge) {
          outlookBadge.classList.toggle("cs-provider-tag-active", activeProvider === "outlook");
          outlookBadge.setAttribute("aria-selected", activeProvider === "outlook" ? "true" : "false");
        }

        var setup = providerData.setup || {};
        var connectReady = allSetupChecksOk(setup);
        if (!providerConnected && activeProvider === "outlook") {
          var localReady = outlookFormReady();
          connectBtn.dataset.locked = localReady ? "0" : "1";
          connectBtn.disabled = !localReady;
          var outlookHints = Array.isArray(setup.hints) ? setup.hints : [];
          if (!localReady) {
            setSetupStatus("Enter Outlook client ID, client secret, and redirect URI to enable Connect.");
          } else if (outlookHints.length > 0) {
            setSetupStatus(outlookHints.join(" | "));
          } else {
            setSetupStatus("Enter Outlook OAuth details, then click Connect Provider.");
          }
        } else if (!providerConnected && !connectReady) {
          connectBtn.dataset.locked = "1";
          connectBtn.disabled = true;
          var hints = Array.isArray(setup.hints) ? setup.hints : [];
          var msg = hints.length > 0 ? hints.join(" | ") : "Provider setup is incomplete.";
          setSetupStatus(msg);
        } else if (!providerConnected) {
          if (activeProvider === "outlook") {
            setSetupStatus("Not connected. Click Connect Provider to start Outlook sign-in.");
          } else {
            setSetupStatus("Not connected. Click Connect Provider to start Google device sign-in.");
          }
        }

        if (activeProvider === "outlook") {
          var tenantInput = byId("csOutlookTenantId");
          var calendarInput = byId("csOutlookCalendarId");
          var clientIdInput = byId("csOutlookClientId");
          var clientSecretInput = byId("csOutlookClientSecret");
          var redirectInput = byId("csOutlookRedirectUri");
          var scopesInput = byId("csOutlookScopes");
          var oauth = (providerData && typeof providerData.oauth === "object" && providerData.oauth)
            ? providerData.oauth
            : {};
          if (tenantInput && !tenantInput.value) {
            tenantInput.value = (oauth.tenant_id || "common");
          }
          if (calendarInput && !calendarInput.value) {
            calendarInput.value = providerData.selectedCalendarId || "primary";
          }
          if (clientIdInput && !clientIdInput.value) {
            clientIdInput.value = oauth.client_id || "";
          }
          if (clientSecretInput && !clientSecretInput.value) {
            clientSecretInput.value = oauth.client_secret || "";
          }
          if (redirectInput && !redirectInput.value) {
            redirectInput.value = oauth.redirect_uri || "http://localhost:8765/oauth2callback";
          }
          if (scopesInput && !scopesInput.value) {
            scopesInput.value = Array.isArray(oauth.scopes) && oauth.scopes.length > 0
              ? oauth.scopes.join(" ")
              : "offline_access openid profile User.Read Calendars.ReadWrite";
          }
        }
        return refreshDiagnostics();
      });
    }

    // Execute reconciliation preview and render pending changes.
    function runPreview() {
      return fetchJson({ action: "preview", sync_mode: syncMode }).then(function (res) {
        renderPreview(res.preview || {});
        return refreshDiagnostics();
      });
    }

    function runApply() {
      return fetchJson({ action: "apply", sync_mode: syncMode }).then(function (res) {
        renderPreview(res.preview || {});
        return refreshDiagnostics();
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
      return fetchJson({ action: "auth_device_start" })
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
          setError("Automatic device authorization could not start: " + err.message);
          throw err;
        });
    }

    function startOutlookAuthFlow() {
      var tenantId = (byId("csOutlookTenantId").value || "").trim() || "common";
      var clientId = (byId("csOutlookClientId").value || "").trim();
      var clientSecret = (byId("csOutlookClientSecret").value || "").trim();
      var redirectUri = (byId("csOutlookRedirectUri").value || "").trim() || "http://127.0.0.1:8765/oauth2callback";
      var scopes = (byId("csOutlookScopes").value || "").trim() || "offline_access openid profile User.Read Calendars.ReadWrite";
      var calendarId = (byId("csOutlookCalendarId").value || "").trim() || "primary";

      if (!clientId || !clientSecret) {
        setOutlookAuthMessage("Outlook client_id and client_secret are required before connecting.", true);
        setSetupStatus("Outlook client_id and client_secret are required.");
        return;
      }

      setButtonsDisabled(true);
      setLoadingState();
      return fetchJson({
        action: "auth_outlook_save_config",
        tenant_id: tenantId,
        client_id: clientId,
        client_secret: clientSecret,
        redirect_uri: redirectUri,
        scopes: scopes,
        calendar_id: calendarId
      })
        .then(function () {
          setOutlookAuthVisible(false);
        })
        .then(function () {
          return startDeviceAuthFlow();
        })
        .catch(function (err) {
          setError(err.message);
          throw err;
        })
        .finally(function () {
          setButtonsDisabled(false);
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
            lastPendingCount = 0;
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
        var providerLabel = activeProvider === "outlook" ? "Outlook" : "Google";
        if (!window.confirm("Disconnect provider and remove the local " + providerLabel + " token from this FPP instance?")) {
          return;
        }
        setButtonsDisabled(true);
        setLoadingState();
        fetchJson({ action: "auth_disconnect" })
          .then(function () {
            setDeviceAuthVisible(false);
            setOutlookAuthVisible(false);
            clearDeviceAuthPoll();
            return refreshAll();
          })
          .catch(function (err) { setError(err.message); })
          .finally(function () { setButtonsDisabled(false); });
        return;
      }

      if (activeProvider === "outlook") {
        startOutlookAuthFlow().catch(function () {});
      } else {
        startDeviceAuthFlow().catch(function () {});
      }
    });

    function onProviderTagClick(provider) {
      provider = String(provider || "google").trim().toLowerCase();
      if (provider !== "google" && provider !== "outlook") {
        return;
      }
      if (provider === activeProvider) {
        return;
      }
      setButtonsDisabled(true);
      setLoadingState();
      fetchJson({ action: "set_provider", provider: provider })
        .then(function () {
          activeProvider = provider;
          setDeviceAuthVisible(false);
          setOutlookAuthVisible(false);
          clearDeviceAuthPoll();
          return refreshAll();
        })
        .catch(function (err) { setError(err.message); })
        .finally(function () { setButtonsDisabled(false); });
    }

    byId("csProviderGoogleBadge").addEventListener("click", function () {
      onProviderTagClick("google");
    });

    byId("csProviderOutlookBadge").addEventListener("click", function () {
      onProviderTagClick("outlook");
    });

    ["csOutlookClientId", "csOutlookClientSecret", "csOutlookRedirectUri"].forEach(function (id) {
      var node = byId(id);
      if (!node) {
        return;
      }
      node.addEventListener("input", function () {
        if (activeProvider === "outlook" && !providerConnected) {
          var connectBtn = byId("csConnectBtn");
          if (!connectBtn) {
            return;
          }
          var ready = outlookFormReady();
          connectBtn.dataset.locked = ready ? "0" : "1";
          connectBtn.disabled = !ready;
        }
      });
    });

    byId("csConnectionCloseBtn").addEventListener("click", function () {
      setConnectionCollapsed(!connectionCollapsed);
      fetchJson({
        action: "set_ui_pref",
        key: "connection_collapsed",
        value: connectionCollapsed
      }).catch(function () {
        // Keep local state even if pref persistence fails.
      });
    });

    byId("csConnectionModifyBtn").addEventListener("click", function () {
      setConnectionCollapsed(!connectionCollapsed);
      fetchJson({
        action: "set_ui_pref",
        key: "connection_collapsed",
        value: connectionCollapsed
      }).catch(function () {
        // Keep local state even if pref persistence fails.
      });
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

    byId("csOutlookAuthCancelBtn").addEventListener("click", function () {
      setOutlookAuthVisible(false);
      setSetupStatus("Sign-in canceled. Click Connect Provider to start again.");
    });

    byId("csOutlookAuthPasteBtn").addEventListener("click", function () {
      var input = byId("csOutlookAuthCodeInput");
      if (!input) {
        return;
      }

      if (navigator.clipboard && navigator.clipboard.readText) {
        navigator.clipboard.readText()
          .then(function (text) {
            var value = String(text || "").trim();
            if (!value) {
              setOutlookAuthMessage("Clipboard is empty. Copy callback URL/code first.", true);
              return;
            }
            input.value = value;
            input.classList.remove("is-invalid");
            setOutlookAuthMessage("Pasted from clipboard. Click Complete Sign-In.", false);
          })
          .catch(function () {
            setOutlookAuthMessage("Clipboard read failed. Paste manually into the field.", true);
          });
        return;
      }

      setOutlookAuthMessage("Clipboard read is unavailable in this browser context. Paste manually.", true);
    });

    byId("csOutlookAuthCompleteBtn").addEventListener("click", function () {
      var input = byId("csOutlookAuthCodeInput");
      var completeBtn = byId("csOutlookAuthCompleteBtn");
      var code = input ? String(input.value || "").trim() : "";
      if (!code) {
        if (input) {
          input.classList.add("is-invalid");
          input.focus();
        }
        setOutlookAuthMessage("Paste the full callback URL or authorization code to continue.", true);
        return;
      }
      if (input) {
        input.classList.remove("is-invalid");
      }
      if (completeBtn) {
        completeBtn.disabled = true;
      }
      setOutlookAuthMessage("Completing Outlook sign-in...", false);

      setButtonsDisabled(true);
      setLoadingState();
      fetchJson({ action: "auth_exchange_code", code: code })
        .then(function () {
          setOutlookAuthMessage("Outlook sign-in complete.", false);
          setOutlookAuthVisible(false);
          return refreshAll();
        })
        .catch(function (err) {
          if (input) {
            input.classList.add("is-invalid");
          }
          setOutlookAuthMessage("Sign-in failed: " + err.message, true);
          setError(err.message);
        })
        .finally(function () {
          if (completeBtn) {
            completeBtn.disabled = false;
          }
          setButtonsDisabled(false);
        });
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
          updateApplySubtitle();
          return refreshAll();
        })
        .catch(function (err) { setError(err.message); })
        .finally(function () { setButtonsDisabled(false); });
    });

    byId("csApplyBtn").addEventListener("click", function () {
      if (!applyConfirmArmed) {
        armApplyConfirm();
        return;
      }
      resetApplyConfirm();
      applyInFlight = true;
      setApplyEnabled(false);
      setButtonsDisabled(true);
      setLoadingState();
      runApply()
        .catch(function (err) { setError(err.message); })
        .finally(function () {
          applyInFlight = false;
          setButtonsDisabled(false);
          setApplyEnabled(lastPendingCount > 0);
        });
    });

    window.addEventListener("focus", function () {
      refreshAll();
    });
    window.addEventListener("scroll", function () {
      updateTopStatusAnchor();
      updateTopStatusCompact();
    }, { passive: true });
    window.addEventListener("resize", updateTopStatusAnchor);

    updateTopStatusAnchor();
    updateTopStatusCompact();
    refreshAll();
  }());
</script>
