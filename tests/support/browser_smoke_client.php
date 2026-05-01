<?php

declare(strict_types=1) ?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Browser Smoke</title>
  <style>
    body { font: 14px/1.4 sans-serif; padding: 16px; }
    iframe { width: 1280px; height: 900px; border: 1px solid #ccc; }
    #result[data-status="pass"] { color: #1f7a1f; }
    #result[data-status="fail"] { color: #b00020; white-space: pre-wrap; }
  </style>
</head>
<body>
  <pre id="result" data-status="pending">Running…</pre>
  <iframe id="appFrame" title="smoke target"></iframe>
  <script>
    (function () {
      "use strict";

      var params = new URLSearchParams(window.location.search);
      var check = params.get("check") || "diag";
      var result = document.getElementById("result");
      var frame = document.getElementById("appFrame");

      function setResult(status, text) {
        result.dataset.status = status;
        result.textContent = text;
      }

      function fail(error) {
        setResult("fail", String(error && error.message ? error.message : error));
      }

      function waitFor(fn, timeoutMs) {
        var started = Date.now();
        return new Promise(function (resolve, reject) {
          (function poll() {
            try {
              var value = fn();
              if (value) {
                resolve(value);
                return;
              }
            } catch (error) {
              reject(error);
              return;
            }
            if (Date.now() - started >= timeoutMs) {
              reject(new Error("Timed out waiting for " + check));
              return;
            }
            window.setTimeout(poll, 50);
          })();
        });
      }

      function loadFrame(path) {
        return new Promise(function (resolve, reject) {
          frame.onload = function () {
            resolve(frame.contentWindow);
          };
          frame.onerror = function () {
            reject(new Error("Failed to load " + path));
          };
          frame.src = path;
        });
      }

      async function runDiag() {
        await loadFrame("/diag.php?__smoke_role=admin");
        var doc = frame.contentDocument;
        await waitFor(function () {
          return doc && /Environment Doctor/.test(doc.body.textContent || "");
        }, 4000);
        if (!/Admin Shortcuts/.test(doc.body.textContent || "")) {
          throw new Error("Diagnostics page did not render shortcuts");
        }
        setResult("pass", "diag");
      }

      async function runConfig() {
        await loadFrame("/config.php?__smoke_role=admin");
        var doc = frame.contentDocument;
        await waitFor(function () {
          return doc && doc.getElementById("configTabs") && doc.getElementById("configPane");
        }, 4000);
        var securityBtn = Array.prototype.find.call(
          doc.querySelectorAll("#configTabs button[data-section]"),
          function (btn) {
            return (btn.dataset.section || "").toLowerCase() === "security";
          },
        );
        if (!securityBtn) {
          throw new Error("Config page did not render Security tab");
        }
        securityBtn.click();
        await waitFor(function () {
          var panel = doc.querySelector(".security-token-tools");
          var rotate = panel && Array.prototype.find.call(panel.querySelectorAll("button"), function (btn) {
            return /rotate token/i.test(btn.textContent || "");
          });
          return panel && rotate;
        }, 4000);
        setResult("pass", "config");
      }

      async function runHistory() {
        await loadFrame("/history.php?__smoke_role=admin");
        var doc = frame.contentDocument;
        await waitFor(function () {
          return doc && doc.getElementById("reportHtmlBtn");
        }, 4000);
        doc.getElementById("reportHtmlBtn").click();
        await waitFor(function () {
          var modal = doc.querySelector(".history-report-modal");
          var inner = modal && modal.querySelector("iframe");
          return modal && !modal.hidden && inner ? inner : null;
        }, 4000);
        setResult("pass", "history");
      }

      async function runSpeedtest() {
        await loadFrame("/speedtest.php?__smoke_role=admin&__smoke_fixture=speedtest");
        var doc = frame.contentDocument;
        await waitFor(function () {
          var content = doc.getElementById("speedtestContent");
          var rows = doc.querySelectorAll("#speedtestTableBody tr");
          return content && !content.hidden && rows.length === 25;
        }, 5000);
        var select = doc.getElementById("speedtestPageSize");
        select.value = "50";
        select.dispatchEvent(new frame.contentWindow.Event("change", { bubbles: true }));
        await waitFor(function () {
          return doc.querySelectorAll("#speedtestTableBody tr").length === 50;
        }, 2000);
        setResult("pass", "speedtest");
      }

      async function runDashboard() {
        await loadFrame("/index.php?__smoke_role=admin&__smoke_fixture=ops");
        var doc = frame.contentDocument;
        await waitFor(function () {
          return doc && doc.getElementById("services");
        }, 4000);
        await waitFor(function () {
          return doc.querySelectorAll("#services .service-card").length >= 1;
        }, 4000);
        setResult("pass", "dashboard");
      }

      async function runLogs() {
        await loadFrame("/logs.php?__smoke_role=admin&__smoke_fixture=ops");
        var doc = frame.contentDocument;
        await waitFor(function () {
          return doc && doc.getElementById("logsModeLive");
        }, 4000);
        doc.getElementById("logsModeLive").click();
        await waitFor(function () {
          var panel = doc.getElementById("livePrivilegedPanel");
          return panel && !panel.hidden;
        }, 3000);
        setResult("pass", "logs");
      }

      async function runServiceDetail() {
        await loadFrame("/service_detail.php?id=svc_smoke_web&__smoke_role=admin&__smoke_fixture=ops");
        var doc = frame.contentDocument;
        await waitFor(function () {
          return doc && /Smoke Web/.test(doc.body.textContent || "");
        }, 4000);
        await waitFor(function () {
          return doc && /Related Incidents/.test(doc.body.textContent || "");
        }, 4000);
        setResult("pass", "service-detail");
      }

      async function runIncidents() {
        await loadFrame("/history.php?__smoke_role=admin&__smoke_fixture=ops");
        var doc = frame.contentDocument;
        await waitFor(function () {
          return doc && doc.getElementById("incidentsTable");
        }, 4000);
        await waitFor(function () {
          return doc.querySelector('#incidentsTable tbody a[href*="incident.php?id="]');
        }, 4000);
        var href = doc.querySelector('#incidentsTable tbody a[href*="incident.php?id="]').getAttribute("href");
        await loadFrame("/" + href.replace(/^\//, "") + "&__smoke_role=admin&__smoke_fixture=ops");
        var detailDoc = frame.contentDocument;
        await waitFor(function () {
          return detailDoc && /Timeline/.test(detailDoc.body.textContent || "");
        }, 4000);
        setResult("pass", "incidents");
      }

      async function runBackups() {
        await loadFrame("/backups.php?__smoke_role=admin&__smoke_fixture=ops");
        var doc = frame.contentDocument;
        await waitFor(function () {
          return doc && doc.getElementById("runBackupVerify") && doc.getElementById("buildSupportBundle");
        }, 5000);
        setResult("pass", "backups");
      }

      var runners = {
        dashboard: runDashboard,
        diag: runDiag,
        config: runConfig,
        history: runHistory,
        logs: runLogs,
        incidents: runIncidents,
        "service-detail": runServiceDetail,
        backups: runBackups,
        speedtest: runSpeedtest,
      };

      Promise.resolve()
        .then(function () {
          if (!runners[check]) {
            throw new Error("Unknown check: " + check);
          }
          return runners[check]();
        })
        .catch(fail);
    })();
  </script>
</body>
</html>
