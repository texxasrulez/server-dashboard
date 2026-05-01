(function () {
  "use strict";

  function $(selector, ctx) {
    return (ctx || document).querySelector(selector);
  }

  function create(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (typeof text === "string") node.textContent = text;
    return node;
  }

  function report() {
    return window.__ASSETS_AUDIT__ || {};
  }

  function renderJson() {
    var output = $("#aa-output");
    if (!output) return;
    output.textContent = JSON.stringify(report(), null, 2);
  }

  function ensureToolbar() {
    var tools = $(".audit-toolbar");
    if (!tools || tools.dataset.ready === "1") return;
    tools.dataset.ready = "1";

    var refresh = create("button", "btn", "Refresh Snapshot");
    var open = create("button", "btn secondary", "Open JSON");
    var copy = create("button", "btn secondary", "Copy JSON");

    refresh.addEventListener("click", function () {
      window.location.reload();
    });

    open.addEventListener("click", function () {
      var payload = JSON.stringify(report(), null, 2);
      var blob = new Blob([payload], { type: "application/json" });
      var url = URL.createObjectURL(blob);
      window.open(url, "_blank", "noopener");
      window.setTimeout(function () {
        URL.revokeObjectURL(url);
      }, 15000);
    });

    copy.addEventListener("click", function () {
      var payload = JSON.stringify(report(), null, 2);
      Promise.resolve(
        navigator.clipboard && navigator.clipboard.writeText(payload),
      )
        .then(function () {
          if (window.toast && window.toast.info) {
            window.toast.info("Audit JSON copied");
          }
        })
        .catch(function () {
          window.prompt("Copy audit JSON", payload);
        });
    });

    tools.appendChild(refresh);
    tools.appendChild(open);
    tools.appendChild(copy);
  }

  ensureToolbar();
  renderJson();
})();
