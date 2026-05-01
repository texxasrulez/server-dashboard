/* assets/js/pages/bookmarks.js */
(function () {
  const SOURCE_STORAGE_KEY = "serverDashboard.bookmarks.source";
  let editId = null;
  let API_BASE = null;
  let CATS = [];
  let BOOKS = [];
  let SOURCE = "local";
  let ROUND_DAV_CONFIGURED = false;
  let DEFAULT_SOURCE = "local";
  let LAST_CATEGORY_SOURCE = "";
  let LAST_BOOKMARK_SOURCE = "";

  function t(key, fallback, vars) {
    try {
      if (window.I18N && typeof window.I18N.t === "function") {
        return window.I18N.t(key, fallback, vars);
      }
    } catch (_error) {}
    return fallback != null ? fallback : key;
  }

  function $(selector, root) {
    return (root || document).querySelector(selector);
  }

  function esc(value) {
    return String(value || "").replace(/[&<>"']/g, function (char) {
      return {
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#39;",
      }[char];
    });
  }

  function tOK(message) {
    try {
      toast && toast.success && toast.success(message);
    } catch (_error) {}
  }

  function tWarn(message) {
    try {
      toast && toast.warn && toast.warn(message);
    } catch (_error) {}
  }

  function tErr(message, sticky) {
    try {
      toast && toast.error && toast.error(message, { sticky: !!sticky });
    } catch (_error) {}
  }

  function logI(eventName, data) {
    try {
      clientLog && clientLog.info && clientLog.info(eventName, data || {});
    } catch (_error) {}
  }

  function logE(eventName, data) {
    try {
      clientLog && clientLog.error && clientLog.error(eventName, data || {});
    } catch (_error) {}
  }

  function csrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta && meta.content ? String(meta.content) : "";
  }

  function apiFetch(url, options) {
    const opts = options ? Object.assign({}, options) : {};
    if (!opts.credentials) {
      opts.credentials = "same-origin";
    }
    const method = String(opts.method || "GET").toUpperCase();
    if (method !== "GET" && method !== "HEAD" && method !== "OPTIONS") {
      const token = csrfToken();
      if (token) {
        const headers = new Headers(opts.headers || {});
        if (!headers.has("X-CSRF-Token")) {
          headers.set("X-CSRF-Token", token);
        }
        opts.headers = headers;
        if (opts.body instanceof URLSearchParams && !opts.body.has("_csrf")) {
          opts.body.set("_csrf", token);
        } else if (
          (headers.get("Content-Type") || "").indexOf("application/json") >= 0 &&
          typeof opts.body === "string"
        ) {
          try {
            const parsed = JSON.parse(opts.body);
            if (parsed && typeof parsed === "object" && !parsed._csrf && !parsed.csrf) {
              parsed._csrf = token;
              opts.body = JSON.stringify(parsed);
            }
          } catch (_error) {}
        }
      }
    }
    return fetch(url, opts);
  }

  function join(a, b) {
    if (!a.endsWith("/")) {
      a += "/";
    }
    return a + b.replace(/^\/+/, "");
  }

  function dirname(path) {
    const index = path.lastIndexOf("/");
    if (index <= 0) {
      return "/";
    }
    return path.slice(0, index + 1);
  }

  function uniq(items) {
    const seen = new Set();
    const out = [];
    for (let index = 0; index < items.length; index += 1) {
      if (!seen.has(items[index])) {
        seen.add(items[index]);
        out.push(items[index]);
      }
    }
    return out;
  }

  function __projectBase() {
    try {
      if (typeof window !== "undefined" && window.PROJECT_BASE) {
        return String(window.PROJECT_BASE);
      }
    } catch (_error) {}
    try {
      const scripts = document.getElementsByTagName("script");
      for (let index = scripts.length - 1; index >= 0; index -= 1) {
        const src = scripts[index].getAttribute("src") || "";
        const match = src.match(/^(.*)\/assets\/js\/(?:pages|util)\/[^/]+\.js(?:\?.*)?$/i);
        if (match) {
          return match[1] || "/";
        }
      }
    } catch (_error) {}
    try {
      const path = location.pathname.replace(/\/[^/]*$/, "");
      return path || "/";
    } catch (_error) {}
    return "/";
  }

  function __apiBase() {
    try {
      if (typeof window !== "undefined" && window.API_BASE) {
        return String(window.API_BASE);
      }
    } catch (_error) {}
    const base = __projectBase().replace(/\/+$/, "");
    return base + "/api/";
  }

  function __join(a, b) {
    if (!a) {
      return b;
    }
    if (!b) {
      return a;
    }
    return a.replace(/\/+$/, "") + "/" + String(b).replace(/^\/+/, "");
  }

  function apiUrl(endpoint) {
    return __join(__apiBase(), endpoint);
  }

  function appendSource(url, source) {
    const next = new URL(url, window.location.origin);
    next.searchParams.set("source", source || SOURCE);
    return next.toString();
  }

  function resolveFaviconUrl(path) {
    const value = String(path || "").trim();
    if (!value) {
      return "";
    }
    if (/^https?:\/\//i.test(value)) {
      return value;
    }
    if (value[0] === "/") {
      if (/^\/favicon_proxy\.php(?:\?|$)/i.test(value)) {
        return apiUrl(value.replace(/^\/+/, ""));
      }
      return value;
    }
    if (value.indexOf("api/") === 0) {
      return __join(__projectBase(), value);
    }
    return apiUrl(value);
  }

  function parseJsonResponse(response) {
    return response.text().then(function (text) {
      if (!response.ok) {
        const head = text.slice(0, 200).replace(/\s+/g, " ").trim();
        throw new Error("HTTP " + response.status + (head ? " - " + head : ""));
      }
      try {
        return JSON.parse(text);
      } catch (_error) {
        logE("bm_bad_json", { snippet: text.slice(0, 200) });
        throw new Error(t("bookmarks_page.errors.bad_json", "Bad JSON from server"));
      }
    });
  }

  function currentSource() {
    return SOURCE === "rounddav" ? "rounddav" : "local";
  }

  function isRoundDavSource() {
    return currentSource() === "rounddav";
  }

  function categoryLabelText() {
    return isRoundDavSource() ? "Folder" : t("bookmarks_page.category", "Category");
  }

  function defaultCategoryLabel() {
    return isRoundDavSource() ? "Root / Unfiled" : t("bookmarks_page.uncategorized", "Uncategorized");
  }

  function sourceOptionLabel(source) {
    return source === "rounddav" ? "RoundDAV" : "Local";
  }

  function setSourceNote(message) {
    const note = $("#bmSourceNote");
    if (note) {
      note.textContent = message || "";
    }
  }

  function refreshSourceNote(extraMessage) {
    const sourceLabel = sourceOptionLabel(currentSource());
    const parts = ["Active source: " + sourceLabel];

    if (LAST_BOOKMARK_SOURCE) {
      parts.push("Bookmarks API: " + sourceOptionLabel(LAST_BOOKMARK_SOURCE));
    }

    if (LAST_CATEGORY_SOURCE) {
      parts.push("Folders API: " + sourceOptionLabel(LAST_CATEGORY_SOURCE));
    }

    if (extraMessage) {
      parts.push(extraMessage);
    } else if (isRoundDavSource()) {
      parts.push(
        ROUND_DAV_CONFIGURED
          ? "RoundDAV mode uses existing remote folders. Folder creation and reordering remain local-only here."
          : "RoundDAV is not configured in Config."
      );
    } else {
      parts.push(
        ROUND_DAV_CONFIGURED
          ? "Local bookmarks stay in the dashboard JSON store. Switch to RoundDAV when you want to work against the remote bookmark system."
          : "Local bookmarks stay in the dashboard JSON store."
      );
    }

    setSourceNote(parts.join(" | "));
  }

  function updateSourceUI() {
    const label = categoryLabelText();
    document.querySelectorAll("[data-category-label]").forEach(function (node) {
      node.textContent = label;
    });

    document.querySelectorAll(".local-only").forEach(function (node) {
      node.classList.toggle("is-hidden", isRoundDavSource());
    });

    const sourceSelect = $("#bmSource");
    if (sourceSelect) {
      sourceSelect.value = currentSource();
    }

    refreshSourceNote();
  }

  function persistSource(source) {
    try {
      window.localStorage.setItem(SOURCE_STORAGE_KEY, source);
    } catch (_error) {}
  }

  function loadStoredSource(defaultSource) {
    try {
      const stored = window.localStorage.getItem(SOURCE_STORAGE_KEY);
      if (stored === "rounddav" || stored === "local") {
        return stored;
      }
    } catch (_error) {}
    return defaultSource;
  }

  function setSource(nextSource, persist) {
    const target = nextSource === "rounddav" ? "rounddav" : "local";
    if (target === "rounddav" && !ROUND_DAV_CONFIGURED) {
      SOURCE = "local";
      updateSourceUI();
      tWarn("RoundDAV is not configured in Config.");
      return Promise.resolve();
    }
    SOURCE = target;
    updateSourceUI();
    resetForm();
    catReset();
    if (persist !== false) {
      persistSource(SOURCE);
    }
    return loadCategories().then(loadList).catch(function () {
      return loadList();
    });
  }

  function resolveCandidates(endpoint) {
    const loc = window.location;
    const base = loc.origin + loc.pathname;
    const dir = dirname(base);
    const candidates = [];
    try {
      if (typeof project_url === "function") {
        candidates.push(project_url("/api/" + endpoint));
      }
    } catch (_error) {}
    candidates.push(apiUrl(endpoint));
    candidates.push(loc.origin + "/api/" + endpoint);
    candidates.push(join(dir, "api/" + endpoint));
    const up = dirname(dir.slice(0, -1));
    candidates.push(join(up, "api/" + endpoint));
    candidates.push("api/" + endpoint);
    return uniq(candidates);
  }

  function fetchFirstGood(endpoint, opt) {
    const urls = resolveCandidates(endpoint);
    let index = 0;
    let lastErr = null;
    const tried = [];
    return new Promise(function (resolve, reject) {
      (function step() {
        if (index >= urls.length) {
          reject({
            message: t("bookmarks_page.errors.all_endpoints_failed", "All endpoints failed"),
            tried: tried,
            lastErr: lastErr,
          });
          return;
        }
        const rawUrl = urls[index++];
        const url = appendSource(
          rawUrl + (opt && opt.cacheBust ? (rawUrl.indexOf("?") >= 0 ? "&" : "?") + "t=" + Date.now() : ""),
          currentSource()
        );
        tried.push(url);
        apiFetch(url, {
          cache: "no-store",
          method: (opt && opt.method) || "GET",
          headers: (opt && opt.headers) || undefined,
          body: (opt && opt.body) || undefined,
        })
          .then(function (response) {
            if (!response.ok) {
              lastErr = "HTTP " + response.status;
              step();
              return;
            }
            resolve({ url: rawUrl, resp: response, tried: tried });
          })
          .catch(function (error) {
            lastErr = (error && error.message) || String(error);
            step();
          });
      })();
    });
  }

  function wireCategoryForm(options) {
    const form = $(options.formSel);
    if (!form) {
      return;
    }
    const nameEl = $(options.nameSel);
    const idEl = options.idSel ? $(options.idSel) : null;
    const cancelBtn = $(options.cancelSel);
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      if (isRoundDavSource()) {
        tWarn("RoundDAV folder management is not available from this page yet.");
        return;
      }
      const id = (idEl && idEl.value) || "";
      const name = ((nameEl && nameEl.value) || "").trim();
      if (!name) {
        tWarn(t("bookmarks_page.validation.category_name_required", "Category name required"));
        nameEl && nameEl.focus();
        return;
      }
      const body = new URLSearchParams();
      if (id) {
        body.set("id", id);
      }
      body.set("name", name);
      body.set("source", currentSource());
      apiFetch(apiUrl("bm_categories_upsert.php"), { method: "POST", body: body })
        .then(parseJsonResponse)
        .then(function () {
          tOK(t("bookmarks_page.toasts.category_saved", "Category saved"));
          if (nameEl) {
            nameEl.value = "";
          }
          if (idEl) {
            idEl.value = "";
          }
          loadCategories().then(renderCategories);
        })
        .catch(function (error) {
          tErr(
            t("bookmarks_page.toasts.save_failed", "Save failed: {message}", {
              message: (error && error.message) || error,
            }),
            true
          );
        });
    });
    if (cancelBtn) {
      cancelBtn.addEventListener("click", function (event) {
        event.preventDefault();
        if (nameEl) {
          nameEl.value = "";
        }
        if (idEl) {
          idEl.value = "";
        }
      });
    }
  }

  function openModal() {
    const modal = $("#bmModal");
    if (!modal) {
      return;
    }
    modal.hidden = false;
    modal.setAttribute("aria-hidden", "false");
  }

  function closeModal() {
    const modal = $("#bmModal");
    if (!modal) {
      return;
    }
    modal.hidden = true;
    modal.setAttribute("aria-hidden", "true");
  }

  function wireModalButtons() {
    const openBtn = $("#openManage");
    const closeBtn = $("#bmModal .modal-close");
    if (openBtn) {
      openBtn.addEventListener("click", function (event) {
        event.preventDefault();
        openModal();
      });
    }
    if (closeBtn) {
      closeBtn.addEventListener("click", function (event) {
        event.preventDefault();
        closeModal();
      });
    }
    const modal = $("#bmModal");
    if (modal) {
      modal.addEventListener("click", function (event) {
        if (event.target === modal) {
          closeModal();
        }
      });
    }
  }

  function init() {
    const root = $("#bookmarks-root");
    const hinted = root && root.dataset && root.dataset.apiBase;
    const defaultSource = root && root.dataset ? root.dataset.defaultSource || "local" : "local";
    ROUND_DAV_CONFIGURED = !!(root && root.dataset && root.dataset.rounddavConfigured === "1");
    DEFAULT_SOURCE = defaultSource === "rounddav" ? "rounddav" : "local";
    SOURCE = DEFAULT_SOURCE === "rounddav" && ROUND_DAV_CONFIGURED
      ? "rounddav"
      : loadStoredSource(DEFAULT_SOURCE);
    if (SOURCE === "rounddav" && !ROUND_DAV_CONFIGURED) {
      SOURCE = "local";
    }

    if (hinted) {
      API_BASE = hinted.endsWith("/") ? hinted : hinted + "/";
      logI("bookmarks_api_base_hint", { base: API_BASE, source: currentSource() });
      boot();
      return;
    }

    fetchFirstGood("bookmarks_list.php", { cacheBust: true })
      .then(function (result) {
        API_BASE = result.url.replace(/bookmarks_list\.php[\s\S]*$/, "");
        logI("bookmarks_api_base", { base: API_BASE, tried: result.tried, source: currentSource() });
        boot();
      })
      .catch(function (error) {
        const tried = error && error.tried ? error.tried.slice(0, 3).join(" | ") : "";
        tErr(
          t("bookmarks_page.errors.api_not_found", "Failed to load bookmarks (API not found). Tried: {tried}", {
            tried: tried || "-",
          }),
          true
        );
        logE("bookmarks_api_discovery_failed", error || {});
      });
  }

  function boot() {
    wireUI();
    wireModalButtons();
    wireCategoryForm({ formSel: "#catForm", nameSel: "#catName", idSel: "#catId", cancelSel: "#catCancel" });
    wireCategoryForm({ formSel: "#catFormMain", nameSel: "#catNameMain", idSel: "#catIdMain", cancelSel: "#catCancelMain" });
    updateSourceUI();
    loadCategories().then(loadList).catch(function () {
      loadList();
    });
    handleBookmarkletPrefill();
  }

  function handleBookmarkletPrefill() {
    try {
      const hash = (location.hash || "").replace(/^#/, "");
      if (!hash.startsWith("add?")) {
        return;
      }
      const params = new URLSearchParams(hash.slice(4));
      const title = params.get("t") || "";
      const url = params.get("u") || "";
      const tags = params.get("tags") || "";
      if ($("#bmTitle")) {
        $("#bmTitle").value = decodeURIComponent(title);
      }
      if ($("#bmUrl")) {
        $("#bmUrl").value = decodeURIComponent(url);
      }
      if ($("#bmTags")) {
        $("#bmTags").value = decodeURIComponent(tags);
      }
      if ($("#bmTitle")) {
        $("#bmTitle").focus();
      }
      history.replaceState(null, document.title, location.pathname + location.search);
    } catch (_error) {}
  }

  function wireUI() {
    const refresh = $("#bmRefresh");
    if (refresh) {
      refresh.addEventListener("click", function () {
        loadCategories().then(loadList).catch(loadList);
      });
    }
    const cancel = $("#bmCancel");
    if (cancel) {
      cancel.addEventListener("click", resetForm);
    }
    const saveBtn = $("#bmSave");
    if (saveBtn) {
      saveBtn.addEventListener("click", function (event) {
        event.preventDefault();
        save();
      });
    }
    const catFilter = $("#bmCatFilter");
    if (catFilter) {
      catFilter.addEventListener("change", renderList);
    }
    const sortSelect = $("#bmSort");
    if (sortSelect) {
      sortSelect.addEventListener("change", renderList);
    }
    const sourceSelect = $("#bmSource");
    if (sourceSelect) {
      sourceSelect.value = currentSource();
      sourceSelect.addEventListener("change", function () {
        setSource(sourceSelect.value, true);
      });
    }
    const catSaveBtn = $("#catSave");
    if (catSaveBtn) {
      catSaveBtn.addEventListener("click", function (event) {
        event.preventDefault();
        catSave();
      });
    }
    const catCancelBtn = $("#catCancel");
    if (catCancelBtn) {
      catCancelBtn.addEventListener("click", function () {
        catReset();
      });
    }
    const catDeleteBtn = $("#catDelete");
    if (catDeleteBtn) {
      catDeleteBtn.addEventListener("click", function (event) {
        event.preventDefault();
        if (isRoundDavSource()) {
          tWarn("RoundDAV folder management is not available from this page yet.");
          return;
        }
        if (!$("#catId").value) {
          return;
        }
        if (!confirm(t("bookmarks_page.confirm_delete_category", "Delete this category? Bookmarks will become Uncategorized."))) {
          return;
        }
        apiFetch(appendSource(apiUrl("bm_categories_delete.php?id=" + encodeURIComponent($("#catId").value))), { method: "POST" })
          .then(parseJsonResponse)
          .then(function () {
            tOK(t("bookmarks_page.toasts.category_deleted", "Category deleted"));
            loadCategories().then(renderList);
          })
          .catch(function (error) {
            tErr(
              t("bookmarks_page.toasts.delete_failed", "Delete failed: {message}", {
                message: (error && error.message) || error,
              }),
              true
            );
          });
      });
    }
    const catList = $("#catList");
    if (catList) {
      catList.addEventListener("click", onCatListClick);
    }
  }

  function loadCategories() {
    return apiFetch(appendSource(apiUrl("bm_categories_list.php") + "?t=" + Date.now()), { cache: "no-store" })
      .then(parseJsonResponse)
      .then(function (data) {
        if (data && data.error) {
          throw new Error(data.error);
        }
        LAST_CATEGORY_SOURCE = data && data.source ? String(data.source) : currentSource();
        CATS = data && data.items ? data.items.slice().sort(catSort) : [];
        renderCategories();
        fillCategorySelects();
        refreshSourceNote();
        return CATS;
      })
      .catch(function (error) {
        LAST_CATEGORY_SOURCE = "";
        CATS = [];
        renderCategories();
        fillCategorySelects();
        refreshSourceNote();
        logE("bm_categories_load_failed", { source: currentSource(), err: String((error && error.message) || error) });
        if (isRoundDavSource()) {
          tErr("Failed to load RoundDAV folders: " + ((error && error.message) || error), true);
        }
      });
  }

  function catSort(a, b) {
    const aSort = a && a.sort != null ? a.sort | 0 : 0;
    const bSort = b && b.sort != null ? b.sort | 0 : 0;
    if (aSort !== bSort) {
      return aSort - bSort;
    }
    const aName = String((a && (a.full_name || a.name)) || "");
    const bName = String((b && (b.full_name || b.name)) || "");
    return aName.localeCompare(bName);
  }

  function renderCategories() {
    const root = $("#catList");
    const empty = $("#catEmpty");
    if (!root || !empty) {
      return;
    }
    if (!CATS || !CATS.length) {
      root.innerHTML = "";
      empty.style.display = "block";
      empty.textContent = isRoundDavSource() ? "No RoundDAV folders found." : t("bookmarks_page.empty_categories", "No categories yet.");
      return;
    }
    empty.style.display = "none";
    root.innerHTML = CATS.map(function (cat) {
      const name = cat.full_name || cat.name || "";
      const actions = isRoundDavSource()
        ? ""
        : '<span class="actions">' +
          '<button class="btn" data-act="cup">↑</button>' +
          '<button class="btn" data-act="cdn">↓</button>' +
          '<button class="btn" data-act="cedit">' + esc(t("common.edit", "Edit")) + "</button>" +
          '<button class="btn danger" data-act="cdel">' + esc(t("common.delete", "Delete")) + "</button>" +
          "</span>";
      return '<div class="row" data-id="' + esc(cat.id) + '">' +
        '<span class="name">' + esc(name) + "</span>" +
        actions +
        "</div>";
    }).join("");
  }

  function fillCategorySelects() {
    const select = $("#bmCategory");
    const filter = $("#bmCatFilter");
    const defaultOption = '<option value="">' + esc(defaultCategoryLabel()) + "</option>";
    const catOptions = CATS.map(function (cat) {
      const label = cat.full_name || cat.name || "";
      return '<option value="' + esc(cat.id) + '">' + esc(label) + "</option>";
    }).join("");
    if (select) {
      select.innerHTML = defaultOption + catOptions;
    }
    if (filter) {
      filter.innerHTML = '<option value="">' + esc(t("bookmarks_page.all", "All")) + "</option>" + catOptions;
    }
  }

  function onCatListClick(event) {
    if (isRoundDavSource()) {
      return;
    }
    const btn = event.target.closest && event.target.closest("button");
    if (!btn) {
      return;
    }
    const row = event.target.closest(".row");
    const id = row && row.getAttribute("data-id");
    if (!id) {
      return;
    }
    const index = CATS.findIndex(function (cat) {
      return cat.id === id;
    });
    if (index < 0) {
      return;
    }
    const action = btn.dataset.act;
    if (action === "cedit") {
      $("#catId").value = CATS[index].id;
      $("#catName").value = CATS[index].name || "";
      $("#catName").focus();
      return;
    }
    if (action === "cdel") {
      if (!confirm(t("bookmarks_page.confirm_delete_category", "Delete this category? Bookmarks will become Uncategorized."))) {
        return;
      }
      apiFetch(appendSource(apiUrl("bm_categories_delete.php?id=" + encodeURIComponent(id))), { method: "POST" })
        .then(parseJsonResponse)
        .then(function () {
          tOK(t("bookmarks_page.toasts.category_deleted", "Category deleted"));
          loadCategories().then(renderList);
        })
        .catch(function (error) {
          tErr(
            t("bookmarks_page.toasts.delete_failed", "Delete failed: {message}", {
              message: (error && error.message) || error,
            }),
            true
          );
        });
      return;
    }
    if (action === "cup" || action === "cdn") {
      const nextIndex = action === "cup" ? Math.max(0, index - 1) : Math.min(CATS.length - 1, index + 1);
      if (nextIndex === index) {
        return;
      }
      const item = CATS[index];
      CATS.splice(index, 1);
      CATS.splice(nextIndex, 0, item);
      renderCategories();
      const ids = CATS.map(function (cat) {
        return cat.id;
      });
      apiFetch(apiUrl("bm_categories_reorder.php"), {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ ids: ids, source: currentSource() }),
      })
        .then(parseJsonResponse)
        .then(function () {
          tOK(t("bookmarks_page.toasts.order_saved", "Order saved"));
          fillCategorySelects();
          renderList();
        })
        .catch(function (error) {
          tErr(
            t("bookmarks_page.toasts.reorder_failed", "Reorder failed: {message}", {
              message: (error && error.message) || error,
            }),
            true
          );
        });
    }
  }

  function catReset() {
    if ($("#catId")) {
      $("#catId").value = "";
    }
    if ($("#catName")) {
      $("#catName").value = "";
    }
  }

  function catSave() {
    if (isRoundDavSource()) {
      tWarn("RoundDAV folder management is not available from this page yet.");
      return;
    }
    const id = $("#catId").value || null;
    const name = (($("#catName") && $("#catName").value) || "").trim();
    if (!name) {
      tWarn(t("bookmarks_page.validation.category_name_required", "Category name required"));
      return;
    }
    apiFetch(apiUrl("bm_categories_upsert.php"), {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id: id, name: name, source: currentSource() }),
    })
      .then(parseJsonResponse)
      .then(function () {
        tOK(id ? t("bookmarks_page.toasts.category_updated", "Category updated") : t("bookmarks_page.toasts.category_added", "Category added"));
        catReset();
        loadCategories().then(renderList);
      })
      .catch(function (error) {
        tErr(
          t("bookmarks_page.toasts.save_failed", "Save failed: {message}", {
            message: (error && error.message) || error,
          }),
          true
        );
      });
  }

  function loadList() {
    return apiFetch(appendSource(apiUrl("bookmarks_list.php") + "?t=" + Date.now()), { cache: "no-store" })
      .then(parseJsonResponse)
      .then(function (data) {
        if (!data || data.error) {
          throw new Error((data && data.error) || "Unknown error");
        }
        LAST_BOOKMARK_SOURCE = data && data.source ? String(data.source) : currentSource();
        BOOKS = data.items ? data.items : [];
        renderList();
        refreshSourceNote();
      })
      .catch(function (error) {
        LAST_BOOKMARK_SOURCE = "";
        BOOKS = [];
        renderList();
        refreshSourceNote();
        tErr(
          t("bookmarks_page.errors.load_failed", "Failed to load bookmarks: {message}", {
            message: (error && error.message) || error,
          }),
          true
        );
        logE("bookmarks_load_failed", { source: currentSource(), err: String((error && error.message) || error) });
      });
  }

  function currentCatFilter() {
    return ($("#bmCatFilter") && $("#bmCatFilter").value) || "";
  }

  function currentSort() {
    return ($("#bmSort") && $("#bmSort").value) || "updated_desc";
  }

  function renderList() {
    const list = $("#bmList");
    const empty = $("#bmEmpty");
    if (!list || !empty) {
      return;
    }
    let items = BOOKS.slice();
    const cat = currentCatFilter();
    const sort = currentSort();
    if (cat) {
      items = items.filter(function (bookmark) {
        return String(bookmark.category_id || bookmark.folder_id || "") === String(cat);
      });
    }
    items.sort(function (a, b) {
      if (sort === "title_asc") {
        return String(a.title || "").localeCompare(String(b.title || ""));
      }
      if (sort === "host_asc") {
        return String(a.host || "").localeCompare(String(b.host || ""));
      }
      return (b.updated || 0) - (a.updated || 0);
    });
    if (!items.length) {
      list.innerHTML = "";
      empty.style.display = "block";
      empty.textContent = isRoundDavSource() ? "No RoundDAV bookmarks found." : t("bookmarks_page.empty", "No bookmarks yet.");
      return;
    }
    empty.style.display = "none";
    list.innerHTML = items.map(renderItem).join("");
    list.onclick = onListClick;
  }

  function renderItem(item) {
    const fav = item.favicon && String(item.favicon).trim()
      ? resolveFaviconUrl(item.favicon)
      : item.host
        ? apiUrl("favicon_proxy.php") + "?host=" + encodeURIComponent(item.host || "") + "&v=" + encodeURIComponent(item.updated || "")
        : "";
    const tags = (item.tags || []).join(", ");
    const updated = item.updated ? new Date(item.updated * 1000).toLocaleString() : "-";
    const catName = item.folder_path || item.folder_name || (item.category_id ? (CATS.find(function (cat) { return String(cat.id) === String(item.category_id); }) || {}).name : "");
    const titlePrefix = fav ? '<img src="' + esc(fav) + '" alt="" class="fav"> ' : "";
    return '<div class="row" data-id="' + esc(item.id) + '">' +
      '<span class="title">' + titlePrefix + esc(item.title || "") + (catName ? ' <span class="muted">· ' + esc(catName) + "</span>" : "") + "</span>" +
      '<span class="link"><a href="' + esc(item.url) + '" target="_blank" rel="noopener">' + esc(item.url) + "</a></span>" +
      '<span class="tags">' + esc(tags) + "</span>" +
      '<span class="updated">' + esc(updated) + "</span>" +
      '<span class="actions">' +
      '<button class="btn" data-act="edit">' + esc(t("common.edit", "Edit")) + "</button>" +
      '<button class="btn danger" data-act="delete">' + esc(t("common.delete", "Delete")) + "</button>" +
      "</span>" +
      "</div>";
  }

  function onListClick(event) {
    const btn = event.target.closest && event.target.closest("button");
    if (!btn) {
      return;
    }
    const row = event.target.closest(".row");
    const id = row && row.getAttribute("data-id");
    if (btn.dataset.act === "edit") {
      editId = id;
      const saveBtn = $("#bmSave");
      if (saveBtn) {
        saveBtn.textContent = t("common.update", "Update");
      }
      const item = BOOKS.find(function (entry) {
        return String(entry.id) === String(id);
      });
      if (!item) {
        return;
      }
      if ($("#bmTitle")) {
        $("#bmTitle").value = item.title || "";
      }
      if ($("#bmUrl")) {
        $("#bmUrl").value = item.url || "";
      }
      if ($("#bmTags")) {
        $("#bmTags").value = (item.tags || []).join(", ");
      }
      if ($("#bmCategory")) {
        $("#bmCategory").value = item.folder_id || item.category_id || "";
      }
      window.scrollTo({ top: 0, behavior: "smooth" });
      return;
    }
    if (btn.dataset.act === "delete" && id) {
      if (!confirm(t("bookmarks_page.confirm_delete_bookmark", "Delete this bookmark?"))) {
        return;
      }
      apiFetch(appendSource(apiUrl("bookmarks_delete.php?id=" + encodeURIComponent(id))), { method: "POST" })
        .then(parseJsonResponse)
        .then(function () {
          tOK(t("bookmarks_page.toasts.bookmark_deleted", "Bookmark deleted"));
          loadList();
        })
        .catch(function (error) {
          tErr(
            t("bookmarks_page.toasts.delete_failed", "Delete failed: {message}", {
              message: (error && error.message) || error,
            }),
            true
          );
        });
    }
  }

  function resetForm() {
    editId = null;
    const saveBtn = $("#bmSave");
    if (saveBtn) {
      saveBtn.textContent = t("common.save", "Save");
    }
    if ($("#bmTitle")) {
      $("#bmTitle").value = "";
    }
    if ($("#bmUrl")) {
      $("#bmUrl").value = "";
    }
    if ($("#bmTags")) {
      $("#bmTags").value = "";
    }
    if ($("#bmCategory")) {
      $("#bmCategory").value = "";
    }
  }

  function save() {
    const payload = {
      id: editId,
      title: (($("#bmTitle") && $("#bmTitle").value) || "").trim(),
      url: (($("#bmUrl") && $("#bmUrl").value) || "").trim(),
      tags: (($("#bmTags") && $("#bmTags").value) || "")
        .split(",")
        .map(function (part) { return part.trim(); })
        .filter(Boolean),
      source: currentSource(),
    };
    const folderValue = ($("#bmCategory") && $("#bmCategory").value) || "";
    if (isRoundDavSource()) {
      payload.folder_id = folderValue || null;
    } else {
      payload.category_id = folderValue || null;
    }
    if (!payload.url) {
      tWarn(t("bookmarks_page.validation.url_required", "URL is required"));
      return;
    }
    apiFetch(apiUrl("bookmarks_upsert.php"), {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    })
      .then(parseJsonResponse)
      .then(function (response) {
        if (!response || response.error) {
          tErr(
            t("bookmarks_page.toasts.save_failed", "Save failed: {message}", {
              message: (response && response.error) || "",
            }),
            true
          );
          return;
        }
        tOK(editId ? t("bookmarks_page.toasts.bookmark_updated", "Bookmark updated") : t("bookmarks_page.toasts.bookmark_added", "Bookmark added"));
        resetForm();
        loadList();
      })
      .catch(function (error) {
        tErr(
          t("bookmarks_page.toasts.save_failed", "Save failed: {message}", {
            message: (error && error.message) || error,
          }),
          true
        );
      });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init, { once: true });
  } else {
    init();
  }
})();
