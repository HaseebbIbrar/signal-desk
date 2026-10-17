// Signal Desk (PHP) - small vanilla JS for actions without page reloads.
// All text goes in via textContent (never innerHTML), so sheet content can't inject markup.
(function () {
  "use strict";

  var body = document.body;
  var page = body.getAttribute("data-page");
  var CSRF = body.getAttribute("data-csrf") || "";
  var app = document.getElementById("app");
  if (!app || !page) return;

  // ---------------------------------------------------------------- helpers
  function h(tag, attrs) {
    var el = document.createElement(tag);
    if (attrs) {
      Object.keys(attrs).forEach(function (k) {
        var v = attrs[k];
        if (v === null || v === undefined || v === false) return;
        if (k === "class") el.className = v;
        else if (k === "text") el.textContent = v;
        else if (k.slice(0, 2) === "on") el.addEventListener(k.slice(2), v);
        else if (v === true) el.setAttribute(k, "");
        else el.setAttribute(k, v);
      });
    }
    for (var i = 2; i < arguments.length; i++) {
      var c = arguments[i];
      if (c === null || c === undefined || c === false) continue;
      if (Array.isArray(c)) c.forEach(function (x) { if (x) el.appendChild(typeof x === "string" ? document.createTextNode(x) : x); });
      else el.appendChild(typeof c === "string" ? document.createTextNode(c) : c);
    }
    return el;
  }
  function clear(el) { while (el.firstChild) el.removeChild(el.firstChild); }
  function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

  function safeHref(v) {
    if (!v) return null;
    var s = String(v).trim();
    if (!/^https?:\/\//i.test(s)) return null;
    try { var u = new URL(s); return (u.protocol === "https:" || u.protocol === "http:") ? u.toString() : null; } catch (e) { return null; }
  }

  // Same rules as the server (the server is the authority).
  function normaliseLinkedIn(input) {
    var s = String(input || "").trim();
    if (!s) return { ok: false, error: "Empty link" };
    if (s.length > 500) return { ok: false, error: "Link is too long" };
    if (/\s/.test(s)) return { ok: false, error: "Link must not contain spaces" };
    if (!/^[a-z][a-z0-9+.-]*:\/\//i.test(s)) s = "https://" + s;
    var u;
    try { u = new URL(s); } catch (e) { return { ok: false, error: "Not a valid link" }; }
    if (u.protocol !== "https:" && u.protocol !== "http:") return { ok: false, error: "Not a valid link" };
    if (u.username || u.password || u.port) return { ok: false, error: "Not a valid link" };
    if (!/^(?:www\.|[a-z]{2}\.)?linkedin\.com$/.test(u.hostname.toLowerCase())) return { ok: false, error: "Must be a linkedin.com link" };
    var m = /^\/(in|sales)\/[^/]+/.exec(u.pathname);
    if (!m) return { ok: false, error: "Use a linkedin.com/in/… or linkedin.com/sales/… link" };
    var path = u.pathname.replace(/\/+$/, "");
    return { ok: true, url: "https://www.linkedin.com" + path + (m[1] === "sales" ? u.search : "") };
  }
  function validateLinks(inputs) {
    var links = [], errors = inputs.map(function () { return null; });
    inputs.forEach(function (raw, i) {
      if (!String(raw || "").trim()) return;
      var r = normaliseLinkedIn(raw);
      if (!r.ok) errors[i] = r.error; else if (links.indexOf(r.url) < 0) links.push(r.url);
    });
    return { links: links, errors: errors, tooMany: links.length > 10 };
  }

  // Busy workbook (nightly job saving): the server answers SHEET_BUSY; we wait and retry, and say so.
  function api(path, opts) {
    opts = opts || {};
    var attempt = 0;
    function go() {
      var init = { method: opts.method || "GET", credentials: "same-origin", cache: "no-store", headers: {} };
      if (opts.body !== undefined) {
        init.headers["Content-Type"] = "application/json";
        init.headers["X-CSRF-Token"] = CSRF;
        init.body = JSON.stringify(opts.body);
      }
      return fetch(path, init).then(function (res) {
        if (res.status === 401) { window.location.href = "/login"; throw new Error("Please sign in again."); }
        return res.json().catch(function () { return {}; }).then(function (data) {
          if (res.status === 503 && data.code === "SHEET_BUSY" && attempt < 5) {
            attempt++;
            if (opts.onBusy) opts.onBusy(attempt);
            return sleep(1500 * attempt).then(go);
          }
          if (opts.onBusy) opts.onBusy(0);
          if (!res.ok) {
            var err = new Error(res.status === 503 && data.code === "SHEET_BUSY"
              ? "The sheet is still busy (probably the nightly update). Nothing was lost. Please try again in a minute."
              : (data.error || ("Request failed (" + res.status + ")")));
            err.code = data.code;
            throw err;
          }
          return data;
        });
      }, function () {
        if (opts.onBusy) opts.onBusy(0);
        throw new Error("Network error. Check your connection and try again.");
      });
    }
    return go();
  }

  var fieldClass = "w-full rounded-xl border border-line bg-black/40 px-3 py-2.5 text-base text-white placeholder:text-white/40 focus:border-pink focus:outline-none sm:text-sm";

  function notice(kind, text, onClose) {
    var styles = {
      ok: "border-ok/50 bg-ok/10 text-ok", warn: "border-warn/50 bg-warn/10 text-warn",
      error: "border-bad/50 bg-bad/10 text-bad", busy: "border-pink/50 bg-pink/10 text-pink-soft"
    }[kind];
    return h("div", { role: kind === "error" ? "alert" : "status", class: "flex items-start gap-2 rounded-xl border px-3 py-2 text-sm " + styles },
      kind === "busy" ? h("span", { class: "sd-spinner mt-0.5 shrink-0", "aria-hidden": "true" }) : null,
      h("div", { class: "min-w-0 flex-1 whitespace-pre-line", text: text }),
      onClose ? h("button", { type: "button", class: "shrink-0 px-1 text-white/60 hover:text-white", "aria-label": "Dismiss", onclick: onClose, text: "×" }) : null);
  }
  function chip(text, tone) {
    return h("span", { class: "inline-flex items-center rounded-full border px-2 py-0.5 text-xs " + (tone === "pink" ? "border-pink/60 text-pink-soft" : tone === "ok" ? "border-ok/60 text-ok" : "border-line text-white/75"), text: text });
  }
  function details(items) {
    var shown = items.filter(function (it) { return String(it[1] || "").trim(); });
    var box = h("div", { class: "mt-2 rounded-xl border border-line/60 bg-black/20 p-3" },
      shown.length ? h("dl", { class: "space-y-2 text-sm" }, shown.map(function (it) {
        return h("div", null, h("dt", { class: "text-xs uppercase tracking-wide text-white/50", text: it[0] }),
          h("dd", { class: "whitespace-pre-line break-words text-white/85", text: it[1] }));
      })) : h("p", { class: "text-sm text-white/50", text: "No extra details." }));
    return h("details", { class: "group mt-2" },
      h("summary", { class: "text-sm text-white/60 hover:text-white" },
        h("span", { class: "group-open:hidden", text: "▸ More details" }), h("span", { class: "hidden group-open:inline", text: "▾ Fewer details" })),
      box);
  }
  function busyText(n) { return n > 0 ? "Sheet busy, retrying… (attempt " + n + ")" : null; }
  function uniqSorted(arr) { var s = {}; arr.forEach(function (x) { if (x) s[x] = 1; }); return Object.keys(s).sort(); }

  // ================================================================ Signals page
  function signalsPage() {
    var st = { view: null, loading: true, error: null, busy: null, notice: null, pending: {},
      roleGroup: "", companyType: "", search: "", hideTicked: false, showRejected: false, date: null };

    var header = h("section", { class: "space-y-3 rounded-2xl border border-line bg-card/80 p-3" });
    var msgs = h("div", { class: "space-y-2" });
    var list = h("ul", { class: "space-y-3" });
    clear(app);
    app.appendChild(h("div", { class: "space-y-4" }, header, msgs, list));

    function load() {
      st.loading = true; st.error = null; render();
      var qs = [];
      if (st.date) qs.push("date=" + encodeURIComponent(st.date));
      if (st.showRejected) qs.push("rejected=1");
      return api("/api/signals" + (qs.length ? "?" + qs.join("&") : ""), { onBusy: function (n) { st.busy = busyText(n); renderMsgs(); } })
        .then(function (v) { st.view = v; st.date = v.selectedDate; }, function (e) { st.error = e.message; })
        .then(function () { st.loading = false; render(); });
    }

    function patchRows(fn) { if (st.view) st.view.rows = st.view.rows.map(fn); }

    function act(row, action) {
      if (!row.key || st.pending[row.key]) return;
      st.notice = null;
      if (action === "untick") {
        if (row.untickLocked) {
          st.notice = { kind: "warn", text: "Can't untick " + row.company + ": decision-maker information is already filled in for it (verify tab row " + row.verifyRow + ", columns M/S/T/U)." };
          return render();
        }
        if (!window.confirm('Remove "' + row.company + " – " + row.jobTitle + '" from "Signals to verify decision make"?\n\nThis deletes row ' + (row.verifyRow || "?") + " of that tab (rows below move up).")) return render();
      }
      if (action === "reject" && !window.confirm('Reject "' + row.company + " – " + row.jobTitle + '"?\n\nIt is hidden from your list (not written to the workbook). You can undo this with "Show rejected".')) return;
      st.pending[row.key] = true; render();
      api("/api/" + action, { method: "POST", body: { jobUrl: row.jobUrl }, onBusy: function (n) { st.busy = busyText(n); renderMsgs(); } })
        .then(function (res) {
          if (action === "tick") {
            patchRows(function (r) { return r.key === row.key ? Object.assign({}, r, { ticked: true, verifyRow: res.row, hasDecisionMaker: res.hasDecisionMaker, untickLocked: res.hasDecisionMaker, rejected: null }) : r; });
            st.notice = { kind: "ok", text: res.status === "already" ? row.company + " was already in the verify tab (row " + res.row + ")." : "Verified: added " + row.company + " to the verify tab (row " + res.row + ")." };
          } else if (action === "untick") {
            var removed = res.row;
            patchRows(function (r) {
              if (r.key === row.key) return Object.assign({}, r, { ticked: false, verifyRow: null, hasDecisionMaker: false, untickLocked: false });
              if (removed && r.verifyRow && r.verifyRow > removed) return Object.assign({}, r, { verifyRow: r.verifyRow - 1 });
              return r;
            });
            st.notice = { kind: "ok", text: res.status === "removed" ? "Removed " + row.company + " from the verify tab." : row.company + " was not in the verify tab." };
          } else if (action === "reject") {
            patchRows(function (r) { return r.key === row.key ? Object.assign({}, r, { rejected: res.rejected }) : r; });
            if (!st.showRejected) st.view.rows = st.view.rows.filter(function (r) { return r.key !== row.key; });
            st.view.counts.rejected++; st.view.counts.signals--;
            st.notice = { kind: "ok", text: "Rejected " + row.company + ". Turn on \"Show rejected\" to undo." };
          } else if (action === "unreject") {
            patchRows(function (r) { return r.key === row.key ? Object.assign({}, r, { rejected: null }) : r; });
            st.view.counts.rejected--; st.view.counts.signals++;
            st.notice = { kind: "ok", text: "Restored " + row.company + "." };
          }
        }, function (e) {
          if (e.code === "HAS_DECISION_MAKER") patchRows(function (r) { return r.key === row.key ? Object.assign({}, r, { untickLocked: true }) : r; });
          st.notice = { kind: "error", text: e.message };
        })
        .then(function () { delete st.pending[row.key]; render(); });
    }

    function renderHeader() {
      clear(header);
      var v = st.view;
      var isLatest = v && v.selectedDate && v.selectedDate === v.latestDate;
      var rows = v ? v.rows : [];
      var ticked = rows.filter(function (r) { return r.ticked && !r.rejected; }).length;
      var counter = v ? [h("strong", { class: "text-white", text: String(v.counts.signals) }), " signals " + (isLatest ? "today" : "on " + (v.selectedDate || "—")) + " · ",
        h("strong", { class: "text-pink-soft", text: String(ticked) }), " verified · ",
        h("strong", { class: "text-white/80", text: String(v.counts.rejected) }), " rejected"] : ["Loading…"];
      var dateSel = h("select", { class: fieldClass + " w-auto", disabled: !v || st.loading, "aria-label": "Block date",
        onchange: function (e) { st.date = e.target.value || null; load(); } },
        (v ? v.dates : []).map(function (d) { return h("option", { value: d, selected: d === v.selectedDate, text: d + (d === v.latestDate ? " (latest)" : "") }); }));
      var groups = uniqSorted(rows.map(function (r) { return r.roleGroup; }));
      var types = uniqSorted(rows.map(function (r) { return r.companyType; }));
      var search = h("input", { class: fieldClass + " col-span-2", type: "search", placeholder: "Search company, title, location…", value: st.search,
        oninput: function (e) { st.search = e.target.value; renderList(); } });
      var gSel = h("select", { class: fieldClass, "aria-label": "Role group", onchange: function (e) { st.roleGroup = e.target.value; renderList(); } },
        h("option", { value: "", text: "All role groups" }), groups.map(function (g) { return h("option", { value: g, selected: g === st.roleGroup, text: g }); }));
      var tSel = h("select", { class: fieldClass, "aria-label": "Company type", onchange: function (e) { st.companyType = e.target.value; renderList(); } },
        h("option", { value: "", text: "All company types" }), types.map(function (t) { return h("option", { value: t, selected: t === st.companyType, text: t }); }));
      header.appendChild(h("div", { class: "flex flex-wrap items-end justify-between gap-2" },
        h("div", null, h("h1", { class: "text-lg font-semibold", text: isLatest ? "Today's signals" : "Signals" }),
          h("p", { class: "text-sm text-white/70", "aria-live": "polite" }, counter)),
        h("label", { class: "text-sm" }, h("span", { class: "mb-1 block text-xs text-white/60", text: "Block date" }), dateSel)));
      header.appendChild(h("div", { class: "grid grid-cols-2 gap-2 sm:grid-cols-4" }, search, gSel, tSel));
      header.appendChild(h("div", { class: "flex flex-wrap gap-x-5 gap-y-2" },
        h("label", { class: "flex items-center gap-2 text-sm text-white/80" },
          h("input", { type: "checkbox", class: "h-5 w-5", checked: st.hideTicked, onchange: function (e) { st.hideTicked = e.target.checked; renderList(); } }), "Hide already verified"),
        h("label", { class: "flex items-center gap-2 text-sm text-white/80" },
          h("input", { type: "checkbox", class: "h-5 w-5", checked: st.showRejected, onchange: function (e) { st.showRejected = e.target.checked; load(); } }), "Show rejected")));
    }

    function renderMsgs() {
      clear(msgs);
      if (st.busy) msgs.appendChild(notice("busy", st.busy));
      if (st.notice) msgs.appendChild(notice(st.notice.kind, st.notice.text, function () { st.notice = null; renderMsgs(); }));
      if (st.error) msgs.appendChild(h("div", null, notice("error", st.error), h("button", { type: "button", class: "mt-1 text-sm underline", onclick: load, text: "Retry" })));
      if (st.loading && !st.view) msgs.appendChild(h("p", { class: "flex items-center gap-2 text-sm text-white/70" }, h("span", { class: "sd-spinner" }), " Reading the master sheet…"));
    }

    function card(row) {
      var href = safeHref(row.jobUrl);
      var pending = !!st.pending[row.key];
      var rejected = !!row.rejected;
      var tickbox = h("label", { class: "flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center rounded-xl border border-line bg-black/30", title: row.key ? "Verify" : "This row has no Job URL" },
        pending ? h("span", { class: "sd-spinner", "aria-label": "Saving" }) :
          h("input", { type: "checkbox", class: "h-6 w-6 cursor-pointer", checked: row.ticked, disabled: !row.key || rejected,
            "aria-label": (row.ticked ? "Untick " : "Verify ") + row.company + " – " + row.jobTitle,
            onchange: function () { act(row, row.ticked ? "untick" : "tick"); } }));
      var actions = h("div", { class: "mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm" },
        href ? h("a", { href: href, target: "_blank", rel: "noopener noreferrer", class: "font-medium text-pink-soft underline-offset-2 hover:underline", text: "Open job ↗" })
          : h("span", { class: "text-white/50", text: row.jobUrl ? "Job URL: " + row.jobUrl : "No Job URL" }),
        row.ticked ? h("span", { class: "text-xs text-white/60", text: "In verify tab" + (row.verifyRow ? " · row " + row.verifyRow : "") + (row.hasDecisionMaker ? " · decision maker filled" : "") }) : null,
        (!row.ticked && !rejected && row.key) ? h("button", { type: "button", class: "sd-btn-reject", disabled: pending, onclick: function () { act(row, "reject"); }, text: "Reject" }) : null,
        rejected ? h("span", { class: "text-xs text-white/60", text: "Rejected " + String(row.rejected.date).slice(0, 10) + " by " + row.rejected.user }) : null,
        rejected ? h("button", { type: "button", class: "sd-btn-secondary", disabled: pending, onclick: function () { act(row, "unreject"); }, text: "Undo reject" }) : null);
      return h("li", { class: "rounded-2xl border p-3 transition-colors " + (row.ticked ? "border-pink/70 bg-card-2" : "border-line bg-card") + (rejected ? " sd-rejected" : "") },
        h("div", { class: "flex gap-3" }, tickbox,
          h("div", { class: "min-w-0 flex-1" },
            h("h2", { class: "font-semibold leading-snug", text: row.company || "(no company)" }),
            h("p", { class: "text-sm text-white/85", text: row.jobTitle }),
            h("div", { class: "mt-1.5 flex flex-wrap gap-1.5" },
              row.roleGroup ? chip(row.roleGroup, "pink") : null, row.roleSubGroup ? chip(row.roleSubGroup, "pink") : null,
              row.companyType ? chip(row.companyType) : null, row.location ? chip(row.location) : null),
            actions,
            details([["Notes", row.notes], ["Lemlist / Prior Outreach", row.priorOutreach], ["Verified Evidence", row.verifiedEvidence],
              ["Matched keyword", row.matchedKeyword], ["Source", row.source], ["Status", row.status], ["Domain", row.domain], ["My comments", row.myComments]]))));
    }

    function renderList() {
      clear(list);
      var v = st.view;
      if (!v) return;
      list.className = "space-y-3" + (st.loading ? " opacity-60" : "");
      var q = st.search.trim().toLowerCase();
      var rows = v.rows.filter(function (r) {
        if (st.roleGroup && r.roleGroup !== st.roleGroup) return false;
        if (st.companyType && r.companyType !== st.companyType) return false;
        if (st.hideTicked && r.ticked) return false;
        if (q && [r.company, r.jobTitle, r.location, r.matchedKeyword, r.domain, r.notes, r.roleSubGroup].join(" ").toLowerCase().indexOf(q) < 0) return false;
        return true;
      });
      if (!st.loading && v.rows.length === 0) list.appendChild(h("p", { class: "text-sm text-white/60", text: "No signals in this block." }));
      else if (v.rows.length && !rows.length) list.appendChild(h("p", { class: "text-sm text-white/60", text: "No signals match these filters." }));
      rows.forEach(function (r) { list.appendChild(card(r)); });
    }

    function render() { renderHeader(); renderMsgs(); renderList(); }
    load();
  }

  // ================================================================ Verified page
  function verifiedPage() {
    var st = { rows: null, warnings: [], loading: true, error: null, busy: null, filter: "waiting", search: "", sticky: {} };
    var header = h("section", { class: "space-y-3 rounded-2xl border border-line bg-card/80 p-3" });
    var msgs = h("div", { class: "space-y-2" });
    var list = h("div", { class: "space-y-4" });
    clear(app);
    app.appendChild(h("div", { class: "space-y-4" }, header, msgs, list));

    function load() {
      st.loading = true; st.error = null; render();
      return api("/api/verified", { onBusy: function (n) { st.busy = busyText(n); renderMsgs(); } })
        .then(function (v) { st.rows = v.rows; st.warnings = v.warnings || []; }, function (e) { st.error = e.message; })
        .then(function () { st.loading = false; render(); });
    }
    function counts() {
      var c = { waiting: 0, ready: 0, sent: 0, all: 0 };
      (st.rows || []).forEach(function (r) { c[r.status]++; c.all++; });
      return c;
    }
    function setFilter(f) { st.filter = f; st.sticky = {}; render(); }

    function renderHeader() {
      clear(header);
      var c = counts();
      var tab = function (f, label, n) {
        return h("button", { type: "button", "aria-pressed": st.filter === f ? "true" : "false", onclick: function () { setFilter(f); },
          class: "rounded-full border px-3 py-1.5 text-sm " + (st.filter === f ? "border-pink bg-pink/20 text-white" : "border-line text-white/70 hover:text-white") },
          label + " ", h("span", { class: "text-white/60", text: String(n) }));
      };
      header.appendChild(h("div", null, h("h1", { class: "text-lg font-semibold", text: "Signals to verify" }),
        h("p", { class: "text-sm text-white/70" }, st.rows ? [h("strong", { class: "text-pink-soft", text: String(c.waiting) }), " waiting · ",
          h("strong", { class: "text-white", text: String(c.ready) }), " ready to review · ", h("strong", { class: "text-ok", text: String(c.sent) }), " sent"] : ["Loading…"])));
      header.appendChild(h("div", { class: "flex flex-wrap gap-2" },
        tab("waiting", "Waiting for decision maker", c.waiting), tab("ready", "Ready to review", c.ready), tab("sent", "Sent", c.sent), tab("all", "All", c.all)));
      header.appendChild(h("input", { class: fieldClass, type: "search", placeholder: "Search company, title, location…", value: st.search,
        oninput: function (e) { st.search = e.target.value; renderList(); } }));
    }
    function renderMsgs() {
      clear(msgs);
      if (st.busy) msgs.appendChild(notice("busy", st.busy));
      st.warnings.forEach(function (w) { msgs.appendChild(notice("warn", w)); });
      if (st.error) msgs.appendChild(h("div", null, notice("error", st.error), h("button", { type: "button", class: "mt-1 text-sm underline", onclick: load, text: "Retry" })));
      if (st.loading && !st.rows) msgs.appendChild(h("p", { class: "flex items-center gap-2 text-sm text-white/70" }, h("span", { class: "sd-spinner" }), " Reading the master sheet…"));
    }
    function updateRow(key, patch) {
      st.rows = st.rows.map(function (r) { return r.key === key ? Object.assign({}, r, patch) : r; });
    }

    function card(row) {
      var href = safeHref(row.jobUrl);
      var editable = !!row.key && !row.duplicate;
      var links = row.decisionMakers.length ? row.decisionMakers.slice() : [""];
      var errs = [];
      var msg = h("div", { class: "space-y-2" });
      var inputsBox = h("div", { class: "space-y-2" });
      var saveBtn, approveBox = h("div", { class: "mt-3" });

      function setMsg(kind, text) { clear(msg); if (text) msg.appendChild(notice(kind, text)); }
      function dirty() {
        var cur = links.map(function (s) { return s.trim(); }).filter(Boolean);
        return JSON.stringify(cur) !== JSON.stringify(row.decisionMakers);
      }
      function drawInputs() {
        clear(inputsBox);
        links.forEach(function (l, i) {
          var input = h("input", { class: fieldClass + (errs[i] ? " border-bad" : ""), type: "url", inputmode: "url", autocapitalize: "off", spellcheck: "false",
            placeholder: "https://www.linkedin.com/in/…", value: l, "aria-label": "Decision maker LinkedIn link " + (i + 1),
            oninput: function (e) { links[i] = e.target.value; errs[i] = null; saveBtn.disabled = !dirty(); } });
          inputsBox.appendChild(h("div", null, h("div", { class: "flex gap-2" }, input,
            links.length > 1 ? h("button", { type: "button", class: "shrink-0 rounded-xl border border-line px-3 text-white/60 hover:text-white", "aria-label": "Remove link " + (i + 1),
              onclick: function () { links.splice(i, 1); errs.splice(i, 1); drawInputs(); saveBtn.disabled = !dirty(); }, text: "×" }) : null),
            errs[i] ? h("p", { class: "mt-1 text-xs text-bad", text: errs[i] }) : null));
        });
      }
      function save() {
        var v = validateLinks(links);
        if (v.errors.some(Boolean)) { errs = v.errors; drawInputs(); return; }
        if (v.tooMany) return setMsg("error", "At most 10 links per row.");
        if (!v.links.length && row.decisionMakers.length && !window.confirm("Clear the Decision Maker LinkedIn cell (column M) for " + row.company + "?")) return;
        saveBtn.disabled = true; saveBtn.textContent = "Saving…"; setMsg(null, null);
        api("/api/linkedin", { method: "POST", body: { jobUrl: row.jobUrl, links: v.links }, onBusy: function (n) { setMsg("busy", busyText(n)); } })
          .then(function (res) {
            st.sticky[row.key] = true;
            var status = res.links.length ? (row.status === "sent" && JSON.stringify(res.links) === JSON.stringify(row.decisionMakers) ? "sent" : "ready") : "waiting";
            updateRow(row.key, { decisionMakers: res.links, status: status, canUndo: false, savedRow: res.row });
            renderList();
          }, function (e) { saveBtn.disabled = false; saveBtn.textContent = "Save"; setMsg("error", e.message); });
      }
      function approve() {
        if (!window.confirm("Approve " + row.company + " and add it to the \"Decision Makers\" tab?")) return;
        clear(approveBox); approveBox.appendChild(h("p", { class: "flex items-center gap-2 text-sm text-white/70" }, h("span", { class: "sd-spinner" }), " Adding to Decision Makers…"));
        api("/api/approve", { method: "POST", body: { jobUrl: row.jobUrl }, onBusy: function (n) { setMsg("busy", busyText(n)); } })
          .then(function (res) {
            st.sticky[row.key] = true;
            st.rows = st.rows.map(function (r) { return Object.assign({}, r, { canUndo: false }); });
            updateRow(row.key, { status: "sent", sentDate: res.sentDate, canUndo: res.status === "appended", approvedRow: res.row, approvedNote: res.status === "already" ? "already" : "new" });
            renderList();
          }, function (e) { st.sticky[row.key] = true; updateRow(row.key, { approveError: e.message }); renderList(); });
      }
      function undo() {
        if (!window.confirm("Undo: remove the row just added to \"Decision Makers\" for " + row.company + "?")) return;
        api("/api/undo-approve", { method: "POST", body: { jobUrl: row.jobUrl }, onBusy: function (n) { setMsg("busy", busyText(n)); } })
          .then(function () { st.sticky[row.key] = true; updateRow(row.key, { status: "ready", sentDate: null, canUndo: false, approvedNote: "undone" }); renderList(); },
            function (e) { st.sticky[row.key] = true; updateRow(row.key, { approveError: e.message, canUndo: false }); renderList(); });
      }

      // Decision-maker information (Haseeb fills M/S/T/U in the sheet; JP can edit M here).
      var dmInfo = [["Name (T)", row.dmName], ["Title (U)", row.dmTitle], ["Note (S)", row.dmNote]].filter(function (x) { return x[1]; });
      var dmBox = h("div", { class: "mt-3 rounded-xl border border-line/60 bg-black/20 p-3" },
        h("span", { class: "block text-xs uppercase tracking-wide text-white/50", text: "Possible decision maker" }),
        row.decisionMakers.length ? h("ul", { class: "mt-1 space-y-1 text-sm" }, row.decisionMakers.map(function (l) {
          var hr = safeHref(l);
          return h("li", { class: "break-all" }, hr ? h("a", { href: hr, target: "_blank", rel: "noopener noreferrer", class: "text-pink-soft hover:underline", text: l }) : l);
        })) : h("p", { class: "mt-1 text-sm text-white/50", text: "No LinkedIn yet (column M)." }),
        dmInfo.length ? h("dl", { class: "mt-2 space-y-1 text-sm" }, dmInfo.map(function (x) {
          return h("div", null, h("dt", { class: "inline text-white/50", text: x[0] + ": " }), h("dd", { class: "inline whitespace-pre-line text-white/85", text: x[1] }));
        })) : null);

      saveBtn = h("button", { type: "button", class: "rounded-xl bg-pink px-4 py-2 text-sm font-semibold text-white hover:brightness-110 disabled:opacity-50", disabled: true, onclick: save, text: "Save" });
      drawInputs();
      var editor = editable ? h("div", { class: "mt-3 space-y-2" },
        h("span", { class: "block text-xs uppercase tracking-wide text-white/50", text: "Edit LinkedIn (column M)" }), inputsBox,
        h("div", { class: "flex flex-wrap items-center gap-2" },
          h("button", { type: "button", class: "rounded-xl border border-line px-3 py-2 text-sm text-white/80 hover:border-pink disabled:opacity-40",
            onclick: function () { if (links.length < 10) { links.push(""); drawInputs(); } }, text: "+ Add another" }),
          saveBtn,
          row.savedRow ? h("span", { class: "text-sm font-medium text-ok", role: "status" }, "✓ Saved to master sheet ", h("span", { class: "text-white/40", text: "(row " + row.savedRow + ")" })) : null),
        msg) : h("p", { class: "mt-3 text-sm text-warn", text: row.duplicate ? "This Job URL appears more than once in the verify tab, so it can't be edited safely here. Tidy it up in Excel." : "This row has no Job URL, so it can't be edited safely here." });

      // Final tick
      if (row.status === "sent") {
        approveBox.appendChild(h("div", { class: "flex flex-wrap items-center gap-3" },
          h("span", { class: "sd-sent", text: "Sent ✓ " + (row.sentDate || "") }),
          row.approvedNote === "already" ? h("span", { class: "text-xs text-white/60", text: "(already in Decision Makers, nothing added)" }) : null,
          row.canUndo ? h("button", { type: "button", class: "sd-btn-secondary", onclick: undo, text: "Undo" }) : null));
      } else if (row.status === "ready" && editable) {
        approveBox.appendChild(h("button", { type: "button", class: "sd-btn-approve", onclick: approve, text: "✓ Approve → Decision Makers" }));
      }
      if (row.approvedNote === "undone") approveBox.appendChild(h("p", { class: "mt-2 text-sm text-ok", text: "Undone: the row was removed from Decision Makers." }));
      if (row.approveError) approveBox.appendChild(h("div", { class: "mt-2" }, notice("error", row.approveError)));

      var badge = row.status === "sent" ? chip("✓ Sent", "ok") : row.status === "ready" ? chip("Ready to review", "pink") : chip("Waiting for decision maker");
      return h("li", { class: "rounded-2xl border p-3 " + (row.status === "sent" ? "border-ok/40 bg-card" : row.status === "ready" ? "border-pink/50 bg-card-2" : "border-line bg-card") },
        h("div", { class: "flex items-start justify-between gap-2" },
          h("div", { class: "min-w-0" }, h("h3", { class: "font-semibold leading-snug", text: row.company || "(no company)" }), h("p", { class: "text-sm text-white/85", text: row.jobTitle })),
          badge),
        h("div", { class: "mt-1.5 flex flex-wrap items-center gap-1.5 text-sm" },
          row.roleGroup ? chip(row.roleGroup, "pink") : null, row.roleSubGroup ? chip(row.roleSubGroup, "pink") : null,
          row.companyType ? chip(row.companyType) : null, row.location ? chip(row.location) : null,
          href ? h("a", { href: href, target: "_blank", rel: "noopener noreferrer", class: "ml-1 font-medium text-pink-soft hover:underline", text: "Open job ↗" }) : null,
          h("span", { class: "text-xs text-white/40", text: "row " + row.rowNumber })),
        details([["Notes", row.notes], ["Lemlist / Prior Outreach", row.priorOutreach], ["Verified Evidence", row.verifiedEvidence], ["My comments", row.myComments]]),
        dmBox, editor, approveBox);
    }

    function renderList() {
      clear(list);
      if (!st.rows) return;
      var q = st.search.trim().toLowerCase();
      var shown = st.rows.filter(function (r) {
        if (!st.sticky[r.key] && st.filter !== "all" && r.status !== st.filter) return false;
        if (q && [r.company, r.jobTitle, r.location, r.roleGroup, r.roleSubGroup, r.dmName].join(" ").toLowerCase().indexOf(q) < 0) return false;
        return true;
      });
      if (!shown.length) { list.appendChild(h("p", { class: "text-sm text-white/60", text: "Nothing to show for this filter." })); return; }
      var groups = {}, order = [];
      shown.forEach(function (r) { var k = r.dateFound || "No date"; if (!groups[k]) { groups[k] = []; order.push(k); } groups[k].push(r); });
      order.forEach(function (d) {
        list.appendChild(h("section", { class: "space-y-2" },
          h("h2", { class: "sd-date-heading", text: d + " · " + groups[d].length }),
          h("ul", { class: "space-y-3" }, groups[d].map(card))));
      });
    }
    function render() { renderHeader(); renderMsgs(); renderList(); }
    load();
  }

  if (page === "signals") signalsPage();
  else if (page === "verified") verifiedPage();
})();
