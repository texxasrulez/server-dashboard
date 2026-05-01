(function () {
  "use strict";

  function ensureModal() {
    var modal = document.getElementById("diagModal");
    if (modal) return modal;

    var style = document.createElement("style");
    style.textContent = [
      ".diag-modal{position:fixed;inset:0;background:rgba(8,12,18,.68);display:flex;align-items:center;justify-content:center;padding:1rem;z-index:9999}",
      ".diag-modal[hidden]{display:none !important}",
      ".diag-modal-dialog{width:min(1100px,100%);height:min(82vh,820px);background:var(--card,#111);border:1px solid rgba(255,255,255,.12);border-radius:18px;display:grid;grid-template-rows:auto 1fr;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.34)}",
      ".diag-modal-head{display:flex;justify-content:space-between;gap:1rem;align-items:center;padding:.85rem 1rem;border-bottom:1px solid var(--border)}",
      ".diag-modal-body{padding:1rem;overflow:auto}",
      ".diag-modal-body iframe{width:100%;height:100%;border:0;border-radius:12px;background:#fff}",
      ".diag-modal-pre{margin:0;white-space:pre-wrap;word-break:break-word}",
    ].join("");
    document.head.appendChild(style);

    modal = document.createElement("div");
    modal.id = "diagModal";
    modal.className = "diag-modal";
    modal.hidden = true;
    modal.innerHTML = [
      '<div class="diag-modal-dialog">',
      '  <div class="diag-modal-head">',
      '    <strong id="diagModalTitle">Details</strong>',
      '    <button type="button" class="btn secondary" id="diagModalClose">Close</button>',
      "  </div>",
      '  <div class="diag-modal-body" id="diagModalBody"></div>',
      "</div>",
    ].join("");
    document.body.appendChild(modal);

    modal.addEventListener("click", function (event) {
      if (event.target === modal) {
        modal.hidden = true;
      }
    });
    document
      .getElementById("diagModalClose")
      .addEventListener("click", function () {
        modal.hidden = true;
      });
    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && !modal.hidden) {
        modal.hidden = true;
      }
    });

    return modal;
  }

  function openModal(title, node) {
    var modal = ensureModal();
    document.getElementById("diagModalTitle").textContent = title || "Details";
    var body = document.getElementById("diagModalBody");
    body.innerHTML = "";
    body.appendChild(node);
    modal.hidden = false;
  }

  document.addEventListener("click", function (event) {
    var copyButton = event.target.closest("[data-copy-text]");
    if (copyButton) {
      event.preventDefault();
      var text = copyButton.getAttribute("data-copy-text") || "";
      if (!text) return;

      Promise.resolve(
        navigator.clipboard && navigator.clipboard.writeText(text),
      )
        .then(function () {
          if (window.toast && window.toast.info) {
            window.toast.info("Copied");
          }
        })
        .catch(function () {
          window.prompt("Copy value", text);
        });
      return;
    }

    var modalLink = event.target.closest("[data-modal-url]");
    if (!modalLink) return;
    event.preventDefault();

    var url = modalLink.getAttribute("data-modal-url") || modalLink.href;
    var kind = modalLink.getAttribute("data-modal-kind") || "iframe";
    var title = (modalLink.textContent || "").trim();

    if (kind === "json") {
      fetch(url, { credentials: "same-origin" })
        .then(function (response) {
          return response.text().then(function (text) {
            var pretty = text;
            try {
              pretty = JSON.stringify(JSON.parse(text), null, 2);
            } catch (_) {}
            var pre = document.createElement("pre");
            pre.className = "diag-modal-pre";
            pre.textContent = pretty;
            openModal(title, pre);
          });
        })
        .catch(function (error) {
          var pre = document.createElement("pre");
          pre.className = "diag-modal-pre";
          pre.textContent = "Unable to load: " + error.message;
          openModal(title, pre);
        });
      return;
    }

    var frame = document.createElement("iframe");
    frame.src = url;
    frame.loading = "lazy";
    openModal(title, frame);
  });
})();
