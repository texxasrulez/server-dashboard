(function () {
  "use strict";

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (typeof text === "string") node.textContent = text;
    return node;
  }

  function req(action, body) {
    var url = "api/cron_token_admin.php?action=" + encodeURIComponent(action);
    var opts = {
      method: body ? "POST" : "GET",
      credentials: "same-origin",
      headers: {},
    };
    if (body) {
      body._csrf = window.__CONFIG_CSRF__ || "";
      opts.headers["Content-Type"] = "application/json";
      opts.body = JSON.stringify(body);
    }
    return fetch(url, opts).then(function (response) {
      return response.text().then(function (text) {
        var json = null;
        try {
          json = JSON.parse(text);
        } catch (_) {}
        if (!response.ok || !json || json.ok === false) {
          throw new Error(
            (json && (json.error || json.message)) ||
              "Request failed [" + response.status + "]",
          );
        }
        return json;
      });
    });
  }

  function toast(kind, message) {
    if (window.toast && window.toast[kind]) {
      window.toast[kind](message);
    }
  }

  function ensureModalStyles() {
    if (document.getElementById("security-token-modal-style")) return;
    var style = document.createElement("style");
    style.id = "security-token-modal-style";
    style.textContent = [
      ".token-auth-modal{position:fixed;inset:0;background:rgba(9,13,19,.6);display:flex;align-items:center;justify-content:center;padding:1rem;z-index:9999}",
      ".token-auth-modal[hidden]{display:none !important}",
      ".token-auth-dialog{width:min(460px,100%);background:var(--card,#111);border:1px solid rgba(255,255,255,.12);border-radius:18px;padding:1rem 1rem 1.1rem;box-shadow:0 24px 60px rgba(0,0,0,.34)}",
      ".token-auth-dialog h3{margin:0 0 .4rem}",
      ".token-auth-dialog p{margin:.35rem 0 .9rem}",
      ".token-auth-dialog input{width:100%;margin:0 0 .9rem}",
      ".token-auth-dialog .actions{display:flex;gap:.5rem;justify-content:flex-end;flex-wrap:wrap}",
    ].join("");
    document.head.appendChild(style);
  }

  function createAuthModal(onSubmit) {
    ensureModalStyles();

    var root = el("div", "token-auth-modal");
    root.hidden = true;

    var dialog = el("div", "token-auth-dialog");
    var title = el("h3", "", "Re-authenticate");
    var text = el(
      "p",
      "muted",
      "Confirm your current password before revealing or rotating the cron token.",
    );
    var input = el("input");
    input.type = "password";
    input.autocomplete = "current-password";
    input.placeholder = "Current password";

    var actions = el("div", "actions");
    var cancel = el("button", "btn secondary", "Cancel");
    cancel.type = "button";
    var confirm = el("button", "btn", "Authorize");
    confirm.type = "button";

    actions.appendChild(cancel);
    actions.appendChild(confirm);
    dialog.appendChild(title);
    dialog.appendChild(text);
    dialog.appendChild(input);
    dialog.appendChild(actions);
    root.appendChild(dialog);
    document.body.appendChild(root);

    function close() {
      input.value = "";
      root.hidden = true;
    }

    function submit() {
      var password = input.value || "";
      if (!password) {
        toast("error", "Enter your current password");
        input.focus();
        return;
      }
      confirm.disabled = true;
      req("authorize", { password: password })
        .then(function (payload) {
          close();
          return onSubmit(payload);
        })
        .catch(function (error) {
          toast("error", error.message);
          input.focus();
        })
        .finally(function () {
          confirm.disabled = false;
        });
    }

    cancel.addEventListener("click", close);
    confirm.addEventListener("click", submit);
    input.addEventListener("keydown", function (event) {
      if (event.key === "Enter") {
        event.preventDefault();
        submit();
      } else if (event.key === "Escape") {
        close();
      }
    });
    root.addEventListener("click", function (event) {
      if (event.target === root) {
        close();
      }
    });

    return {
      open: function () {
        root.hidden = false;
        window.setTimeout(function () {
          input.focus();
        }, 0);
      },
      close: close,
    };
  }

  function renderTokenTools(pane) {
    if (!pane || pane.querySelector(".security-token-tools")) return;

    var card = el("div", "block security-token-tools");
    var title = el("h3", "", "Cron Token Management");
    var intro = el(
      "p",
      "muted",
      "The token stays hidden by default. Sensitive actions require a fresh password confirmation in this session.",
    );
    var status = el("div", "row gap-sm wrap");
    var details = el("div", "muted small");
    var revealRow = el("div", "row gap-sm wrap");
    var revealInput = el("input");
    revealInput.type = "text";
    revealInput.readOnly = true;
    revealInput.placeholder = "Token hidden";
    revealInput.style.minWidth = "360px";
    revealInput.style.flex = "1 1 360px";

    var unlockBtn = el("button", "btn", "Unlock");
    unlockBtn.type = "button";
    var revealBtn = el("button", "btn secondary", "Reveal");
    revealBtn.type = "button";
    var copyBtn = el("button", "btn secondary", "Copy");
    copyBtn.type = "button";
    copyBtn.disabled = true;
    var rotateBtn = el("button", "btn danger", "Rotate Token");
    rotateBtn.type = "button";
    var revokeBtn = el("button", "btn secondary", "Clear Authorization");
    revokeBtn.type = "button";

    revealRow.appendChild(revealInput);
    revealRow.appendChild(unlockBtn);
    revealRow.appendChild(revealBtn);
    revealRow.appendChild(copyBtn);
    revealRow.appendChild(rotateBtn);
    revealRow.appendChild(revokeBtn);

    var guidance = el("div", "muted small");

    card.appendChild(title);
    card.appendChild(intro);
    card.appendChild(status);
    card.appendChild(details);
    card.appendChild(revealRow);
    card.appendChild(guidance);
    pane.appendChild(card);

    var current = null;
    var pendingAfterAuth = null;

    function pill(label, cls) {
      return el("span", "pill " + cls, label);
    }

    function setVisibleToken(value) {
      revealInput.value = value || "";
      revealInput.placeholder =
        (current && current.status && current.status.masked) || "Token hidden";
      copyBtn.disabled = !revealInput.value;
    }

    function renderStatus(payload) {
      current = payload;
      status.innerHTML = "";
      details.innerHTML = "";
      guidance.innerHTML = "";

      var info = payload.status || {};
      status.appendChild(
        pill(
          info.present ? "Token Present" : "Token Missing",
          info.present ? "ok" : "fail",
        ),
      );
      status.appendChild(
        pill(
          info.reveal_authorized ? "Session Unlocked" : "Session Locked",
          info.reveal_authorized ? "ok" : "warn",
        ),
      );

      var parts = [];
      parts.push("Visible by default: " + (info.masked || "not available"));
      if (info.last_rotation_at) {
        parts.push(
          "Last rotated: " + new Date(info.last_rotation_at).toLocaleString(),
        );
      } else {
        parts.push("Last rotated: not recorded yet");
      }
      if (info.last_rotation_user) {
        parts.push("By: " + info.last_rotation_user);
      }
      details.textContent = parts.join(" | ");

      var tips = [];
      if (payload.guidance && payload.guidance.preferred) {
        tips.push(payload.guidance.preferred);
      }
      if (payload.guidance && payload.guidance.discouraged) {
        tips.push(payload.guidance.discouraged);
      }
      guidance.textContent = tips.join(" ");

      if (!info.reveal_authorized) {
        setVisibleToken("");
      }
    }

    function refresh() {
      return req("status")
        .then(renderStatus)
        .catch(function (error) {
          toast("error", error.message);
        });
    }

    function revealToken() {
      return req("reveal", {})
        .then(function (payload) {
          setVisibleToken(payload.token || "");
          toast("success", "Token revealed for this session");
          return refresh();
        })
        .catch(function (error) {
          toast("error", error.message);
        });
    }

    function rotateToken() {
      return req("rotate", {})
        .then(function (payload) {
          toast("success", "Cron token rotated");
          renderStatus({
            ok: true,
            status: payload.status,
            guidance: (current && current.guidance) || {},
          });
        })
        .catch(function (error) {
          toast("error", error.message);
        });
    }

    var modal = createAuthModal(function () {
      toast("success", "Session unlocked");
      return refresh().then(function () {
        if (typeof pendingAfterAuth === "function") {
          var next = pendingAfterAuth;
          pendingAfterAuth = null;
          return next();
        }
      });
    });

    unlockBtn.addEventListener("click", function () {
      pendingAfterAuth = null;
      modal.open();
    });

    revealBtn.addEventListener("click", function () {
      if (!current || !current.status || !current.status.reveal_authorized) {
        pendingAfterAuth = revealToken;
        modal.open();
        return;
      }
      revealToken();
    });

    copyBtn.addEventListener("click", function () {
      if (!revealInput.value) return;
      Promise.resolve(
        navigator.clipboard && navigator.clipboard.writeText(revealInput.value),
      )
        .then(function () {
          toast("info", "Token copied");
        })
        .catch(function () {
          window.prompt("Copy token", revealInput.value);
        });
    });

    rotateBtn.addEventListener("click", function () {
      if (
        !window.confirm(
          "Rotate the cron token now? Existing cron callers will stop working until updated.",
        )
      ) {
        return;
      }
      if (!current || !current.status || !current.status.reveal_authorized) {
        pendingAfterAuth = rotateToken;
        modal.open();
        return;
      }
      rotateToken();
    });

    revokeBtn.addEventListener("click", function () {
      req("revoke", {})
        .then(function () {
          setVisibleToken("");
          toast("info", "Reveal authorization cleared");
          return refresh();
        })
        .catch(function (error) {
          toast("error", error.message);
        });
    });

    refresh();
  }

  window.ConfigPageHooks = window.ConfigPageHooks || {};
  var prevAfterRender = window.ConfigPageHooks.afterRender;
  window.ConfigPageHooks.afterRender = function (section, pane) {
    if (typeof prevAfterRender === "function") {
      prevAfterRender(section, pane);
    }
    try {
      if ((section || "").toLowerCase() === "security") {
        renderTokenTools(pane);
      }
    } catch (error) {
      if (window.console) console.error("[config:security]", error);
    }
  };

  if ((window.__CONFIG_ACTIVE_SECTION__ || "").toLowerCase() === "security") {
    window.setTimeout(function () {
      renderTokenTools(
        window.__CONFIG_PANE__ || document.getElementById("configPane"),
      );
    }, 0);
  }
})();
