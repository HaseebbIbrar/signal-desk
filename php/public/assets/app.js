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
        else if (k === "width") el.style.width = v; // CSSOM, allowed by the CSP (style-src 'self' blocks style="")
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

  // ---------------------------------------------------------------- Atlas badges ("N matching titles in <pool> (M in pool)")
  // Counts load lazily, in batches of 10, one request at a time, and are remembered for this page.
  var atlasOn = body.getAttribute("data-atlas") === "on";
  var jdOn = body.getAttribute("data-jd") === "on";
  function fmt(n) { return Number(n || 0).toLocaleString("en-GB"); }
  var atlasCache = {}, atlasQueue = [], atlasTimer = null, atlasBusy = false;
  function atlasKey(t, s, g) { return [t || "", s || "", g || ""].join("\u0001"); }
  function candidatesHref(title, sub, group, jobUrl) {
    var q = [];
    if (title) q.push("title=" + encodeURIComponent(title));
    if (sub) q.push("sub=" + encodeURIComponent(sub));
    if (group) q.push("group=" + encodeURIComponent(group));
    if (jobUrl) q.push("jobUrl=" + encodeURIComponent(jobUrl));
    return "/sourcing/candidates" + (q.length ? "?" + q.join("&") : "") + (jobUrl ? "#jd" : "");
  }
  function matchLink(title, sub, group, jobUrl) {
    if (!jdOn || !(title || sub)) return null;
    return h("a", { class: "sd-match-link", href: candidatesHref(title, sub, group, jobUrl || ""), text: "Match candidates", title: "Match Atlas candidates to this job description" });
  }
  function poolText(r) {
    var pools = (r.pools || []).join(" + ");
    if (r.n === null || r.n === undefined) return fmt(r.m) + " in " + pools + (r.index === "building" ? " · title index building…" : " · title index not built yet");
    return fmt(r.n) + " matching title" + (r.n === 1 ? "" : "s") + " in " + pools + " (" + fmt(r.m) + " in pool)";
  }
  function fillAtlas(el, r) {
    el.classList.remove("sd-atlas-none");
    if (r.none) { el.textContent = "No matching list"; el.classList.add("sd-atlas-none"); el.title = "No Atlas candidate list matches this role (see storage/atlas-list-map.json)"; }
    else if (r.error) { el.textContent = r.error === "rate" ? "Atlas: busy, try later" : "Atlas: unavailable"; }
    else { el.textContent = "Atlas: " + poolText(r); el.title = "Matched via " + r.reason + ". Click to see the candidates."; }
  }
  function atlasBadge(title, sub, group) {
    if (!atlasOn || !(title || sub)) return null;
    var a = h("a", { class: "sd-atlas-badge", href: candidatesHref(title, sub, group), text: "Atlas: …" });
    var key = atlasKey(title, sub, group);
    if (atlasCache[key]) fillAtlas(a, atlasCache[key]);
    else { atlasQueue.push({ key: key, el: a, item: { title: title || "", sub: sub || "", group: group || "" } }); if (!atlasTimer) atlasTimer = setTimeout(runAtlas, 60); }
    return a;
  }
  function runAtlas() {
    atlasTimer = null;
    if (atlasBusy) return;
    atlasQueue = atlasQueue.filter(function (q) {
      if (atlasCache[q.key]) { fillAtlas(q.el, atlasCache[q.key]); return false; }
      return document.body.contains(q.el); // cards re-rendered since: drop their stale badges
    });
    var seen = {}, batch = [];
    atlasQueue.forEach(function (q) { if (!seen[q.key] && batch.length < 10) { seen[q.key] = 1; batch.push(q); } });
    if (!batch.length) return;
    atlasBusy = true;
    var done = function (key, r) {
      atlasQueue = atlasQueue.filter(function (q) { if (q.key !== key) return true; fillAtlas(q.el, r); return false; });
    };
    api("/api/atlas/counts", { method: "POST", body: { items: batch.map(function (q) { return q.item; }) } })
      .then(function (res) {
        batch.forEach(function (q, i) { var r = (res.items || [])[i] || { error: "x" }; if (!r.error) atlasCache[q.key] = r; done(q.key, r); });
      }, function () { batch.forEach(function (q) { done(q.key, { error: "x" }); }); })
      .then(function () { atlasBusy = false; if (atlasQueue.length) setTimeout(runAtlas, 250); });
  }

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
          st.notice = { kind: "warn", text: row.company + ": Has decision makers, edit in Excel" + (row.verifyRows > 1 ? " (" + row.verifyRows + " rows in the verify tab)." : " (verify tab row " + row.verifyRow + ").") };
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

    // "Rebuild index": starts a build (at most hourly) and keeps it going in ~20 s steps while this page is open.
    function rebuild(action) {
      st.indexing = true; st.notice = null; render();
      api("/api/atlas/index", { method: "POST", body: { action: action } })
        .then(function (r) {
          if (st.view) st.view.index = r;
          if (r.result === "done") { st.notice = { kind: "ok", text: "Title index built: " + fmt(r.entries) + " people." }; st.indexing = false; render(); return; }
          if (r.state === "running") { render(); setTimeout(function () { rebuild("continue"); }, r.result === "paused" ? 20000 : 300); return; }
          st.indexing = false; render();
        }, function (e) { st.notice = { kind: e.code === "REBUILD_TOO_SOON" ? "warn" : "error", text: e.message }; st.indexing = false; render(); });
    }
    function indexLine(ix) {
      if (!ix) return null;
      var text = ix.state === "running"
        ? "Title index: building… " + (ix.phase === "members" ? "reading pool members (" + ix.poolsScanned + " of " + ix.pools + " pools)" : fmt(ix.done) + " of " + fmt(ix.total) + " people")
        : st.indexing ? "Title index: starting… (reading the pools takes a moment)"
        : ix.builtAt ? "Title index: " + fmt(ix.entries) + " people · built " + when(ix.builtAt) : "Title index: not built yet";
      return h("div", { class: "flex flex-wrap items-center gap-2 text-sm text-white/70" },
        st.indexing ? h("span", { class: "sd-spinner" }) : null, h("span", { text: text }),
        h("button", { type: "button", class: "sd-btn-secondary", disabled: st.indexing, onclick: function () { rebuild(ix.state === "running" ? "continue" : "start"); },
          text: ix.state === "running" ? (st.indexing ? "Building…" : "Continue building") : "Rebuild index" }));
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
        row.ticked ? h("span", { class: "text-xs text-white/60", text: "In verify tab" + (row.verifyRows > 1 ? " · " + row.verifyRows + " decision makers" : row.verifyRow ? " · row " + row.verifyRow : "") + (row.verifyRows <= 1 && row.hasDecisionMaker ? " · decision maker filled" : "") }) : null,
        row.ticked && row.untickLocked ? h("span", { class: "text-xs text-warn", text: "Has decision makers, edit in Excel" }) : null,
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
              row.companyType ? chip(row.companyType) : null, row.location ? chip(row.location) : null,
              atlasBadge(row.jobTitle, row.roleSubGroup, row.roleGroup), matchLink(row.jobTitle, row.roleSubGroup, row.roleGroup, row.jobUrl)),
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
    load().then(function () { if (st.view && st.view.index && st.view.index.state === "running" && !st.indexing) rebuild("continue"); });
  }

  // ================================================================ Verified page
  // One card per verify-tab row (a job can have several decision makers), grouped under the job.
  // Every write sends the card's row number + Job URL; the server refuses if that row moved.
  // Two tracks with the same workflow: verify tab -> Decision Makers, and Hr signals -> HR Decision Makers.
  var VERIFIED_CFG = { view: "/api/verified", api: "/api/", title: "Signals to verify", rowWord: "verify", tabName: "verify tab", dm: "Decision Makers" };
  var HR_CFG = { view: "/api/hr", api: "/api/hr/", title: "HR signals", rowWord: "HR", tabName: "HR signals tab", dm: "HR Decision Makers" };
  function verifiedPage(cfg) {
    cfg = cfg || VERIFIED_CFG;
    var st = { jobs: null, warnings: [], loading: true, error: null, busy: null, notice: null, filter: "waiting", search: "", sticky: {} };
    var header = h("section", { class: "space-y-3 rounded-2xl border border-line bg-card/80 p-3" });
    var msgs = h("div", { class: "space-y-2" });
    var list = h("div", { class: "space-y-4" });
    clear(app);
    app.appendChild(h("div", { class: "space-y-4" }, header, msgs, list));

    function cardId(c) { return c.row + "|" + c.jobUrl; }
    function load() {
      st.loading = true; st.error = null; render();
      return api(cfg.view, { onBusy: function (n) { st.busy = busyText(n); renderMsgs(); } })
        .then(function (v) { st.jobs = v.jobs; st.warnings = v.warnings || []; }, function (e) { st.error = e.message; })
        .then(function () { st.loading = false; render(); });
    }
    function counts() {
      var c = { waiting: 0, ready: 0, sent: 0, all: 0 };
      (st.jobs || []).forEach(function (j) { j.cards.forEach(function (k) { c[k.status]++; c.all++; }); });
      return c;
    }
    function setFilter(f) { st.filter = f; st.sticky = {}; render(); }
    function updateCard(row, patch) {
      st.jobs.forEach(function (j) {
        j.cards = j.cards.map(function (c) { return c.row === row ? Object.assign({}, c, patch) : c; });
        j.sent = j.cards.filter(function (c) { return c.status === "sent"; }).length;
      });
    }
    function changedError(e, box) {
      clear(box);
      box.appendChild(notice("error", e.message));
      if (e.code === "ROW_CHANGED") box.appendChild(h("button", { type: "button", class: "sd-btn-secondary mt-1", onclick: load, text: "Refresh" }));
    }

    function renderHeader() {
      clear(header);
      var c = counts();
      var tab = function (f, label, n) {
        return h("button", { type: "button", "aria-pressed": st.filter === f ? "true" : "false", onclick: function () { setFilter(f); },
          class: "rounded-full border px-3 py-1.5 text-sm " + (st.filter === f ? "border-pink bg-pink/20 text-white" : "border-line text-white/70 hover:text-white") },
          label + " ", h("span", { class: "text-white/60", text: String(n) }));
      };
      header.appendChild(h("div", null, h("h1", { class: "text-lg font-semibold", text: cfg.title }),
        h("p", { class: "text-sm text-white/70" }, st.jobs ? [h("strong", { class: "text-pink-soft", text: String(c.waiting) }), " waiting · ",
          h("strong", { class: "text-white", text: String(c.ready) }), " ready to review · ", h("strong", { class: "text-ok", text: String(c.sent) }), " sent (decision makers)"] : ["Loading…"])));
      header.appendChild(h("div", { class: "flex flex-wrap gap-2" },
        tab("waiting", "Waiting for decision maker", c.waiting), tab("ready", "Ready to review", c.ready), tab("sent", "Sent", c.sent), tab("all", "All", c.all)));
      header.appendChild(h("input", { class: fieldClass, type: "search", placeholder: "Search company, title, person…", value: st.search,
        oninput: function (e) { st.search = e.target.value; renderList(); } }));
    }
    function renderMsgs() {
      clear(msgs);
      if (st.busy) msgs.appendChild(notice("busy", st.busy));
      if (st.notice) msgs.appendChild(notice(st.notice.kind, st.notice.text, function () { st.notice = null; renderMsgs(); }));
      st.warnings.forEach(function (w) { msgs.appendChild(notice("warn", w)); });
      if (st.error) msgs.appendChild(h("div", null, notice("error", st.error), h("button", { type: "button", class: "mt-1 text-sm underline", onclick: load, text: "Retry" })));
      if (st.loading && !st.jobs) msgs.appendChild(h("p", { class: "flex items-center gap-2 text-sm text-white/70" }, h("span", { class: "sd-spinner" }), " Reading the master sheet…"));
    }

    // ---------------------------------------------------------- one decision maker (one verify row)
    function dmCard(job, card, index) {
      var editable = !!job.key;
      var links = card.decisionMakers.length ? card.decisionMakers.slice() : [""];
      var errs = [];
      var msg = h("div", { class: "space-y-2" });
      var inputsBox = h("div", { class: "space-y-2" });
      var approveBox = h("div", { class: "mt-3" });
      var saveBtn;
      var body = { row: card.row, jobUrl: card.jobUrl };

      function dirty() { return JSON.stringify(links.map(function (s) { return s.trim(); }).filter(Boolean)) !== JSON.stringify(card.decisionMakers); }
      function drawInputs() {
        clear(inputsBox);
        links.forEach(function (l, i) {
          var input = h("input", { class: fieldClass + (errs[i] ? " border-bad" : ""), type: "url", inputmode: "url", autocapitalize: "off", spellcheck: "false",
            placeholder: "https://www.linkedin.com/in/…", value: l, "aria-label": "LinkedIn link " + (i + 1) + " (row " + card.row + ")",
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
        if (v.tooMany) { clear(msg); msg.appendChild(notice("error", "At most 10 links per row.")); return; }
        if (!v.links.length && card.decisionMakers.length && !window.confirm("Clear the LinkedIn cell (column M) on " + cfg.rowWord + " row " + card.row + "?")) return;
        saveBtn.disabled = true; saveBtn.textContent = "Saving…"; clear(msg);
        api(cfg.api + "linkedin", { method: "POST", body: Object.assign({ links: v.links }, body), onBusy: function (n) { clear(msg); if (n) msg.appendChild(notice("busy", busyText(n))); } })
          .then(function (res) {
            st.sticky[cardId(card)] = true;
            updateCard(card.row, { decisionMakers: res.links, status: res.links.length ? "ready" : "waiting", canUndo: false, savedRow: res.row, sentDate: null });
            renderList();
          }, function (e) { saveBtn.disabled = false; saveBtn.textContent = "Save"; changedError(e, msg); });
      }
      function approve() {
        if (!window.confirm("Approve " + (card.dmName || "this decision maker") + " for " + job.company + " and add them to the \"" + cfg.dm + "\" tab?")) return;
        clear(approveBox); approveBox.appendChild(h("p", { class: "flex items-center gap-2 text-sm text-white/70" }, h("span", { class: "sd-spinner" }), " Adding to " + cfg.dm + "…"));
        api(cfg.api + "approve", { method: "POST", body: body, onBusy: function (n) { clear(msg); if (n) msg.appendChild(notice("busy", busyText(n))); } })
          .then(function (res) {
            st.sticky[cardId(card)] = true;
            st.jobs.forEach(function (j) { j.cards.forEach(function (c) { c.canUndo = false; }); });
            updateCard(card.row, { status: "sent", sentDate: res.sentDate, canUndo: res.status === "appended", approvedNote: res.status === "already" ? "already" : "new", approveError: null });
            renderList();
          }, function (e) { st.sticky[cardId(card)] = true; updateCard(card.row, { approveError: e.message, approveCode: e.code }); renderList(); });
      }
      function undo() {
        if (!window.confirm("Undo: remove the row just added to \"" + cfg.dm + "\" for " + (card.dmName || job.company) + "?")) return;
        api(cfg.api + "undo-approve", { method: "POST", body: body, onBusy: function (n) { clear(msg); if (n) msg.appendChild(notice("busy", busyText(n))); } })
          .then(function () { st.sticky[cardId(card)] = true; updateCard(card.row, { status: "ready", sentDate: null, canUndo: false, approvedNote: "undone", approveError: null }); renderList(); },
            function (e) { st.sticky[cardId(card)] = true; updateCard(card.row, { approveError: e.message, approveCode: e.code, canUndo: false }); renderList(); });
      }

      var dmInfo = [["Name (T)", card.dmName], ["Title (U)", card.dmTitle], ["Note (S)", card.dmNote]].filter(function (x) { return x[1]; });
      var dmBox = h("div", { class: "mt-2 rounded-xl border border-line/60 bg-black/20 p-3" },
        card.decisionMakers.length ? h("ul", { class: "space-y-1 text-sm" }, card.decisionMakers.map(function (l) {
          var hr = safeHref(l);
          return h("li", { class: "break-all" }, hr ? h("a", { href: hr, target: "_blank", rel: "noopener noreferrer", class: "text-pink-soft hover:underline", text: l }) : l);
        })) : h("p", { class: "text-sm text-white/50", text: "No LinkedIn yet (column M)." }),
        dmInfo.length ? h("dl", { class: "mt-2 space-y-1 text-sm" }, dmInfo.map(function (x) {
          return h("div", null, h("dt", { class: "inline text-white/50", text: x[0] + ": " }), h("dd", { class: "inline whitespace-pre-line text-white/85", text: x[1] }));
        })) : null);

      saveBtn = h("button", { type: "button", class: "rounded-xl bg-pink px-4 py-2 text-sm font-semibold text-white hover:brightness-110 disabled:opacity-50", disabled: true, onclick: save, text: "Save" });
      drawInputs();
      var editor = editable ? h("details", { class: "group mt-2" },
        h("summary", { class: "text-sm text-white/60 hover:text-white" },
          h("span", { class: "group-open:hidden", text: "▸ Edit LinkedIn (column M)" }), h("span", { class: "hidden group-open:inline", text: "▾ Edit LinkedIn (column M)" })),
        h("div", { class: "mt-2 space-y-2" }, inputsBox,
          h("div", { class: "flex flex-wrap items-center gap-2" },
            h("button", { type: "button", class: "rounded-xl border border-line px-3 py-2 text-sm text-white/80 hover:border-pink disabled:opacity-40",
              onclick: function () { if (links.length < 10) { links.push(""); drawInputs(); } }, text: "+ Add link" }),
            saveBtn))) : h("p", { class: "mt-2 text-sm text-warn", text: "This row has no Job URL, so it can't be edited safely here." });
      if (card.decisionMakers.length === 0 && editable) editor.setAttribute("open", "");

      if (card.status === "sent") {
        approveBox.appendChild(h("div", { class: "flex flex-wrap items-center gap-3" },
          h("span", { class: "sd-sent", text: "Sent ✓ " + (card.sentDate || "") }),
          card.approvedNote === "already" ? h("span", { class: "text-xs text-white/60", text: "(already in " + cfg.dm + ", nothing added)" }) : null,
          card.canUndo ? h("button", { type: "button", class: "sd-btn-secondary", onclick: undo, text: "Undo" }) : null));
      } else if (card.status === "ready" && editable) {
        approveBox.appendChild(h("button", { type: "button", class: "sd-btn-approve", onclick: approve, text: "✓ Approve → " + cfg.dm }));
      }
      if (card.approvedNote === "undone") approveBox.appendChild(h("p", { class: "mt-2 text-sm text-ok", text: "Undone: the row was removed from " + cfg.dm + "." }));
      if (card.approveError) changedError({ message: card.approveError, code: card.approveCode }, approveBox.appendChild(h("div", { class: "mt-2" })));

      var badge = card.status === "sent" ? chip("✓ Sent", "ok") : card.status === "ready" ? chip("Ready to review", "pink") : chip("Waiting for decision maker");
      return h("li", { class: "sd-dm-card " + (card.status === "sent" ? "border-ok/40" : card.status === "ready" ? "border-pink/50" : "border-line") },
        h("div", { class: "flex items-start justify-between gap-2" },
          h("div", { class: "min-w-0" },
            h("h4", { class: "font-semibold leading-snug", text: card.dmName || ("Decision maker " + (index + 1)) }),
            h("p", { class: "text-xs text-white/50", text: (card.dmTitle ? card.dmTitle + " · " : "") + cfg.rowWord + " row " + card.row })),
          badge),
        dmBox, editor,
        card.savedRow ? h("p", { class: "mt-2 text-sm font-medium text-ok", role: "status" }, "✓ Saved to master sheet ", h("span", { class: "text-white/40", text: "(row " + card.savedRow + ")" })) : null,
        msg, approveBox);
    }

    // ---------------------------------------------------------- one job (one or more decision makers)
    function jobGroup(job, cards) {
      var href = safeHref(job.jobUrl);
      var addMsg = h("div", { class: "space-y-2" });
      var addInput = h("input", { class: fieldClass, type: "url", inputmode: "url", autocapitalize: "off", spellcheck: "false",
        placeholder: "https://www.linkedin.com/in/…", "aria-label": "New decision maker LinkedIn for " + job.company });
      var addBtn = h("button", { type: "button", class: "sd-btn-secondary shrink-0", text: "Add" });
      addBtn.addEventListener("click", function () {
        var r = normaliseLinkedIn(addInput.value);
        clear(addMsg);
        if (!r.ok) { addMsg.appendChild(notice("error", r.error)); return; }
        addBtn.disabled = true; addBtn.textContent = "Adding…";
        api(cfg.api + "add-decision-maker", { method: "POST", body: { row: job.firstRow, jobUrl: job.jobUrl, link: r.url }, onBusy: function (n) { clear(addMsg); if (n) addMsg.appendChild(notice("busy", busyText(n))); } })
          .then(function (res) {
            st.sticky[res.row + "|" + job.jobUrl] = true;
            st.notice = { kind: "ok", text: "Added a decision maker for " + job.company + " (" + cfg.rowWord + " row " + res.row + ")." };
            return load();
          }, function (e) { addBtn.disabled = false; addBtn.textContent = "Add"; changedError(e, addMsg); });
      });
      var addForm = job.key ? h("details", { class: "group mt-3" },
        h("summary", { class: "text-sm font-medium text-pink-soft hover:text-white", text: "+ Add another decision maker" }),
        h("div", { class: "mt-2 space-y-2" }, h("div", { class: "flex gap-2" }, addInput, addBtn),
          h("p", { class: "text-xs text-white/50", text: "Adds a new row to the " + cfg.tabName + " for this job, with this LinkedIn in column M. Name, title and note (S/T/U) are filled in Excel." }),
          addMsg)) : null;

      var total = job.total;
      return h("li", { class: "rounded-2xl border border-line bg-card p-3" },
        h("div", { class: "flex items-start justify-between gap-2" },
          h("div", { class: "min-w-0" },
            h("h3", { class: "font-semibold leading-snug", text: job.company || "(no company)" }),
            h("p", { class: "text-sm text-white/85", text: job.jobTitle + " · " + total + " decision maker" + (total === 1 ? "" : "s") })),
          chip(job.sent + " of " + total + " sent", job.sent === total ? "ok" : job.sent ? "pink" : null)),
        h("div", { class: "mt-1.5 flex flex-wrap items-center gap-1.5 text-sm" },
          job.roleGroup ? chip(job.roleGroup, "pink") : null, job.roleSubGroup ? chip(job.roleSubGroup, "pink") : null,
          job.companyType ? chip(job.companyType) : null, job.location ? chip(job.location) : null,
          atlasBadge(job.jobTitle, job.roleSubGroup, job.roleGroup), matchLink(job.jobTitle, job.roleSubGroup, job.roleGroup, job.jobUrl),
          href ? h("a", { href: href, target: "_blank", rel: "noopener noreferrer", class: "ml-1 font-medium text-pink-soft hover:underline", text: "Open job ↗" }) : null),
        details([["Notes", job.notes], ["Lemlist / Prior Outreach", job.priorOutreach], ["Verified Evidence", job.verifiedEvidence], ["My comments", job.myComments]]),
        h("ul", { class: "mt-3 space-y-2" }, cards.map(function (c) { return dmCard(job, c, job.cards.indexOf(c)); })),
        addForm);
    }

    function renderList() {
      clear(list);
      if (!st.jobs) return;
      var q = st.search.trim().toLowerCase();
      var shownJobs = [];
      st.jobs.forEach(function (j) {
        var jobText = [j.company, j.jobTitle, j.location, j.roleGroup, j.roleSubGroup].join(" ").toLowerCase();
        var cards = j.cards.filter(function (c) {
          if (!st.sticky[cardId(c)] && st.filter !== "all" && c.status !== st.filter) return false;
          if (q && (jobText + " " + [c.dmName, c.dmTitle].join(" ").toLowerCase()).indexOf(q) < 0) return false;
          return true;
        });
        if (cards.length) shownJobs.push([j, cards]);
      });
      if (!shownJobs.length) { list.appendChild(h("p", { class: "text-sm text-white/60", text: "Nothing to show for this filter." })); return; }
      var groups = {}, order = [];
      shownJobs.forEach(function (x) { var k = x[0].dateFound || "No date"; if (!groups[k]) { groups[k] = []; order.push(k); } groups[k].push(x); });
      order.forEach(function (d) {
        list.appendChild(h("section", { class: "space-y-2" },
          h("h2", { class: "sd-date-heading", text: d + " · " + groups[d].length + " job" + (groups[d].length === 1 ? "" : "s") }),
          h("ul", { class: "space-y-3" }, groups[d].map(function (x) { return jobGroup(x[0], x[1]); }))));
      });
    }
    function render() { renderHeader(); renderMsgs(); renderList(); }
    load().then(function () { if (st.view && st.view.index && st.view.index.state === "running" && !st.indexing) rebuild("continue"); });
  }

  // ================================================================ Candidates page (Atlas pools + title index, JD matching)
  function candidatesPage() {
    var params = new URLSearchParams(window.location.search);
    var st = { title: params.get("title") || "", sub: params.get("sub") || "", group: params.get("group") || "", jobUrl: params.get("jobUrl") || "",
      all: params.get("all") === "1", page: 1, data: null, loading: false, error: null, busy: null };
    clear(app);
    if (!atlasOn) {
      app.appendChild(notice("warn", "Atlas is not configured. Add ATLAS_API_KEY to config.php (see DEPLOY-20I.md)."));
      return;
    }
    var form = h("form", { class: "space-y-3 rounded-2xl border border-line bg-card/80 p-3" });
    var msgs = h("div", { class: "space-y-2" });
    var results = h("div", { class: "space-y-3" });
    var jdBox = h("section", { id: "jd", class: "space-y-3" });
    app.appendChild(h("div", { class: "space-y-4" }, jdOn ? jdBox : null, form, msgs, results));

    function syncUrl() {
      var q = candidatesHref(st.title, st.sub, st.group) + (st.all ? (st.title || st.sub || st.group ? "&" : "?") + "all=1" : "");
      try { window.history.replaceState(null, "", q + (window.location.hash || "")); } catch (e) { /* ignore */ }
    }
    function load() {
      if (!st.title.trim() && !st.sub) { st.data = null; render(); return; }
      st.loading = true; st.error = null; syncUrl(); render();
      var q = "?title=" + encodeURIComponent(st.title) + "&sub=" + encodeURIComponent(st.sub) + "&group=" + encodeURIComponent(st.group) + "&page=" + st.page + (st.all ? "&all=1" : "");
      api("/api/atlas/search" + q, { onBusy: function (n) { st.busy = busyText(n); renderMsgs(); } })
        .then(function (d) { st.data = d; st.page = d.page || 1; }, function (e) { st.error = e.message; })
        .then(function () { st.loading = false; st.busy = null; render(); });
    }

    function renderForm() {
      clear(form);
      var input = h("input", { class: fieldClass, type: "search", name: "title", placeholder: "Job title, e.g. Security Architect", value: st.title, "aria-label": "Job title" });
      form.onsubmit = function (e) { e.preventDefault(); st.title = input.value; st.page = 1; load(); };
      form.appendChild(h("div", null, h("h1", { class: "text-lg font-semibold", text: "Candidates in Atlas" }),
        h("p", { class: "text-sm text-white/70", text: "Finds the matching Atlas pool and the people in it whose current title matches. Read-only." })));
      form.appendChild(h("div", { class: "flex gap-2" }, input, h("button", { type: "submit", class: "rounded-xl bg-pink px-4 py-2 text-sm font-semibold text-white hover:brightness-110", text: "Search" })));
      if (st.sub || st.group) {
        form.appendChild(h("div", { class: "flex flex-wrap items-center gap-1.5 text-sm" },
          h("span", { class: "text-xs text-white/50", text: "From the signal:" }),
          st.sub ? chip(st.sub, "pink") : null, st.group ? chip(st.group) : null,
          h("button", { type: "button", class: "sd-btn-secondary", text: "Title only", onclick: function () { st.sub = ""; st.group = ""; st.page = 1; renderForm(); load(); } })));
      }
    }
    function renderMsgs() {
      clear(msgs);
      if (st.busy) msgs.appendChild(notice("busy", st.busy));
      if (st.error) msgs.appendChild(notice("error", st.error));
      if (st.loading) msgs.appendChild(h("p", { class: "flex items-center gap-2 text-sm text-white/70" }, h("span", { class: "sd-spinner" }), " Reading Atlas…"));
    }
    function cvButton(m) {
      var btn = h("button", { type: "button", class: "sd-btn-secondary", text: "CV" });
      btn.addEventListener("click", function () {
        var w = window.open("", "_blank");
        if (w) w.opener = null;
        btn.disabled = true; btn.textContent = "CV…";
        api("/api/atlas/cv?personId=" + encodeURIComponent(m.id))
          .then(function (r) {
            var u = safeHref(r.url);
            if (u && w) w.location.href = u; else if (u) window.location.href = u;
            btn.textContent = "CV";
          }, function (e) { if (w) w.close(); btn.textContent = e.message.indexOf("No CV") === 0 ? "No CV" : "CV failed"; })
          .then(function () { btn.disabled = false; });
      });
      return btn;
    }
    function member(m) {
      var href = safeHref(m.profileUrl);
      return h("li", { class: "rounded-2xl border border-line bg-card p-3" },
        h("div", { class: "min-w-0" },
          h("h3", { class: "font-semibold leading-snug", text: m.name }),
          h("p", { class: "text-sm text-white/85", text: [m.role, m.company].filter(Boolean).join(" · ") || "—" })),
        (m.skills && m.skills.length) ? h("div", { class: "mt-1.5 flex flex-wrap gap-1" }, m.skills.slice(0, 8).map(function (s) { return chip(s); })) : null,
        h("div", { class: "mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm" },
          m.location ? h("span", { class: "text-white/70", text: m.location }) : null,
          m.updatedAt ? h("span", { class: "text-xs text-white/50", text: "Last updated " + m.updatedAt }) : null,
          href ? h("a", { href: href, target: "_blank", rel: "noopener noreferrer", class: "font-medium text-pink-soft hover:underline", text: "Open in Atlas ↗" }) : null,
          cvButton(m)));
    }
    function renderResults() {
      clear(results);
      var d = st.data;
      if (!d) { results.appendChild(h("p", { class: "text-sm text-white/60", text: "Type a job title to see matching candidates." })); return; }
      if (!d.families.length) {
        results.appendChild(notice("warn", "No matching pool for \"" + (d.normalisedTitle || st.title) + "\". Add a keyword to a pool on the Lists page."));
        return;
      }
      var pools = d.families.join(" + ");
      var head = h("section", { class: "space-y-2 rounded-2xl border border-line bg-card/80 p-3" });
      if (d.n === null) {
        head.appendChild(h("p", { class: "text-base" }, h("strong", { class: "text-white", text: fmt(d.m) }), " people in " + pools));
        head.appendChild(notice("warn", d.index === "building" ? "Title index building… Showing everyone in the pool for now." : "The title index is not built yet (Lists → Rebuild index). Showing everyone in the pool."));
      } else {
        head.appendChild(h("p", { class: "text-base" }, h("strong", { class: "text-pink-soft", text: fmt(d.n) }), " matching title" + (d.n === 1 ? "" : "s") + " in " + pools + " (",
          h("strong", { class: "text-white", text: fmt(d.m) }), " in pool)"));
        head.appendChild(h("div", { class: "flex flex-wrap items-center gap-2 text-sm" },
          h("span", { class: "text-xs text-white/50", text: "Title words: " + (d.terms || []).join(", ") + " · matched by " + d.reason }),
          h("button", { type: "button", class: "sd-btn-secondary", text: st.all ? "Only matching titles" : "Show whole pool", onclick: function () { st.all = !st.all; st.page = 1; load(); } })));
      }
      results.appendChild(head);
      if (!d.members.length) { results.appendChild(h("p", { class: "text-sm text-white/60", text: "Nobody in the pool has a matching current title. Try “Show whole pool” or JD matching." })); return; }
      results.appendChild(h("ul", { class: "space-y-3" }, d.members.map(member)));
      if (d.totalPages > 1) {
        results.appendChild(h("div", { class: "flex items-center justify-between gap-2 text-sm" },
          h("button", { type: "button", class: "sd-btn-secondary", disabled: d.page <= 1 || st.loading, onclick: function () { st.page = d.page - 1; load(); }, text: "← Previous" }),
          h("span", { class: "text-white/60", text: "Page " + d.page + " of " + d.totalPages }),
          h("button", { type: "button", class: "sd-btn-secondary", disabled: d.page >= d.totalPages || st.loading, onclick: function () { st.page = d.page + 1; load(); }, text: "Next →" })));
      }
    }
    function render() { renderForm(); renderMsgs(); renderResults(); }
    render();
    load();
    if (jdOn) jdMatching(jdBox, st, cvButton);
  }

  // ---------------------------------------------------------------- JD matching: JD -> requirements -> ranked candidates
  // Rule-based by default (no paid AI). "Deep match in Claude" copies a prompt with person IDs only.
  function jdMatching(box, page, cvButton) {
    var aiOn = body.getAttribute("data-ai") === "on";
    var prospeoOn = body.getAttribute("data-prospeo") === "on";
    var jd = { text: "", fetchNote: null, fetching: false, parsing: false, req: null, error: null, status: null,
      run: null, results: [], pageNo: 1, totalPages: 0, progress: null, stopped: false, copied: null };
    var top = h("div", { class: "space-y-3 rounded-2xl border border-pink/50 bg-card/80 p-3" });
    var out = h("div", { class: "space-y-3" });
    var market = h("div", { class: "space-y-3" });
    box.appendChild(top);
    box.appendChild(out);
    box.appendChild(market);

    function loadStatus() {
      api("/api/jd/status").then(function (s) { jd.status = s; renderTop(); }, function () { /* shown on use */ });
    }
    function fetchJob() {
      jd.fetching = true; renderTop();
      api("/api/jd/fetch", { method: "POST", body: { url: page.jobUrl } })
        .then(function (r) {
          if (r.ok) { jd.text = r.text; jd.fetchNote = { kind: "ok", text: "Read from " + r.host + ". Check the text, then click “Extract requirements”." }; }
          else jd.fetchNote = { kind: "warn", text: r.reason + " Paste the job description below." };
        }, function (e) { jd.fetchNote = { kind: "warn", text: e.message + " Paste the job description below." }; })
        .then(function () { jd.fetching = false; renderTop(); });
    }
    function parse() {
      jd.parsing = true; jd.error = null; renderTop();
      api("/api/jd/parse", { method: "POST", body: { text: jd.text } })
        .then(function (r) { jd.req = r.requirements; jd.lang = r.jdLanguage; }, function (e) { jd.error = e.message; })
        .then(function () { jd.parsing = false; renderTop(); renderMarket(); loadStatus(); });
    }
    function startMatch() {
      jd.error = null; jd.results = []; jd.stopped = false; jd.copied = null; jd.progress = { text: "Ranking the pool from the title index…" };
      renderTop(); renderOut();
      api("/api/jd/match", { method: "POST", body: { requirements: jd.req, title: page.title, sub: page.sub, group: page.group, jdText: jd.text } })
        .then(function (r) {
          jd.run = r;
          if (r.engine === "ai") {
            if (!r.shortlisted) { jd.progress = null; jd.error = "Nobody in " + (r.pools.join(" + ") || "the index") + " matches these requirements well enough to score."; renderOut(); return; }
            return nextBatch(r, 0);
          }
          jd.progress = null; jd.results = r.results; jd.pageNo = r.page; jd.totalPages = r.totalPages;
          if (!r.ranked) jd.error = "Nobody in " + (r.pools.join(" + ") || "the index") + " matches these requirements.";
          renderOut();
        }, function (e) { jd.progress = null; jd.error = e.message; renderOut(); })
        .then(function () { loadStatus(); });
    }
    function goPage(n) {
      jd.progress = { text: "Reading page " + n + "…" }; renderOut();
      api("/api/jd/page", { method: "POST", body: { matchId: jd.run.matchId, page: n } })
        .then(function (r) { jd.results = r.results; jd.pageNo = r.page; jd.totalPages = r.totalPages; }, function (e) { jd.error = e.message; })
        .then(function () { jd.progress = null; renderOut(); });
    }
    function nextBatch(r, i) {
      if (i >= r.batches || jd.stopped) { jd.progress = null; renderOut(); return; }
      jd.progress = { text: "AI is scoring candidates " + (i * r.batchSize + 1) + "–" + Math.min(r.shortlisted, (i + 1) * r.batchSize) + " of " + r.shortlisted + "…" };
      renderOut();
      return api("/api/jd/batch", { method: "POST", body: { matchId: r.matchId, batch: i } })
        .then(function (b) {
          jd.results = jd.results.concat(b.results).sort(function (a, c) { return c.score - a.score; });
          return nextBatch(r, i + 1);
        }, function (e) { jd.progress = null; jd.error = e.message; renderOut(); });
    }
    function copyDeepPrompt() {
      var text = jd.run.deepPrompt;
      var done = function () { jd.copied = { ok: true }; renderOut(); };
      var fallback = function () { jd.copied = { ok: false }; renderOut(); };
      if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(done, fallback); else fallback();
    }

    function field(label, value, onInput, opts) {
      opts = opts || {};
      var el = opts.lines ? h("textarea", { class: fieldClass, rows: String(opts.rows || 3) }) : h("input", { class: fieldClass, type: opts.type || "text" });
      el.value = value === null || value === undefined ? "" : value;
      el.addEventListener("input", function () { onInput(el.value); });
      return h("label", { class: "block text-sm" + (opts.wide ? " sm:col-span-2" : "") }, h("span", { class: "mb-1 block text-xs uppercase tracking-wide text-white/50", text: label }), el);
    }
    function lines(v) { return String(v || "").split(/\n|,/).map(function (s) { return s.trim(); }).filter(Boolean); }

    function renderTop() {
      clear(top);
      var s = jd.status;
      top.appendChild(h("div", { class: "flex flex-wrap items-start justify-between gap-2" },
        h("div", null, h("h2", { class: "text-lg font-semibold", text: "Match a job description" }),
          h("p", { class: "text-sm text-white/70", text: aiOn
            ? "Reads the JD, ranks the matching pool and lets the AI score the top 40 on their Atlas profile and CV."
            : "Reads the JD with Signal Desk's dictionary (no paid AI) and ranks everyone in the matching pool on title, skills, seniority and location." })),
        s && aiOn ? h("span", { class: "text-xs text-white/50", text: s.remainingToday + " of " + s.dailyLimit + " AI matches left today · AI spend €" + s.spentEur.toFixed(2) + " of €" + s.limitEur.toFixed(0) + " this month" }) : null));
      if (s && !s.indexReady) top.appendChild(notice("warn", "The Atlas title index is not built yet, so matching can't start. Lists → Rebuild index (or wait for tonight's cron run)."));
      if (jd.fetching) top.appendChild(h("p", { class: "flex items-center gap-2 text-sm text-white/70" }, h("span", { class: "sd-spinner" }), " Reading the job page…"));
      if (jd.fetchNote) top.appendChild(notice(jd.fetchNote.kind, jd.fetchNote.text));
      var ta = h("textarea", { class: fieldClass, rows: "8", placeholder: "Paste the job description here (English, Dutch or French)", "aria-label": "Job description" });
      ta.value = jd.text;
      ta.addEventListener("input", function () { jd.text = ta.value; });
      top.appendChild(ta);
      top.appendChild(h("div", { class: "flex flex-wrap items-center gap-2" },
        h("button", { type: "button", class: "rounded-xl bg-pink px-4 py-2 text-sm font-semibold text-white hover:brightness-110 disabled:opacity-50", disabled: jd.parsing || jd.fetching,
          onclick: parse, text: jd.parsing ? "Extracting…" : (jd.req ? "Extract again" : "Extract requirements") }),
        page.jobUrl && !jd.fetching ? h("button", { type: "button", class: "sd-btn-secondary", onclick: fetchJob, text: "Read the job page again" }) : null));
      if (jd.error && !jd.run) top.appendChild(notice("error", jd.error));
      if (!jd.req) return;
      var r = jd.req;
      top.appendChild(h("div", { class: "rounded-xl border border-line/60 bg-black/20 p-3" },
        h("p", { class: "mb-2 text-sm font-medium text-white", text: "Requirements (check and edit them, then find candidates)" + (jd.lang ? " · JD language: " + jd.lang.toUpperCase() : "") }),
        h("div", { class: "grid gap-2 sm:grid-cols-2" },
          field("Role", r.role, function (v) { r.role = v; }),
          field("Seniority (junior, medior, senior, lead, head, c-level)", r.seniority, function (v) { r.seniority = v.trim().toLowerCase(); }),
          field("Must have (one per line)", r.mustHave.join("\n"), function (v) { r.mustHave = lines(v); }, { lines: true, rows: 5 }),
          field("Nice to have (one per line)", r.niceToHave.join("\n"), function (v) { r.niceToHave = lines(v); }, { lines: true, rows: 5 }),
          field("Min. years of experience", r.minYears, function (v) { r.minYears = v === "" ? null : parseInt(v, 10) || null; }, { type: "number" }),
          field("Languages", r.languages.join(", "), function (v) { r.languages = lines(v); }),
          field("Location", r.location, function (v) { r.location = v; }),
          field("Clearance", r.clearance, function (v) { r.clearance = v; }),
          field("Contract type", r.contractType, function (v) { r.contractType = v; })),
        h("div", { class: "mt-3 flex flex-wrap items-center gap-2" },
          h("button", { type: "button", class: "rounded-xl bg-pink px-4 py-2 text-sm font-semibold text-white hover:brightness-110 disabled:opacity-50", disabled: !!jd.progress,
            onclick: startMatch, text: jd.run ? "Find candidates again" : "Find candidates" }),
          h("span", { class: "text-xs text-white/50", text: aiOn ? "Uses 1 of your daily AI matches." : "Score: title 35 · must-haves 35 · nice-to-haves 10 · seniority 10 · location 10." }))));
    }

    function scoreBubble(score, title) {
      var tone = score >= 70 ? "sd-score-high" : score >= 45 ? "sd-score-mid" : "sd-score-low";
      return h("div", { class: "sd-score " + tone, title: title, text: String(score) });
    }
    function ruleCard(c) {
      var href = safeHref(c.profileUrl);
      var p = c.parts || {};
      var why = h("details", { class: "group mt-2" },
        h("summary", { class: "text-sm text-white/60 hover:text-white" },
          h("span", { class: "group-open:hidden", text: "▸ Why this score" }), h("span", { class: "hidden group-open:inline", text: "▾ Why this score" })),
        h("div", { class: "mt-2 space-y-2 text-sm" },
          h("p", { class: "text-xs text-white/60", text: "Title " + p.title + "/35 · must-haves " + p.must + "/35 · nice-to-haves " + p.nice + "/10 · seniority " + p.seniority + "/10 · location " + p.location + "/10" }),
          c.matched.length ? h("ul", { class: "space-y-1" }, c.matched.map(function (m) { return h("li", { class: "text-ok", text: "✓ " + m }); })) : null,
          c.missing.length ? h("ul", { class: "space-y-1" }, c.missing.map(function (m) { return h("li", { class: "text-warn", text: "✗ " + m }); })) : null,
          h("p", { class: "text-xs text-white/50", text: "Based on the Atlas title index (current and past titles, skills, city). Open the profile to check." })));
      return h("li", { class: "rounded-2xl border border-line bg-card p-3" },
        h("div", { class: "flex items-start gap-3" },
          scoreBubble(c.score, "Rule-based fit (0–100)"),
          h("div", { class: "min-w-0 flex-1" },
            h("h3", { class: "font-semibold leading-snug", text: c.rank + ". " + c.name }),
            h("p", { class: "text-sm text-white/85", text: [c.role, c.company].filter(Boolean).join(" · ") || "—" }),
            c.location ? h("p", { class: "text-xs text-white/60", text: c.location }) : null,
            c.matched.length ? h("p", { class: "mt-1 text-sm text-ok", text: "✓ " + c.matched.slice(0, 5).join(" · ") }) : null,
            c.missing.length ? h("p", { class: "text-sm text-warn", text: "✗ " + c.missing.slice(0, 4).join(" · ") }) : null,
            why,
            h("div", { class: "mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm" },
              href ? h("a", { href: href, target: "_blank", rel: "noopener noreferrer", class: "font-medium text-pink-soft hover:underline", text: "Open in Atlas ↗" }) : null,
              c.hasCv ? cvButton(c) : h("span", { class: "text-xs text-white/40", text: "no CV in Atlas" })))));
    }
    function aiCard(c, rank) {
      var href = safeHref(c.profileUrl);
      var why = h("details", { class: "group mt-2" },
        h("summary", { class: "text-sm text-white/60 hover:text-white" },
          h("span", { class: "group-open:hidden", text: "▸ Why this score" }), h("span", { class: "hidden group-open:inline", text: "▾ Why this score" })),
        h("div", { class: "mt-2 space-y-2 text-sm" },
          c.met.length ? h("ul", { class: "space-y-1" }, c.met.map(function (m) { return h("li", null, h("span", { class: "text-ok", text: "✓ " + m.requirement }), h("span", { class: "text-white/70", text: " — " + m.evidence })); })) : null,
          c.gaps.length ? h("ul", { class: "space-y-1" }, c.gaps.map(function (g) { return h("li", { class: "text-warn", text: "✗ " + g }); })) : null,
          c.risks.length ? h("ul", { class: "space-y-1" }, c.risks.map(function (g) { return h("li", { class: "text-bad", text: "⚠ " + g }); })) : null));
      return h("li", { class: "rounded-2xl border border-line bg-card p-3" },
        h("div", { class: "flex items-start gap-3" },
          scoreBubble(c.score, "AI fit score (0–100)"),
          h("div", { class: "min-w-0 flex-1" },
            h("h3", { class: "font-semibold leading-snug", text: (rank + 1) + ". " + c.name }),
            h("p", { class: "text-sm text-white/85", text: [c.role, c.company].filter(Boolean).join(" · ") || "—" }),
            why,
            h("div", { class: "mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm" },
              href ? h("a", { href: href, target: "_blank", rel: "noopener noreferrer", class: "font-medium text-pink-soft hover:underline", text: "Open in Atlas ↗" }) : null,
              cvButton(c)))));
    }
    function renderOut() {
      clear(out);
      if (!jd.run && !jd.progress) { if (jd.error && jd.req) out.appendChild(notice("error", jd.error)); return; }
      var r = jd.run;
      if (r) {
        out.appendChild(h("div", { class: "flex flex-wrap items-center justify-between gap-2" },
          h("p", { class: "text-sm text-white/70", text: "Pool: " + (r.pools.join(" + ") || "all pools") + " · " + fmt(r.poolSize) + " people · " + (r.engine === "ai" ? "AI scores the top " + r.shortlisted : "top " + r.ranked + " shown") }),
          h("button", { type: "button", class: "sd-btn-secondary", onclick: copyDeepPrompt, title: "Copies a prompt for Claude with the JD, the requirements, the pool and the top 20 Atlas person IDs (no names)", text: "Deep match in Claude" })));
        if (jd.copied && jd.copied.ok) out.appendChild(notice("ok", "Prompt copied. Paste it into Claude (with the Atlas connector switched on). It contains Atlas person IDs only, no names.", function () { jd.copied = null; renderOut(); }));
        if (jd.copied && !jd.copied.ok) {
          var ta = h("textarea", { class: fieldClass, rows: "8", readonly: true, "aria-label": "Prompt for Claude" });
          ta.value = r.deepPrompt;
          out.appendChild(notice("warn", "Copy didn't work in this browser: select the text below and copy it."));
          out.appendChild(ta);
          setTimeout(function () { ta.focus(); ta.select(); }, 0);
        }
      }
      out.appendChild(notice("warn", r && r.engine === "ai" ? "AI suggestion: check before contacting." : "Rule-based suggestion from titles and skills in Atlas: check the profile before contacting."));
      if (jd.progress) out.appendChild(h("div", { class: "flex flex-wrap items-center gap-2" },
        h("p", { class: "flex items-center gap-2 text-sm text-white/70" }, h("span", { class: "sd-spinner" }), " " + jd.progress.text),
        r && r.engine === "ai" ? h("button", { type: "button", class: "sd-btn-secondary", text: "Stop", onclick: function () { jd.stopped = true; } }) : null));
      if (jd.error) out.appendChild(notice("error", jd.error));
      if (!jd.results.length) return;
      out.appendChild(h("ul", { class: "space-y-3" }, r && r.engine === "ai" ? jd.results.map(aiCard) : jd.results.map(ruleCard)));
      if (r && r.engine !== "ai" && jd.totalPages > 1) {
        out.appendChild(h("div", { class: "flex items-center justify-between gap-2 text-sm" },
          h("button", { type: "button", class: "sd-btn-secondary", disabled: jd.pageNo <= 1 || !!jd.progress, onclick: function () { goPage(jd.pageNo - 1); }, text: "← Previous" }),
          h("span", { class: "text-white/60", text: "Page " + jd.pageNo + " of " + jd.totalPages }),
          h("button", { type: "button", class: "sd-btn-secondary", disabled: jd.pageNo >= jd.totalPages || !!jd.progress, onclick: function () { goPage(jd.pageNo + 1); }, text: "Next →" })));
      }
    }

    // ---------------------------------------------------------------- In the market (Prospeo)
    var mk = { status: null, filters: null, preparing: false, searching: false, data: null, page: 1, error: null, reveal: {} };
    var SENIORITIES = ["Founder/Owner", "C-Suite", "Partner", "Vice President", "Head", "Director", "Manager", "Senior", "Entry", "Intern"];
    function mkStatus() { api("/api/prospeo/status").then(function (s) { mk.status = s; renderMarket(); }, function (e) { mk.error = e.message; renderMarket(); }); }
    function prepare() {
      mk.preparing = true; mk.error = null; renderMarket();
      api("/api/prospeo/filters", { method: "POST", body: { requirements: jd.req } })
        .then(function (f) { mk.filters = f; }, function (e) { mk.error = e.message; })
        .then(function () { mk.preparing = false; renderMarket(); });
    }
    function search(n) {
      mk.searching = true; mk.error = null; renderMarket();
      api("/api/prospeo/search", { method: "POST", body: { filters: mk.filters, page: n, requirements: jd.req } })
        .then(function (d) { mk.data = d; mk.page = d.page; if (mk.status) mk.status.usedToday = d.credits.usedToday; }, function (e) { mk.error = e.message; })
        .then(function () { mk.searching = false; renderMarket(); });
    }
    function reveal(c, what) {
      var st = mk.reveal[c.personId] = mk.reveal[c.personId] || {};
      st.busy = what; st.error = null; st.confirm = null; renderMarket();
      api("/api/prospeo/reveal", { method: "POST", body: { personId: c.personId, what: what, confirm: true } })
        .then(function (r) {
          if (what === "email") st.email = r.email || "no verified email found (no credit used)";
          else st.mobile = r.mobile || "no mobile found (no credit used)";
          if (mk.status) mk.status.usedToday = r.usedToday;
        }, function (e) { st.error = e.message; })
        .then(function () { st.busy = null; renderMarket(); });
    }
    function marketCard(c) {
      var st = mk.reveal[c.personId] || {};
      var li = safeHref(c.linkedinUrl);
      var actions = h("div", { class: "mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm" },
        li ? h("a", { href: li, target: "_blank", rel: "noopener noreferrer", class: "font-medium text-pink-soft hover:underline", text: "LinkedIn ↗" }) : null,
        c.inAtlas ? h("a", { href: safeHref(c.inAtlas.profileUrl), target: "_blank", rel: "noopener noreferrer", class: "sd-review-badge", text: "Already in Atlas ↗" }) : null,
        !st.email ? h("button", { type: "button", class: "sd-btn-secondary", disabled: !!st.busy, onclick: function () { st.confirm = "email"; mk.reveal[c.personId] = st; renderMarket(); }, text: st.busy === "email" ? "Revealing…" : "Reveal contact" }) : null,
        st.email && !st.mobile ? h("button", { type: "button", class: "sd-btn-secondary", disabled: !!st.busy, onclick: function () { st.confirm = "mobile"; mk.reveal[c.personId] = st; renderMarket(); }, text: st.busy === "mobile" ? "Revealing…" : "Reveal mobile" }) : null);
      var confirmBox = st.confirm ? h("div", { class: "mt-2 rounded-xl border border-warn/50 bg-warn/10 p-2 text-sm text-warn", role: "alertdialog" },
        h("p", { text: (st.confirm === "email" ? "Reveal the email address? This costs 1 Prospeo credit (none if nothing is found)." : "Reveal the mobile number? This costs 10 Prospeo credits (none if nothing is found).")
          + (mk.status ? " Today: " + mk.status.usedToday + " of " + mk.status.cap + " credits used." : "") }),
        h("div", { class: "mt-2 flex gap-2" },
          h("button", { type: "button", class: "rounded-xl bg-pink px-3 py-1.5 text-sm font-semibold text-white hover:brightness-110", onclick: function () { reveal(c, st.confirm); }, text: st.confirm === "email" ? "Reveal email (1 credit)" : "Reveal mobile (10 credits)" }),
          h("button", { type: "button", class: "sd-btn-secondary", onclick: function () { st.confirm = null; renderMarket(); }, text: "Cancel" }))) : null;
      return h("li", { class: "rounded-2xl border border-line bg-card p-3" },
        h("div", { class: "flex items-start gap-3" },
          scoreBubble(c.fit.score, "Fit based on title & company only"),
          h("div", { class: "min-w-0 flex-1" },
            h("h3", { class: "font-semibold leading-snug", text: c.name }),
            h("p", { class: "text-sm text-white/85", text: [c.title, c.company].filter(Boolean).join(" · ") || "—" }),
            c.location ? h("p", { class: "text-xs text-white/60", text: c.location }) : null,
            h("p", { class: "text-xs text-white/50", text: "Fit " + c.fit.score + "/100, based on title & company only" + (c.fit.missing.length ? " · missing: " + c.fit.missing.slice(0, 3).join(", ") : "") }),
            st.email ? h("p", { class: "mt-1 text-sm text-white", text: "Email: " + st.email }) : null,
            st.mobile ? h("p", { class: "text-sm text-white", text: "Mobile: " + st.mobile }) : null,
            st.error ? notice("error", st.error) : null,
            actions, confirmBox)));
    }
    function renderMarket() {
      clear(market);
      if (!prospeoOn || !jd.req) return;
      var s = mk.status;
      var box = h("section", { class: "space-y-3 rounded-2xl border border-line bg-card/80 p-3" },
        h("div", { class: "flex flex-wrap items-start justify-between gap-2" },
          h("div", null, h("h2", { class: "text-lg font-semibold", text: "In the market (Prospeo)" }),
            h("p", { class: "text-sm text-white/70", text: "People outside Atlas who fit the role. Search costs 1 credit per page of 25; contacts only on request." })),
          s ? h("span", { class: "text-xs text-white/50", text: fmt(s.remaining) + " credits left · today " + s.usedToday + " of " + s.cap + " used" }) : null));
      market.appendChild(box);
      if (mk.error) box.appendChild(notice("error", mk.error));
      if (!mk.filters) {
        box.appendChild(h("button", { type: "button", class: "sd-btn-secondary", disabled: mk.preparing, onclick: prepare, text: mk.preparing ? "Preparing…" : "Prepare a market search (free)" }));
        return;
      }
      var f = mk.filters;
      var sen = h("div", { class: "flex flex-wrap gap-2" }, SENIORITIES.map(function (v) {
        var cb = h("input", { type: "checkbox", class: "h-4 w-4", checked: f.seniority.indexOf(v) >= 0, onchange: function (e) {
          f.seniority = e.target.checked ? f.seniority.concat([v]) : f.seniority.filter(function (x) { return x !== v; }); } });
        return h("label", { class: "flex items-center gap-1 text-xs text-white/80" }, cb, v);
      }));
      box.appendChild(h("div", { class: "grid gap-2 sm:grid-cols-2" },
        field("Job titles (one per line, similar titles match too)", f.titles.join("\n"), function (v) { f.titles = lines(v); }, { lines: true, rows: 4 }),
        field("Keywords (one per line, max 5)", f.keywords.join("\n"), function (v) { f.keywords = lines(v); }, { lines: true, rows: 4 }),
        field("Locations (Prospeo names, one per line)", f.locations.join("\n"), function (v) { f.locations = lines(v); }, { lines: true, rows: 2 }),
        h("div", { class: "grid grid-cols-2 gap-2" },
          field("Years min.", f.yearsMin, function (v) { f.yearsMin = v === "" ? null : parseInt(v, 10); }, { type: "number" }),
          field("Years max.", f.yearsMax, function (v) { f.yearsMax = v === "" ? null : parseInt(v, 10); }, { type: "number" })),
        h("div", { class: "sm:col-span-2" }, h("span", { class: "mb-1 block text-xs uppercase tracking-wide text-white/50", text: "Seniority" }), sen)));
      box.appendChild(h("div", { class: "flex flex-wrap items-center gap-2" },
        h("button", { type: "button", class: "rounded-xl bg-pink px-4 py-2 text-sm font-semibold text-white hover:brightness-110 disabled:opacity-50", disabled: mk.searching,
          onclick: function () { search(1); }, text: mk.searching ? "Searching…" : "Search the market (1 credit / 25 people)" })));
      var d = mk.data;
      if (!d) return;
      box.appendChild(h("p", { class: "text-sm text-white/70", text: fmt(d.total) + " people · page " + d.page + " of " + d.totalPages + (d.credits.free ? " · this page was free (searched before)" : " · " + d.credits.charged + " credit used") }));
      if (!d.results.length) { box.appendChild(h("p", { class: "text-sm text-white/60", text: "No one matches these filters. Widen the titles or locations." })); return; }
      box.appendChild(h("ul", { class: "space-y-3" }, d.results.map(marketCard)));
      if (d.totalPages > 1) {
        box.appendChild(h("div", { class: "flex items-center justify-between gap-2 text-sm" },
          h("button", { type: "button", class: "sd-btn-secondary", disabled: d.page <= 1 || mk.searching, onclick: function () { search(d.page - 1); }, text: "← Previous (free if seen)" }),
          h("span", { class: "text-white/60", text: "Page " + d.page + " of " + d.totalPages }),
          h("button", { type: "button", class: "sd-btn-secondary", disabled: d.page >= d.totalPages || mk.searching, onclick: function () { search(d.page + 1); }, text: "Next (1 credit) →" })));
      }
    }

    renderTop();
    loadStatus();
    if (prospeoOn) mkStatus();
    if (page.jobUrl) fetchJob();
  }

  // ================================================================ Dashboard page (was "Outreach"; lemlist, read-only)
  // Layout and look follow the approved mockup (signal-desk-design/outreach-mockup.dc.html). No inline styles (CSP):
  // colours come from classes that set --c (sd-c-*, sd-st-*, sd-f-*), widths through el.style.width (the "width" key of h()).
  // The selection lives in the URL (?c=ids&p=7) and is remembered per user.
  // "Live · last lemlist event 14 s ago" in the top bar (#sd-live). The server fills data-at; the Outreach page updates it.
  function liveStatus(eventAt, syncedAt) {
    var el = document.getElementById("sd-live");
    if (!el) return;
    if (eventAt !== undefined) el.setAttribute("data-at", eventAt || "");
    if (syncedAt !== undefined) el.setAttribute("data-synced", syncedAt || "");
    var at = el.getAttribute("data-at");
    var t = !at ? 0 : /^\d+$/.test(at) ? Number(at) * 1000 : Date.parse(at);
    var s = Math.max(0, Math.round((Date.now() - t) / 1000));
    var live = t && s < 86400;
    var txt = !live ? "No lemlist event in 24 h" : "Live · last lemlist event " + (s < 60 ? s + " s" : s < 3600 ? Math.round(s / 60) + " min" : Math.round(s / 3600) + " h") + " ago";
    el.classList.toggle("sd-live-off", !live);
    el.firstChild.className = "sd-dot " + (live ? "sd-c-live" : "sd-c-muted");
    el.lastChild.textContent = txt;
    var sy = el.getAttribute("data-synced");
    el.title = "lemlist webhook events arrive live; the full sync runs every 15 minutes" + (sy ? " (last sync " + new Date(/^\d+$/.test(sy) ? Number(sy) * 1000 : Date.parse(sy)).toLocaleString("en-GB") + ")" : "");
  }
  // ================================================================ Calls tab (SDR desk): call tasks, manual tasks, inbox
  // Layout and look follow the approved mockup (signal-desk-design/outreach-mockup.dc.html, Calls tab). Everything acts as the
  // shared lemlist sender; LEMLIST_WRITES=off = preview mode (the server answers what it WOULD send). No inline styles (CSP).
  function confirmDialog(title, text, okLabel) {
    return new Promise(function (resolve) {
      var dlg = h("dialog", { class: "sd-k-dialog", "aria-labelledby": "sd-k-dialog-title" });
      var done = function (v) { try { dlg.close(); } catch (e) { /* ignore */ } dlg.remove(); resolve(v); };
      dlg.appendChild(h("div", { class: "sd-o-stack-s" },
        h("h2", { id: "sd-k-dialog-title", class: "sd-o-h2", text: title }),
        h("p", { class: "sd-k-dialog-text", text: text }),
        h("div", { class: "sd-k-dialog-actions" },
          h("button", { type: "button", class: "sd-btn", onclick: function () { done(false); }, text: "Cancel" }),
          h("button", { type: "button", class: "sd-btn sd-btn-on", onclick: function () { done(true); }, text: okLabel }))));
      dlg.addEventListener("cancel", function (e) { e.preventDefault(); done(false); });
      document.body.appendChild(dlg);
      if (dlg.showModal) dlg.showModal(); else dlg.setAttribute("open", "");
      dlg.querySelector(".sd-btn").focus();
    });
  }
  // Top-bar badges: Calls = open call + manual tasks, Inbox = unread conversations
  function setBadge(id, n) {
    var b = document.getElementById(id);
    if (!b) return;
    b.textContent = String(n || 0);
    if (n > 0) b.removeAttribute("hidden"); else b.setAttribute("hidden", "");
  }
  function refreshInboxBadge() {
    if (!document.getElementById("sd-badge-inbox")) return;
    api("/api/inbox/unread").then(function (d) { setBadge("sd-badge-inbox", d.badge); }, function () { /* keep the cached number */ });
  }
  function callsPage(sub) {
    var wantTask = new URLSearchParams(window.location.search).get("task"); // "Log call" on a manual call task
    var st = { sub: sub === "manual" || sub === "inbox" ? sub : "calls", data: null, error: null, msg: null, sel: null, ans: null, res: null, note: "", cb: "",
      attempt: newAttempt(), caller: "", logged: {}, mFilter: "all", mDone: {}, busy: false,
      inbox: { filter: "all", q: "", page: 1, total: 0, hasMore: false, more: false, older: false, full: {}, list: null, unread: 0, sel: null, thread: null, ch: "email", draft: "", loading: false, sent: [] }, seen: {} };
    var MT = { call: ["Call again", "sd-c-call"], li: ["LinkedIn message", "sd-c-message"], email: ["Email to review", "sd-c-email"], ooo: ["Out of office", "sd-c-fin"],
      visit: ["LinkedIn visit", "sd-c-invite"], task: ["Task", "sd-c-muted"] };
    var MF = [["all", "All"], ["call", "Calls"], ["li", "LinkedIn"], ["email", "Email"], ["ooo", "Out of office"], ["visit", "LinkedIn visit"], ["task", "Other"]];
    var CH = { email: ["Email", "sd-c-email"], linkedin: ["LinkedIn", "sd-c-message"], whatsapp: ["WhatsApp", "sd-c-invite"], sms: ["SMS", "sd-c-call"] };
    var LBL = { Interested: "sd-c-int", Meeting: "sd-c-meet", "Not interested": "sd-c-bnc", "Out of office": "sd-c-fin" };
    var root = h("div", { class: "sd-o sd-o-stack" });
    clear(app);
    app.appendChild(root);

    function newAttempt() {
      var a = new Uint8Array(8);
      (window.crypto || window.msCrypto).getRandomValues(a);
      return Array.prototype.map.call(a, function (b) { return ("0" + b.toString(16)).slice(-2); }).join("");
    }
    function tz(d, o) { return d.toLocaleString("en-GB", Object.assign({ timeZone: "Europe/Brussels" }, o)); }
    function dayKey(d) { return tz(d, { year: "numeric", month: "2-digit", day: "2-digit" }); }
    function when(iso) {
      if (!iso) return "";
      var d = new Date(iso), now = new Date();
      if (dayKey(d) === dayKey(now)) return tz(d, { hour: "2-digit", minute: "2-digit" });
      if (dayKey(d) === dayKey(new Date(now.getTime() - 86400000))) return "Yesterday";
      return tz(d, { day: "numeric", month: "short" });
    }
    function whenLong(iso) { return iso ? (dayKey(new Date(iso)) === dayKey(new Date()) ? "Today " + tz(new Date(iso), { hour: "2-digit", minute: "2-digit" }) : tz(new Date(iso), { day: "numeric", month: "short" }) + " " + tz(new Date(iso), { hour: "2-digit", minute: "2-digit" })) : ""; }
    function due(t) {
      if (!t.dueDate) return { text: "No due date", late: false };
      var d = new Date(t.dueDate), now = new Date();
      var days = Math.round((new Date(dayKey(now)) - new Date(dayKey(d))) / 86400000);
      if (d <= now) return days >= 1 ? { text: "Overdue " + days + (days === 1 ? " day" : " days"), late: true } : { text: "Due now", late: false };
      if (days === 0) return { text: "Due " + tz(d, { hour: "2-digit", minute: "2-digit" }), late: false };
      if (days === -1) return { text: "Tomorrow", late: false };
      return { text: tz(d, { weekday: "short", day: "numeric", month: "short" }), late: false };
    }
    function pill(text, cls) { return h("span", { class: "sd-pill sd-pill-fill " + cls, text: text }); }
    function btn(label, on, onclick, extra) {
      var b = h("button", { type: "button", class: "sd-btn" + (on ? " sd-btn-on" : "") + (extra ? " " + extra : ""), onclick: onclick, text: label });
      if (on !== null) b.setAttribute("aria-pressed", on ? "true" : "false");
      return b;
    }
    function say(kind, text) { st.msg = { kind: kind, text: text }; render(); }
    function previewText(r) {
      return r.message + (r.wouldSend ? " It would send: " + r.wouldSend.map(function (w) { return w.request + " (" + w.fields.join(", ") + ")"; }).join(" + ") + "." : "");
    }

    function load() {
      return api("/api/calls").then(function (d) {
        st.data = d; st.error = null;
        var open = d.queue.filter(function (q) { return !st.logged[q.id]; });
        if (wantTask && d.queue.some(function (q) { return q.id === wantTask; })) { st.sel = wantTask; resetCall(); }
        wantTask = null;
        if (!st.sel || !d.queue.some(function (q) { return q.id === st.sel; })) { st.sel = open.length ? open[0].id : null; resetCall(); }
        setBadge("sd-badge-calls", d.counts.badge);
        [["calls", d.counts.calls], ["manual", d.counts.manual]].forEach(function (c) {
          var el = document.querySelector('.sd-sub-count[data-count="' + c[0] + '"]');
          if (el) el.textContent = String(c[1]);
        });
      }, function (e) { st.error = e.message; }).then(render);
    }
    // the lemlist inbox, as lemlist shows it: newest activity first, 25 at a time ("Load more"); a refresh keeps what is loaded
    function inboxQuery(page, per) {
      return "/api/calls/inbox?filter=" + encodeURIComponent(st.inbox.filter) + "&q=" + encodeURIComponent(st.inbox.q) + "&page=" + page + (per ? "&per=" + per : "");
    }
    function loadInbox(keepThread) {
      var ib = st.inbox;
      ib.loading = !ib.list;
      var url = keepThread && ib.page > 1 ? inboxQuery(1, ib.page * 25) : inboxQuery(1);
      if (!keepThread) ib.page = 1;
      return api(url).then(function (d) {
        ib.list = d.conversations; ib.total = d.total; ib.hasMore = keepThread && ib.page > 1 ? ib.list.length < d.total : d.hasMore; ib.unread = d.unread; ib.loading = false;
        setBadge("sd-badge-inbox", d.unread);
        if (!ib.sel && d.conversations.length) openThread(d.conversations[0].contactId);
        else if (keepThread && ib.sel) openThread(ib.sel, true);
      }, function (e) { ib.loading = false; ib.error = e.message; }).then(render);
    }
    function loadMore() {
      var ib = st.inbox;
      ib.more = true; render();
      api(inboxQuery(ib.page + 1)).then(function (d) {
        var seen = {};
        (ib.list || []).forEach(function (x) { seen[x.contactId] = 1; });
        ib.list = (ib.list || []).concat(d.conversations.filter(function (x) { return !seen[x.contactId]; }));
        ib.page = d.page; ib.hasMore = d.hasMore; ib.total = d.total; ib.more = false;
      }, function (e) { ib.more = false; say("error", e.message); }).then(render);
    }
    function openThread(contactId, quiet) {
      if (!quiet) { st.inbox.sel = contactId; st.inbox.thread = null; st.inbox.draft = ""; st.inbox.full = {}; render(); }
      return api("/api/calls/thread?contactId=" + encodeURIComponent(contactId)).then(function (t) {
        if (st.inbox.sel !== contactId) return;
        if (quiet && st.inbox.thread && st.inbox.thread.contactId === contactId && st.inbox.thread.skip + st.inbox.thread.messages.length > t.messages.length) {
          t.messages = mergeMsgs(st.inbox.thread.messages, t.messages); t.hasOlder = st.inbox.thread.hasOlder; // keep "Load older" pages on a refresh
        }
        st.inbox.thread = t;
        if (!t.canLinkedin) st.inbox.ch = "email";
        else if (!quiet) { var c = (st.inbox.list || []).filter(function (x) { return x.contactId === contactId; })[0]; st.inbox.ch = c && c.channel === "linkedin" ? "linkedin" : "email"; }
        if (t.markedRead || !quiet) (st.inbox.list || []).forEach(function (x) {
          if (x.contactId !== contactId || !x.unread) return;
          x.unread = false; st.inbox.unread = Math.max(0, st.inbox.unread - 1); // opening it marks it read, as in lemlist
          setBadge("sd-badge-inbox", st.inbox.unread);
        });
      }, function (e) { st.inbox.error = e.message; }).then(render);
    }
    function mergeMsgs(a, b) {
      var byId = {}, out = [];
      a.concat(b).forEach(function (m) { var k = m.id || (m.at + m.type); if (!byId[k]) { byId[k] = 1; out.push(m); } });
      return out.sort(function (x, y) { return x.at < y.at ? -1 : x.at > y.at ? 1 : 0; });
    }
    function loadOlder() {
      var t = st.inbox.thread;
      if (!t) return;
      st.inbox.older = true; render();
      api("/api/calls/thread?contactId=" + encodeURIComponent(t.contactId) + "&skip=" + t.messages.length).then(function (o) {
        if (!st.inbox.thread || st.inbox.thread.contactId !== t.contactId) return;
        st.inbox.thread.messages = mergeMsgs(o.messages, st.inbox.thread.messages);
        st.inbox.thread.hasOlder = o.hasOlder;
      }, function (e) { say("error", e.message); }).then(function () { st.inbox.older = false; render(); });
    }
    // the call script (the step's lead variable, filled in) with a Copy button; the raw step text when the lead has none
    function copyBtn(text) {
      var b = h("button", { type: "button", class: "sd-btn sd-btn-sm", text: "Copy" });
      b.addEventListener("click", function () {
        var ok = function () { b.textContent = "Copied"; setTimeout(function () { b.textContent = "Copy"; }, 1500); };
        if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(ok, function () { b.textContent = "Copy failed"; });
        else {
          var ta = h("textarea", { class: "sr-only", "aria-hidden": "true" });
          ta.value = text; document.body.appendChild(ta); ta.select();
          try { document.execCommand("copy"); ok(); } catch (e) { b.textContent = "Copy failed"; }
          ta.remove();
        }
      });
      return b;
    }
    function scriptBox(sc) {
      if (!sc) return null;
      var box = h("div", { class: "sd-k-script", role: "region", "aria-label": "Call script" });
      box.appendChild(h("div", { class: "sd-o-between" }, h("span", { class: "sd-k-script-label" }, "Call script",
        sc.fields && sc.fields.length ? h("span", { class: "sd-k-script-field", text: " · " + sc.fields.join(", ") }) : null), sc.empty ? null : copyBtn(sc.text)));
      if (sc.empty) {
        box.appendChild(h("p", { class: "sd-k-script-text sd-muted", text: "No script for this lead." }));
        box.appendChild(h("p", { class: "sd-k-script-raw" }, h("span", { class: "sd-k-approx", text: "Step text in lemlist" }), sc.raw));
      } else box.appendChild(h("p", { class: "sd-k-script-text", text: sc.text }));
      return box;
    }
    function voicemailBox(vm) {
      if (!vm) return null;
      return h("details", { class: "sd-k-vm" }, h("summary", { class: "sd-k-vm-sum", text: "If no answer: voicemail" }),
        h("div", { class: "sd-k-vm-body" }, h("p", { class: "sd-k-script-text", text: vm.text }), h("div", null, copyBtn(vm.text))));
    }
    function resetCall() { st.ans = null; st.res = null; st.note = ""; st.cb = ""; st.attempt = newAttempt(); }
    // ---------------------------------------------------------- title (the sub-tabs are the pill row under the top bar)
    function header() {
      return h("div", { class: "sd-o-titlebar" }, h("h1", { class: "sd-o-h1", text: st.sub === "inbox" ? "Inbox" : "SDR desk" }));
    }
    function banners() {
      var d = st.data, out = [];
      if (!d) return out;
      if (d.writes !== "on") out.push(h("p", { class: "sd-k-banner", role: "status", text: "Preview mode: LEMLIST_WRITES is off, so nothing is sent to lemlist. Every button shows what it would send." }));
      if (d.viewOnly) out.push(h("p", { class: "sd-k-banner", role: "status", text: "View only: your login is not allowed to send or log calls as the lemlist user." }));
      if (!d.statuses.callsApi) out.push(h("p", { class: "sd-k-banner", role: "status", text: "Calls API needs Email Pro+: call tasks are listed, but log the calls in lemlist itself. Manual tasks and the inbox work." }));
      if (st.msg) out.push(h("p", { class: "sd-k-banner sd-k-banner-" + st.msg.kind, role: st.msg.kind === "error" ? "alert" : "status", text: st.msg.text }));
      if (st.error) out.push(h("p", { class: "sd-k-banner sd-k-banner-error", role: "alert", text: st.error }));
      return out;
    }

    // ---------------------------------------------------------- A. call tasks + "Now calling"
    function callsSection() {
      var d = st.data;
      var queue = d.queue.filter(function (q) { return !st.caller || q.assignedTo === st.caller; });
      var cur = queue.filter(function (q) { return q.id === st.sel && !st.logged[q.id]; })[0] || queue.filter(function (q) { return !st.logged[q.id]; })[0] || null;
      if (cur && cur.id !== st.sel) { st.sel = cur.id; resetCall(); }
      var callerSel = h("select", { id: "owner", class: "sd-k-select", "aria-label": "Caller" });
      [["", "All callers"]].concat(d.callers.map(function (u) { return [u.userId, u.name]; })).forEach(function (o) { var op = h("option", { value: o[0], text: o[1] }); if (o[0] === st.caller) op.selected = true; callerSel.appendChild(op); });
      callerSel.addEventListener("change", function () { st.caller = callerSel.value; render(); });
      var list = h("div", { class: "sd-k-list" });
      if (!queue.length) list.appendChild(h("p", { class: "sd-k-empty sd-muted", text: "No call tasks in lemlist right now." }));
      queue.forEach(function (k) {
        var done = st.logged[k.id], on = cur && k.id === cur.id, du = due(k);
        list.appendChild(h("button", { type: "button", class: "sd-k-row" + (on ? " sd-k-row-on" : done ? " sd-k-row-done" : ""), "aria-current": on ? "true" : null,
          onclick: function () { if (!st.logged[k.id] && st.sel !== k.id) { st.sel = k.id; resetCall(); render(); } } },
          h("span", { class: "sd-k-cell" }, h("span", { class: "sd-o-strong", text: k.name }), h("span", { class: "sd-muted sd-o-small", text: [k.jobTitle, k.company].filter(Boolean).join(" · ") })),
          h("span", { class: "sd-k-cell" }, h("span", { class: "sd-o-small13", text: [k.campaign, k.step ? "step " + k.step : ""].filter(Boolean).join(" · ") }),
            h("span", { class: "sd-o-signal", text: k.signal ? k.signal.track + " · " + k.signal.jobTitle : (k.kind === "manualCall" ? k.title : "") })),
          h("span", { class: "sd-o-small" + (du.late ? " sd-k-late" : " sd-muted"), text: du.text }),
          h("span", { class: "sd-k-status sd-pill-fill " + (done ? done.cls : on ? "sd-c-call" : "sd-st-in-sequence"), text: done ? done.label : on ? "Calling" : "To call" })));
      });
      var t = d.today;
      return h("section", { class: "sd-card sd-k-sec sd-c-call", "aria-label": "Call tasks" },
        h("div", { class: "sd-k-head" },
          h("div", { class: "sd-o-stack-xxs sd-o-gap4" }, h("h2", { class: "sd-k-h2" }, "Call tasks ", h("span", { class: "sd-k-count", text: d.counts.calls + " due" })),
            h("span", { class: "sd-muted sd-o-small13", text: "Synced from lemlist Tasks. Logging the outcome here completes the task in lemlist and moves the lead to its next step." })),
          h("div", { class: "sd-o-wrap" }, h("span", { class: "sd-muted sd-o-small13" }, "Done today: ", h("strong", { class: "sd-k-strong", text: String(t.done) }), " · answered " + t.answered + " · positive " + t.positive),
            h("label", { for: "owner", class: "sr-only", text: "Caller" }), callerSel)),
        h("div", { class: "sd-k-body" }, list, nowCalling(cur)));
    }
    function nowCalling(cur) {
      var d = st.data;
      var box = h("div", { class: "sd-k-now", "aria-label": "Now calling", role: "region" });
      if (!cur) {
        box.appendChild(h("div", { class: "sd-o-stack-xxs sd-o-gap4" }, h("span", { class: "sd-k-eyebrow", text: "Now calling" }), h("span", { class: "sd-k-name", text: "All calls done" }),
          h("span", { class: "sd-o-small13", text: "Context: Nothing left in the queue." })));
        return box;
      }
      var c = cur.context, parts = [];
      if (c.events.length) parts.push(c.events.map(function (e) { return e.label + " " + when(e.at); }).join("; "));
      else parts.push("No email or LinkedIn activity yet");
      if (c.atlas) parts.push((c.atlas.matching !== null ? c.atlas.matching + " matching titles in Atlas" : c.atlas.pool + " in the Atlas pool"));
      if (cur.signal) parts.push("Signal: " + cur.signal.track + " · " + cur.signal.jobTitle);
      if (cur.kind === "manualCall") parts.push("lemlist task: " + cur.title);
      var tel = c.tel && /^tel:\+?[0-9]{4,20}$/.test(c.tel) ? c.tel : null; // digits only (built by the server)
      var li = safeHref(c.linkedin);
      box.appendChild(h("div", { class: "sd-o-stack-xxs sd-o-gap4" }, h("span", { class: "sd-k-eyebrow", text: "Now calling" }), h("span", { class: "sd-k-name", text: cur.name }),
        h("span", { class: "sd-muted sd-o-small13", text: [cur.jobTitle, cur.company].filter(Boolean).join(" · ") }), h("span", { class: "sd-o-small13", text: "Context: " + parts.join(". ") + "." })));
      var sb = scriptBox(c.script);
      if (sb) box.appendChild(sb);
      var vb = voicemailBox(c.voicemail);
      if (vb) box.appendChild(vb);
      box.appendChild(h("div", { class: "sd-o-wrap" },
        tel ? h("a", { href: tel, class: "sd-k-callbtn", text: "Call " + c.phone }) : h("span", { class: "sd-k-nophone sd-muted", text: "No phone number in lemlist" }),
        li ? h("a", { href: li, target: "_blank", rel: "noopener noreferrer", class: "sd-btn-link", text: "LinkedIn" }) : null));
      var ro = d.viewOnly || !d.statuses.callsApi;
      box.appendChild(h("span", { class: "sd-k-step", text: "1. Did they answer?" }));
      var a1 = btn("Answered", st.ans === true, function () { st.ans = true; st.res = null; render(); }), a2 = btn("Not answered", st.ans === false, function () { st.ans = false; st.res = null; render(); });
      if (ro) { a1.disabled = true; a2.disabled = true; }
      box.appendChild(h("div", { class: "sd-o-wrap" }, a1, a2));
      var opts = st.ans === null ? [] : (st.ans ? d.statuses.answered : d.statuses.notAnswered);
      if (st.ans !== null) { // step 2 appears once step 1 is picked, with only that group's outcomes (changing step 1 clears it)
        box.appendChild(h("span", { class: "sd-k-step", text: "2. Outcome" }));
        box.appendChild(h("div", { class: "sd-o-wrap", role: "group", "aria-label": "Outcome" }, opts.map(function (o) {
          var b = btn(o.label, st.res === o.key, function () { st.res = o.key; render(); });
          b.title = [o.positive ? "marks the lead interested" : "", o.negative ? "marks the lead not interested" : "", o.pauses ? "pauses the campaign" : ""].filter(Boolean).join(", ");
          if (ro) b.disabled = true;
          return b;
        })));
      }
      box.appendChild(h("label", { for: "call-note", class: "sd-k-step", text: "3. Note (goes to lemlist)" }));
      var note = h("textarea", { id: "call-note", rows: "3", class: "sd-k-textarea", placeholder: "e.g. Hiring 2 SOC analysts in Q1, call back Monday", maxlength: "2000" });
      note.value = st.note;
      note.addEventListener("input", function () { st.note = note.value; });
      box.appendChild(note);
      var tomorrow = new Date(Date.now() + 86400000);
      var cb = h("input", { id: "callback", type: "date", class: "sd-k-date", min: tz(tomorrow, { year: "numeric" }) + "-" + tz(tomorrow, { month: "2-digit" }) + "-" + tz(tomorrow, { day: "2-digit" }) });
      cb.value = st.cb;
      cb.addEventListener("change", function () { st.cb = cb.value; render(); });
      box.appendChild(h("div", { class: "sd-o-wrap" }, h("label", { for: "callback", class: "sd-muted sd-o-small13", text: "Call back on" }), cb));
      var lbl = st.res ? (st.cb ? "Save & move task to " + st.cb : "Save & complete task in lemlist") : "Pick an outcome first";
      var save = h("button", { type: "button", class: "sd-k-save", onclick: function () { saveCall(cur); }, text: st.busy ? "Saving…" : lbl });
      if (!st.res || st.busy || ro) save.disabled = true;
      box.appendChild(save);
      var resLabel = st.res ? (opts.filter(function (o) { return o.key === st.res; })[0] || {}).label : "";
      box.appendChild(h("span", { class: "sd-muted sd-o-small", text: st.res ? (st.cb ? "Logs the call as \"" + resLabel + "\" with your note, moves this lemlist task to " + st.cb + " with your note and keeps it open (plus an Atlas note and task when Atlas writes are on)." : "Logs the call as \"" + resLabel + "\" with your note (also as an Atlas note when Atlas writes are on), completes the lemlist task, and opens the next call.") : "Choose Answered or Not answered, then the outcome." }));
      if (st.lastSave) { // one line per destination: lemlist / Atlas note / Atlas task
        box.appendChild(h("ul", { class: "sd-k-results", "aria-live": "polite", "aria-label": "Saved to" }, [h("li", { class: "sd-k-results-head", text: "Last save" + (st.lastSave.name ? " (" + st.lastSave.name + ")" : "") + ":" })].concat(st.lastSave.results.map(function (x) {
          return h("li", { class: "sd-k-result " + (x.ok === true ? "sd-k-result-ok" : x.ok === false ? "sd-k-result-err" : "sd-k-result-info"), text: x.text });
        }))));
      }
      box.appendChild(h("span", { class: "sd-muted sd-o-small", text: d.sender ? d.sender.label : "View only: calls cannot be logged from this login." }));
      return box;
    }
    function saveCall(cur) {
      if (!st.res || st.busy) return;
      var opts = st.ans ? st.data.statuses.answered : st.data.statuses.notAnswered;
      var o = opts.filter(function (x) { return x.key === st.res; })[0] || { label: st.res };
      st.busy = true; render();
      api("/api/calls/log", { method: "POST", body: { taskId: cur.id, attempt: st.attempt, answered: st.ans, status: st.res, note: st.note, callbackDate: st.cb } }).then(function (r) {
        st.busy = false;
        if (r.results) st.lastSave = { name: cur.name, results: r.results };
        if (r.retry) { say("warn", r.message); return; }
        if (r.ok === false && r.results) { st.msg = { kind: "error", text: r.message }; render(); return; } // keep the form (same attempt): Save again sends nothing twice
        if (r.preview) { st.logged[cur.id] = { label: "Preview", cls: "sd-c-fin" }; st.msg = { kind: "warn", text: previewText(r) }; }
        else if (r.ok) { st.logged[cur.id] = { label: st.cb ? "Call back" : o.label, cls: o.positive ? "sd-c-int" : o.negative ? "sd-c-bnc" : "sd-c-fin" }; st.msg = { kind: "ok", text: r.message }; if (r.today) st.data.today = r.today; }
        else { st.msg = { kind: "warn", text: r.message }; }
        resetCall();
        st.sel = null;
        if (r.refreshed || r.ok) load(); else render();
      }, function (e) { st.busy = false; say("error", e.message); });
    }

    // ---------------------------------------------------------- B. manual tasks
    function manualSection() {
      var d = st.data;
      var count = function (f) { return d.manual.filter(function (m) { return (f === "all" || m.manualType === f) && !st.mDone[m.id]; }).length; };
      var filters = MF.filter(function (f) { return ["all", "call", "li", "email", "ooo"].indexOf(f[0]) >= 0; }); // as in the mockup; visits and other tasks show under All
      var list = h("div", { class: "sd-k-mlist" });
      var shown = d.manual.filter(function (m) { return st.mFilter === "all" || m.manualType === st.mFilter; });
      if (!shown.length) list.appendChild(h("p", { class: "sd-k-empty sd-muted", text: "No open manual tasks here." }));
      shown.forEach(function (m) {
        var t = MT[m.manualType] || MT.task, du = due(m), closed = st.mDone[m.id];
        var typeLabel = m.manualType === "li" && m.type === "linkedinInvite" ? "LinkedIn invite" : m.manualType === "li" && m.type === "linkedinVoiceNote" ? "LinkedIn voice note" : t[0];
        var left = h("div", { class: "sd-k-mleft" }, h("span", { class: "sd-k-type " + t[1], text: typeLabel }),
          h("div", { class: "sd-o-stack-xxs" }, h("span", { class: "sd-o-strong", text: m.title || typeLabel }),
            h("span", { class: "sd-muted sd-o-small", text: [m.name, m.company, m.campaign].filter(Boolean).join(" · ") }),
            m.preview ? h("span", { class: "sd-k-preview" }, h("span", { class: "sd-k-approx", text: "Approximate preview" }), (m.preview.subject ? "Subject: " + m.preview.subject + "\n" : "") + m.preview.text) : null,
            m.script ? h("span", { class: "sd-k-preview sd-k-preview-call" }, h("span", { class: "sd-k-approx", text: m.script.empty ? "No script for this lead · step text" : "Call script" }),
              m.script.empty ? m.script.raw : m.script.text) : null));
        var right = h("div", { class: "sd-o-wrap" }, h("span", { class: "sd-k-due" + (du.late ? " sd-k-late" : " sd-muted"), text: du.text }));
        if (closed) right.appendChild(h("span", { class: "sd-k-closed sd-pill-fill " + (closed === "Skipped" || closed === "Preview" ? "sd-c-fin" : "sd-c-int"), text: closed }));
        else {
          var primary = m.kind === "manualCall" ? btn("Log call", true, function () { window.location.href = "/calls/tasks?task=" + encodeURIComponent(m.id); }, null)
            : m.kind === "message" ? btn("Send", true, function () { manualAction(m, "send"); }) : btn("Done", true, function () { manualAction(m, "done"); });
          primary.removeAttribute("aria-pressed");
          var skip = btn("Skip", null, function () { manualAction(m, "skip"); });
          if (d.viewOnly) { primary.disabled = m.kind !== "manualCall"; skip.disabled = true; }
          right.appendChild(h("div", { class: "sd-k-btns" }, primary, skip));
        }
        list.appendChild(h("div", { class: "sd-k-mrow" + (closed ? " sd-k-row-done" : "") }, left, right));
      });
      return h("section", { class: "sd-card sd-k-sec sd-c-message", "aria-label": "Manual tasks" },
        h("div", { class: "sd-k-head" },
          h("div", { class: "sd-o-stack-xxs sd-o-gap4" }, h("h2", { class: "sd-k-h2" }, "Manual tasks ", h("span", { class: "sd-k-count", text: count("all") + " open" })),
            h("span", { class: "sd-muted sd-o-small13", text: "Every manual step from lemlist: follow-up calls, LinkedIn messages and emails to review, out-of-office checks. Done or Skip here closes it in lemlist." })),
          h("div", { class: "sd-o-group", role: "group", "aria-label": "Task type" }, filters.map(function (f) {
            var b = btn(f[1] + " ", st.mFilter === f[0], function () { st.mFilter = f[0]; render(); });
            b.appendChild(h("span", { class: "sd-k-tabcount", text: String(count(f[0])) }));
            return b;
          }))),
        list,
        h("span", { class: "sd-muted sd-o-small", text: "Call-type manual tasks (\"Calling again\", \"Final call\") open the call logger, so the outcome is recorded too. Message tasks show the text lemlist will send; Send closes the task and lemlist sends the step." }));
    }
    function manualAction(m, action) {
      var go = action !== "send" ? Promise.resolve(true) : confirmDialog("Send this step to " + m.name + "?",
        "lemlist sends the " + (m.type === "email" ? "email" : "LinkedIn step") + " exactly as it is in the campaign \"" + m.campaign + "\" (the preview here is approximate). "
        + (st.data.sender ? st.data.sender.label + "." : ""), "Send");
      go.then(function (yes) {
        if (!yes) return;
        api("/api/calls/task", { method: "POST", body: { taskId: m.id, action: action, confirm: action === "send" ? true : undefined } }).then(function (r) {
          st.mDone[m.id] = r.preview ? "Preview" : r.refreshed ? "Closed" : { send: "Sent", done: "Done", skip: "Skipped" }[action];
          st.msg = { kind: r.preview || r.refreshed ? "warn" : "ok", text: r.preview ? previewText(r) : r.message };
          if (r.ok && !r.preview) load(); else render();
        }, function (e) { say("error", e.message); });
      });
    }

    // ---------------------------------------------------------- C. inbox (mirrors the lemlist inbox)
    function campLine(x) {
      if (x.inCampaign === false) return "Not in a campaign";
      var camp = x.campaign ? x.campaign + (x.campaignStatus && x.campaignStatus !== "running" ? " · " + x.campaignStatus : "") : "";
      return [x.company, camp, x.signal].filter(Boolean).join(" · ");
    }
    function inboxSection() {
      var ib = st.inbox, d = st.data;
      var left = h("div", { class: "sd-k-ileft" });
      var search = h("input", { id: "inbox-search", type: "search", class: "sd-k-isearch", placeholder: "Search name, email or company", "aria-label": "Search conversations", maxlength: "100" });
      search.value = ib.q;
      search.addEventListener("input", function () {
        ib.q = search.value;
        clearTimeout(ib.qTimer);
        ib.qTimer = setTimeout(function () { ib.sel = null; loadInbox(); }, 350);
      });
      left.appendChild(h("div", { class: "sd-k-ihead" },
        h("div", { class: "sd-o-between" }, h("h2", { class: "sd-o-h2", text: "Inbox" }), h("span", { class: "sd-muted sd-o-small", text: ib.unread + " unread · " + ib.total + (ib.filter === "all" && !ib.q ? " conversations" : " shown") + " · as in lemlist" })),
        search,
        h("div", { class: "sd-k-chips", role: "group", "aria-label": "Inbox filter" }, [["all", "All"], ["unread", "Unread"], ["Interested", "Interested"], ["Meeting", "Meeting"], ["Not interested", "Not interested"], ["Out of office", "Out of office"]].map(function (f) {
          return btn(f[1], ib.filter === f[0], function () { ib.filter = f[0]; ib.sel = null; loadInbox(); }, "sd-btn-sm");
        }))));
      if (ib.loading) left.appendChild(h("p", { class: "sd-k-empty sd-muted", text: "Reading the lemlist inbox…" }));
      else if (ib.list && !ib.list.length) left.appendChild(h("p", { class: "sd-k-empty sd-muted", text: ib.q ? "No conversation matches \"" + ib.q + "\"." : "No conversations here." }));
      (ib.list || []).forEach(function (c) {
        left.appendChild(h("button", { type: "button", class: "sd-k-conv" + (c.contactId === ib.sel ? " sd-k-conv-on" : ""), "aria-current": c.contactId === ib.sel ? "true" : null, onclick: function () { openThread(c.contactId); } },
          h("span", { class: "sd-o-between" }, h("span", { class: "sd-k-convname" + (c.unread ? " sd-k-unread" : "") }, h("span", { class: "sd-dot " + (c.unread ? "sd-c-accent" : "sd-k-nodot"), "aria-hidden": "true" }), c.name, c.unread ? h("span", { class: "sr-only", text: " (unread)" }) : null),
            h("span", { class: "sd-muted sd-o-small", text: when(c.at) })),
          h("span", { class: "sd-muted sd-o-small", text: campLine(c) }),
          h("span", { class: "sd-k-snippet" }, c.ours ? h("span", { class: "sd-k-you", text: "You: " }) : null, c.snippet || ""),
          h("span", { class: "sd-k-chips6" }, h("span", { class: "sd-k-chip " + (CH[c.channel] || CH.email)[1], text: (CH[c.channel] || CH.email)[0] }),
            (c.labels || []).map(function (l) { return h("span", { class: "sd-k-lbl " + (LBL[l] || "sd-c-muted"), text: l }); }))));
      });
      if (ib.hasMore) left.appendChild(h("div", { class: "sd-k-more" }, btn(ib.more ? "Loading…" : "Load more", null, loadMore, "sd-btn-sm")));
      var right = h("div", { class: "sd-k-iright" });
      var t = ib.thread;
      if (!ib.sel) right.appendChild(h("p", { class: "sd-k-empty sd-muted", text: "Choose a conversation." }));
      else if (!t) right.appendChild(h("p", { class: "sd-k-empty sd-muted", text: "Loading the conversation…" }));
      else {
        var conv = (ib.list || []).filter(function (c) { return c.contactId === t.contactId; })[0] || {};
        var unreadBtn = btn("Mark as unread", null, function () { markUnread(conv.contactId ? conv : { contactId: t.contactId }); }, "sd-btn-sm");
        right.appendChild(h("div", { class: "sd-k-thead" },
          h("div", { class: "sd-o-stack-xxs" }, h("span", { class: "sd-k-tname", text: t.name || conv.name || "Contact" }),
            h("span", { class: "sd-muted sd-o-small13", text: [t.jobTitle, campLine(t)].filter(Boolean).join(" · ") })),
          h("div", { class: "sd-k-chips", role: "group", "aria-label": "Label" }, ["Interested", "Meeting", "Not interested", "Out of office"].map(function (l) {
            var b = btn(l, conv.label === l, function () { setLabel(conv, conv.label === l ? null : l); }, "sd-btn-sm");
            if (d.viewOnly || !conv.contactId) b.disabled = true;
            return b;
          }).concat([unreadBtn]))));
        var msgs = h("div", { class: "sd-k-msgs" });
        if (t.hasOlder) msgs.appendChild(h("div", { class: "sd-k-more" }, btn(ib.older ? "Loading…" : "Load older messages", null, loadOlder, "sd-btn-sm")));
        t.messages.concat(ib.sent.filter(function (s) { return s.contactId === t.contactId; })).forEach(function (m) {
          var key = m.id || (m.at + m.type);
          var showFull = !!ib.full[key];
          var body = h("div", { class: "sd-k-bubble" + (m.out ? " sd-k-bubble-out" : "") },
            h("span", { class: "sd-k-meta", text: (m.out ? "You · " + m.who : (t.name || conv.name || m.who)) + " · " + m.channel + " · " + whenLong(m.at) }),
            m.subject ? h("span", { class: "sd-k-subject", text: m.subject }) : null,
            h("span", { class: "sd-k-text", text: showFull && m.full ? m.full : m.text }),
            m.attachments && m.attachments.length ? h("span", { class: "sd-k-files", text: "Attachments: " + m.attachments.join(", ") }) : (m.hasAttachments ? h("span", { class: "sd-k-files", text: "Has attachments (open in lemlist)" }) : null),
            m.full ? h("button", { type: "button", class: "sd-k-fulltoggle", "aria-expanded": showFull ? "true" : "false", onclick: function () { ib.full[key] = !showFull; render(); }, text: showFull ? "Show less" : "Show full email" }) : null);
          msgs.appendChild(h("div", { class: "sd-k-msgrow" + (m.out ? " sd-k-out" : "") }, body));
        });
        right.appendChild(msgs);
        var box = h("textarea", { id: "reply-box", rows: "4", class: "sd-k-textarea", placeholder: "Write your reply…", maxlength: "5000" });
        box.value = ib.draft;
        box.addEventListener("input", function () { ib.draft = box.value; });
        var liBtn = btn("LinkedIn", ib.ch === "linkedin", function () { ib.ch = "linkedin"; render(); }, "sd-btn-sm");
        if (!t.canLinkedin) { liBtn.disabled = true; liBtn.title = "LinkedIn replies need the lead of a campaign"; }
        var send = h("button", { type: "button", class: "sd-k-send", onclick: function () { sendReply(t); }, text: "Send via lemlist" });
        if (d.viewOnly) send.disabled = true;
        right.appendChild(h("div", { class: "sd-k-compose" },
          h("div", { class: "sd-o-wrap" }, h("span", { class: "sd-muted sd-o-small13", text: "Reply via" }), btn("Email", ib.ch === "email", function () { ib.ch = "email"; render(); }, "sd-btn-sm"), liBtn,
            h("span", { class: "sd-muted sd-o-small", text: d.sender ? d.sender.label + (ib.ch === "email" ? " · lemlist mailbox" : " · LinkedIn via lemlist") : "View only" }),
            !t.canLinkedin ? h("span", { class: "sd-muted sd-o-small", text: "LinkedIn: campaign leads only." }) : null),
          h("label", { for: "reply-box", class: "sr-only", text: "Reply" }), box,
          h("div", { class: "sd-k-composefoot" }, h("span", { class: "sd-muted sd-o-small", text: "Sent through lemlist, in the same thread. The lead's campaign stops automatically after a reply, as in lemlist." }), send)));
      }
      return h("section", { class: "sd-card sd-k-inbox sd-c-email", "aria-label": "Inbox" }, left, right);
    }
    function sendReply(t) {
      var text = (st.inbox.draft || "").trim();
      if (!text) { say("warn", "Write a reply first."); return; }
      var ch = st.inbox.ch;
      confirmDialog("Send this " + (ch === "email" ? "email" : "LinkedIn message") + " to " + (t.name || "this contact") + "?",
        (st.data.sender ? st.data.sender.label + ". " : "") + (ch === "email" ? "It goes out in the same email thread." : "It goes out on LinkedIn through lemlist.") + " No attachments.", "Send").then(function (yes) {
        if (!yes) return;
        api("/api/calls/reply", { method: "POST", body: { contactId: t.contactId, channel: ch, message: text, confirm: true } }).then(function (r) {
          if (r.preview) { st.msg = { kind: "warn", text: previewText(r) }; render(); return; }
          st.inbox.sent.push({ contactId: t.contactId, out: true, who: st.data.sender ? st.data.sender.name : "You", channel: ch === "email" ? "Email" : "LinkedIn", at: new Date().toISOString(), text: text });
          st.inbox.draft = "";
          (st.inbox.list || []).forEach(function (x) { if (x.contactId === t.contactId) { x.ours = true; x.snippet = text.replace(/\s+/g, " ").slice(0, 90); x.at = new Date().toISOString(); } });
          st.msg = { kind: "ok", text: r.message };
          render();
        }, function (e) { say("error", e.message); });
      });
    }
    function markUnread(conv) {
      api("/api/calls/unread", { method: "POST", body: { contactId: conv.contactId } }).then(function (r) {
        (st.inbox.list || []).forEach(function (x) { if (x.contactId === conv.contactId && !x.unread) { x.unread = true; st.inbox.unread++; } });
        setBadge("sd-badge-inbox", st.inbox.unread);
        st.inbox.sel = null; st.inbox.thread = null; // leave it, as lemlist does
        st.msg = { kind: "ok", text: r.message }; render();
      }, function (e) { say("error", e.message); });
    }
    function setLabel(conv, label) {
      api("/api/calls/label", { method: "POST", body: { contactId: conv.contactId, label: label } }).then(function (r) {
        if (r.preview) { st.msg = { kind: "warn", text: previewText(r) }; render(); return; }
        var ours = ["Interested", "Meeting", "Not interested", "Out of office"];
        conv.labels = (conv.labels || []).filter(function (l) { return ours.indexOf(l) < 0; }).concat(r.label ? [r.label] : []);
        conv.label = r.label; st.msg = { kind: "ok", text: r.message }; render();
      }, function (e) { say("error", e.message); });
    }

    function render() {
      var focus = document.activeElement && document.activeElement.id;
      clear(root);
      root.appendChild(header());
      banners().forEach(function (b) { root.appendChild(b); });
      if (!st.data) { if (!st.error) root.appendChild(h("p", { class: "sd-o-loading" }, h("span", { class: "sd-spinner" }), " Reading lemlist tasks…")); return; }
      root.appendChild(st.sub === "calls" ? callsSection() : st.sub === "manual" ? manualSection() : inboxSection());
      if (focus) { var el = document.getElementById(focus); if (el && el.focus) el.focus(); }
    }

    // live updates: the webhook marks news in the local database; poll that (cheap) every 15 s, reload what changed, full reload every 60 s
    var ticks = 0;
    setInterval(function () {
      if (document.hidden || st.busy) return;
      ticks++;
      var typing = document.activeElement && (document.activeElement.tagName === "TEXTAREA" || document.activeElement.tagName === "INPUT");
      api("/api/calls/updates").then(function (u) {
        setBadge("sd-badge-calls", u.badge);
        var changed = function (k) { var v = u[k] || 0; var was = st.seen[k]; st.seen[k] = v; return was !== undefined && was !== v; };
        var tasks = changed("tasksAt"), inbox = changed("inboxAt"), any = changed("eventAt");
        if ((tasks || any || ticks % 4 === 0) && st.sub !== "inbox" && !typing && st.ans === null) load();
        if ((inbox || ticks % 4 === 0) && st.inbox.list && !typing) loadInbox(true);
        else if (inbox && st.sub !== "inbox") refreshInboxBadge();
      }, function () { /* offline: try again next tick */ });
    }, 15000);
    render();
    load().then(function () { if (st.sub === "inbox") loadInbox(); else refreshInboxBadge(); });
  }

  var OUTREACH_STATUS_LABEL = { "Replied": "Replied: needs outcome" };
  function statusSlug(s) { return String(s || "").toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, ""); }
  function outreachPage() {
    var params = new URLSearchParams(window.location.search);
    var st = { c: params.get("c"), p: params.get("p"), data: null, loading: true, error: null, picker: false, search: "", draft: null, saveName: "",
      compare: false, compareIds: [], compareData: null, board: { view: "table", f: { status: "", campaign: "", track: "", group: "", channel: "", needs: false, q: "" }, page: 1, data: null, loading: false },
      lead: null, leadLoading: false };
    var PERIODS = [["today", "Today"], ["7", "7 days"], ["30", "30 days"]];
    var CHANNELS = [
      { k: "email", name: "Email", short: "Email", chip: "Email", act: "Emails delivered", resp: "Replies", rate: "reply rate" },
      { k: "invite", name: "LinkedIn invites", short: "Invites", chip: "Invite", act: "Invites sent", resp: "Accepted", rate: "acceptance rate" },
      { k: "message", name: "LinkedIn messages", short: "LI msgs", chip: "LI msg", act: "Messages sent", resp: "Replies", rate: "reply rate" },
      { k: "call", name: "Calls", short: "Phone", chip: "Call", act: "Calls made", resp: "Conversations", rate: "connect rate" }];
    var BIZ_COLOR = { "Email": "email", "LinkedIn invites": "invite", "LinkedIn messages": "message", "Calls": "call", "Total": "accent" };
    var RESPONDED = { replied: 1, accepted: 1, answered: 1, positive: 1, negative: 1 };
    var NOT_YET = { "not sent": 1, "not called": 1, "": 1 };
    var ALERTS = { sendLimitReached: "Sending limit reached", connectionIssue: "Mailbox or LinkedIn connection issue", customDomainErrors: "Custom tracking domain error", lemwarmPaused: "lemwarm paused" };
    document.body.classList.add("sd-o-body");
    clear(app);
    var top = h("div", { class: "sd-o-stack" });
    var body = h("div", { class: "sd-o-stack" });
    var boardBox = h("section", { class: "sd-card sd-o-board-card", "aria-label": "Live lead board" });
    var panel = h("div");
    app.appendChild(h("div", { class: "sd-o sd-o-stack" }, top, body, boardBox, panel));

    function ids() { return st.data ? st.data.selection.ids : []; }
    function cParam() { return st.data && !st.data.selection.all ? ids().join(",") : ""; }
    function load(quiet) {
      if (!quiet) { st.loading = true; renderBody(); }
      var q = [];
      if (st.c !== null) q.push("c=" + encodeURIComponent(st.c));
      if (st.p !== null) q.push("p=" + encodeURIComponent(st.p));
      return api("/api/outreach" + (q.length ? "?" + q.join("&") : "")).then(function (d) {
        st.data = d; st.error = null; st.c = d.selection.all ? "" : d.selection.ids.join(","); st.p = d.selection.p;
        try { window.history.replaceState(null, "", "/dashboard" + d.selection.query); } catch (e) { /* ignore */ }
        if (st.compare && st.compareIds.length >= 2) loadCompare();
      }, function (e) { st.error = e.message; }).then(function () { st.loading = false; renderTop(); renderBody(); if (!quiet) { st.board.page = 1; loadBoard(); } else loadBoard(true); });
    }
    function loadBoard(quiet) {
      var f = st.board.f;
      var q = ["page=" + st.board.page];
      if (cParam()) q.push("c=" + encodeURIComponent(cParam()));
      Object.keys(f).forEach(function (k) { if (f[k] === true) q.push(k + "=1"); else if (f[k]) q.push(k + "=" + encodeURIComponent(f[k])); });
      if (!quiet) { st.board.loading = true; renderBoard(); }
      api("/api/outreach/leads?" + q.join("&")).then(function (d) { st.board.data = d; }, function (e) { st.error = e.message; })
        .then(function () { st.board.loading = false; renderBoard(); });
    }
    function loadCompare() {
      api("/api/outreach/compare?c=" + encodeURIComponent(st.compareIds.join(",")) + "&p=" + st.p).then(function (d) { st.compareData = d; renderBody(); }, function (e) { st.error = e.message; renderBody(); });
    }
    function setSelection(list) { st.c = list === null ? "" : list.join(","); st.picker = false; st.draft = null; load(); }
    function openLead(id) {
      st.leadLoading = true; st.lead = { id: id }; renderPanel(true);
      api("/api/outreach/lead?id=" + encodeURIComponent(id)).then(function (d) { st.lead = d; }, function (e) { st.lead = { id: id, error: e.message }; })
        .then(function () { st.leadLoading = false; renderPanel(false); });
    }
    function setOutcome(outcome) {
      var id = st.lead.id;
      api("/api/outreach/outcome", { method: "POST", body: { leadId: id, outcome: outcome } })
        .then(function () { openLead(id); load(true); }, function (e) { st.lead.error = e.message; renderPanel(false); });
    }
    function ts(iso) { return !iso ? 0 : typeof iso === "number" ? iso * 1000 : Date.parse(iso); }
    function ago(iso) {
      if (!iso) return "never";
      var s = Math.max(0, Math.round((Date.now() - ts(iso)) / 1000));
      if (s < 60) return s + " s ago";
      if (s < 3600) return Math.round(s / 60) + " min ago";
      if (s < 86400) return Math.round(s / 3600) + " h ago";
      return Math.round(s / 86400) + " d ago";
    }
    function when(iso) {
      if (!iso) return "";
      var d = new Date(ts(iso)), now = new Date();
      var time = d.toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit", timeZone: "Europe/Brussels" });
      var day = function (x) { return x.toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric", timeZone: "Europe/Brussels" }); };
      if (day(d) === day(now)) return "Today " + time;
      if (day(d) === day(new Date(now.getTime() - 86400000))) return "Yesterday";
      return d.toLocaleDateString("en-GB", { day: "numeric", month: "short", timeZone: "Europe/Brussels" });
    }
    function pct(r) { return r && r.rate !== null ? r.rate + "%" : "–"; }
    function rateText(r) { return r && r.actions ? pct(r) + " (" + fmt(r.responses) + "/" + fmt(r.actions) + ")" : "–"; }
    function pill(text, cls) { return h("span", { class: "sd-pill " + cls, text: text }); }
    function dot(cls) { return h("span", { class: "sd-dot " + cls, "aria-hidden": "true" }); }
    function btn(label, on, onclick, extra) {
      var a = { type: "button", class: "sd-btn" + (on ? " sd-btn-on" : "") + (extra ? " " + extra : ""), onclick: onclick, text: label };
      if (on !== null) a["aria-pressed"] = on ? "true" : "false";
      return h("button", a);
    }
    // section heading as in the mockup: h2 (18 px, or 20 px with big) and a muted sub-line on the right (12 or 13 px)
    function head(title, sub, subSize, big) {
      return h("div", { class: "sd-o-head" }, h("h2", { class: "sd-o-h2" + (big ? " sd-o-h2-lg" : ""), text: title }),
        sub ? h("span", { class: "sd-muted " + (subSize === 13 ? "sd-o-small13" : "sd-o-small"), text: sub }) : null);
    }

    // ---------------------------------------------------------- 1. header: title, live status, period, campaigns, compare
    function renderTop() {
      clear(top);
      var d = st.data;
      var n = d ? d.campaigns.length : 0;
      var label = !d ? "Campaigns: …" : d.selection.all ? "Campaigns: All running (" + n + ")" : "Campaigns: " + d.selection.ids.length + " of " + n;
      if (d) liveStatus(d.dashboard.eventAt, d.dashboard.syncedAt);
      top.appendChild(h("div", { class: "sd-o-titlebar" },
        h("h1", { class: "sd-o-h1", text: "Dashboard" }),
        h("div", { class: "sd-o-actions" },
          h("div", { class: "sd-o-group", role: "group", "aria-label": "Period" }, PERIODS.map(function (x) { return btn(x[1], st.p === x[0], function () { st.p = x[0]; load(); }); })),
          h("button", { type: "button", class: "sd-btn" + (st.picker ? " sd-btn-on" : ""), "aria-expanded": st.picker ? "true" : "false", onclick: function () { st.picker = !st.picker; st.draft = null; renderTop(); }, text: label + " ▾" }),
          btn(st.compare ? "Close compare" : "Compare", st.compare, function () { st.compare = !st.compare; st.compareData = null; renderTop(); renderBody(); }))));
      if (d && d.selection.dropped.length) top.appendChild(notice("warn", "No longer running, removed from your selection: " + d.selection.dropped.join(", ")));
      if (st.picker && d) top.appendChild(picker(d));
      if (st.compare && d) top.appendChild(compareChooser(d));
    }
    function picker(d) {
      var sel = st.draft || (d.selection.all ? d.campaigns.map(function (c) { return c.id; }) : d.selection.ids.slice());
      st.draft = sel;
      var box = h("div", { class: "sd-card sd-o-pad sd-o-stack sd-popover", role: "dialog", "aria-label": "Choose campaigns" });
      var search = h("input", { class: "sd-input", type: "search", placeholder: "Search campaigns", value: st.search, "aria-label": "Search campaigns" });
      var list = h("ul", { class: "sd-o-checklist" });
      function drawList() {
        clear(list);
        var q = st.search.toLowerCase();
        d.campaigns.filter(function (c) { return !q || c.name.toLowerCase().indexOf(q) >= 0; }).forEach(function (c) {
          var cb = h("input", { type: "checkbox", class: "sd-check", checked: sel.indexOf(c.id) >= 0 });
          cb.addEventListener("change", function () { var i = sel.indexOf(c.id); if (cb.checked && i < 0) sel.push(c.id); if (!cb.checked && i >= 0) sel.splice(i, 1); });
          list.appendChild(h("li", null, h("label", { class: "sd-o-checkrow" }, cb, h("span", { class: "sd-o-grow", text: c.name }), h("span", { class: "sd-muted sd-o-small", text: fmt(c.leads) + " leads" }))));
        });
      }
      search.addEventListener("input", function () { st.search = search.value; drawList(); });
      drawList();
      box.appendChild(search);
      box.appendChild(list);
      var set = function (arr) { sel.length = 0; arr.forEach(function (x) { sel.push(x); }); drawList(); };
      box.appendChild(h("div", { class: "sd-o-wrap" },
        btn("Select all", null, function () { set(d.campaigns.map(function (c) { return c.id; })); }),
        btn("Clear", null, function () { set([]); }),
        d.groups.map(function (g) { return btn(g.name + " (" + g.ids.length + ")", null, function () { set(g.ids); }); })));
      if (d.saved.length) box.appendChild(h("div", { class: "sd-o-stack-xs" }, h("span", { class: "sd-o-label", text: "Saved selections" }),
        h("ul", { class: "sd-o-wrap" }, d.saved.map(function (s) {
          return h("li", { class: "sd-o-wrap-xs" },
            btn(s.name + " (" + s.c.length + ")", null, function () { set(s.c); }),
            h("button", { type: "button", class: "sd-btn sd-btn-icon", "aria-label": "Delete saved selection " + s.name, onclick: function () {
              if (!window.confirm("Delete the saved selection \"" + s.name + "\"?")) return;
              api("/api/outreach/selection", { method: "POST", body: { action: "delete", id: s.id } }).then(function () { load(true).then(renderTop); });
            }, text: "×" }));
        }))));
      var nameIn = h("input", { class: "sd-input sd-o-grow", type: "text", placeholder: "Name this selection", value: st.saveName, "aria-label": "Selection name" });
      nameIn.addEventListener("input", function () { st.saveName = nameIn.value; });
      box.appendChild(h("div", { class: "sd-o-wrap" },
        h("button", { type: "button", class: "sd-btn sd-btn-on", onclick: function () { setSelection(sel.length === d.campaigns.length ? null : sel.slice()); }, text: "Apply" }),
        h("div", { class: "sd-o-wrap sd-o-grow" }, nameIn, btn("Save", null, function () {
          api("/api/outreach/selection", { method: "POST", body: { action: "save", name: st.saveName, c: sel } })
            .then(function () { st.saveName = ""; load(true).then(renderTop); }, function (e) { window.alert(e.message); });
        }))));
      return box;
    }
    function compareChooser(d) {
      return h("div", { class: "sd-card sd-o-pad sd-o-stack", role: "group", "aria-label": "Compare campaigns" },
        h("span", { class: "sd-muted sd-o-small", text: "Choose 2 or 3 campaigns to compare side by side." }),
        h("div", { class: "sd-o-wrap" }, d.campaigns.map(function (c) {
          var on = st.compareIds.indexOf(c.id) >= 0;
          var b = btn(c.name, on, function () {
            var i = st.compareIds.indexOf(c.id); if (i >= 0) st.compareIds.splice(i, 1); else st.compareIds.push(c.id);
            st.compareData = null; renderTop(); if (st.compareIds.length >= 2) loadCompare(); else renderBody();
          });
          if (!on && st.compareIds.length >= 3) b.disabled = true;
          return b;
        })));
    }

    // ---------------------------------------------------------- sections 2-6
    function tile(label, t, sub, color) {
      var delta = t.delta === 0 ? "Same as the previous period" : (t.delta > 0 ? "+" : "−") + fmt(Math.abs(t.delta)) + (t.pct !== null ? " (" + (t.pct > 0 ? "+" : "") + t.pct + "%)" : "") + " vs the previous period";
      return h("div", { class: "sd-card sd-o-tile sd-c-" + color, title: delta + " (" + fmt(t.prev) + " before)" },
        h("span", { class: "sd-muted sd-o-tile-label", text: label }), h("span", { class: "sd-o-tile-value", text: fmt(t.value) }),
        h("span", { class: "sd-muted sd-o-small", text: sub }));
    }
    function channelCard(def, c) {
      var extra = fmt(c.extra.value) + (c.extra.pct !== null ? " (" + c.extra.pct + "%)" : "");
      var barW = c.rate.rate === null ? 0 : Math.min(100, Math.round(c.rate.rate * 3));
      var rows = [[def.act, def.k === "email" ? c.delivered : c.actions], [def.resp, c.responses], ["Leads reached", c.reached], [c.extra.label, null, extra], ["Meetings from it", c.meetings]];
      return h("div", { class: "sd-card sd-o-channel sd-c-" + def.k },
        h("div", { class: "sd-o-between" }, h("span", { class: "sd-o-channel-name", text: def.name }), pill(def.short, "sd-pill-fill sd-c-" + def.k)),
        h("div", { class: "sd-o-baseline" }, h("span", { class: "sd-o-rate", text: pct(c.rate) }), h("span", { class: "sd-muted sd-o-small13", text: def.rate })),
        h("div", { class: "sd-o-bar", "aria-hidden": "true" }, h("div", { class: "sd-o-bar-fill", width: barW + "%" })),
        h("dl", { class: "sd-o-kv" }, [].concat.apply([], rows.map(function (r) { return [h("dt", { class: "sd-muted", text: r[0] }), h("dd", { text: r[2] !== undefined ? r[2] : fmt(r[1]) })]; }))));
    }
    function table(cols, rows, opts) {
      opts = opts || {};
      return h("div", { class: "sd-o-scroll" }, h("table", { class: "sd-o-table" + (opts.wide ? " sd-o-table-wide" : "") },
        h("caption", { class: "sr-only", text: opts.caption || "" }),
        h("thead", null, h("tr", null, cols.map(function (x, i) { return h("th", { scope: "col", class: i < (opts.left || 1) ? "sd-left" : "", text: x }); }))),
        h("tbody", null, rows.map(function (r) {
          return h("tr", { class: r.total ? "sd-o-total" : "" }, r.cells.map(function (x, i) {
            var cell = typeof x === "string" ? document.createTextNode(x) : x;
            return i === 0 ? h("th", { scope: "row", class: "sd-left" }, cell) : h("td", { class: i < (opts.left || 1) ? "sd-left" : "" }, cell);
          }));
        }))));
    }
    function needsCard(n) {
      var items = [];
      var lead = function (x) { return function () { openLead(x.leadId); }; };
      n.replies.forEach(function (x) { var li = String(x.type).indexOf("linkedin") === 0; items.push({ kind: li ? "LinkedIn reply" : "Email reply", c: li ? "message" : "email", text: x.name + (x.company ? " (" + x.company + ")" : "") + " replied: set an outcome", at: x.at, campaign: x.campaign, open: lead(x) }); });
      n.meetings.forEach(function (x) { items.push({ kind: "Meeting", c: "meet", text: x.name + (x.company ? " (" + x.company + ")" : "") + " booked a meeting", at: x.at, campaign: x.campaign, open: lead(x) }); });
      n.callbacks.forEach(function (x) { items.push({ kind: "Call back", c: "call", text: x.name + " asked for a call back", at: x.at, campaign: x.campaign, open: lead(x) }); });
      n.bounces.forEach(function (x) { items.push({ kind: "Bounce", c: "bnc", text: x.name + (x.company ? " (" + x.company + ")" : "") + " email bounced: check the address", at: x.at, campaign: x.campaign, open: lead(x) }); });
      n.alerts.slice(-5).forEach(function (a) { items.push({ kind: "Alert", c: "win", text: ALERTS[a.type] || a.type, at: a.at }); });
      if (n.unpushed.count) items.push({ kind: "To push", c: "win", text: fmt(n.unpushed.count) + " approved decision maker" + (n.unpushed.count === 1 ? "" : "s") + " not yet in lemlist"
        + (n.unpushed.items.length ? ": " + n.unpushed.items.slice(0, 3).map(function (x) { return x.company + " (" + x.track + ")"; }).join(", ") + (n.unpushed.count > 3 ? " …" : "") : ""), whenText: "Signal Desk" });
      var shown = items.slice(0, 12);
      return h("section", { class: "sd-card sd-o-pad sd-o-needs-card sd-o-side", "aria-label": "Needs action" },
        h("h2", { class: "sd-o-h2" }, "Needs action ", h("span", { class: "sd-badge", text: String(items.length) })),
        items.length ? null : h("span", { class: "sd-muted sd-o-small", text: "Nothing waiting. Call-back requests arrive with the Calls tab." }),
        shown.map(function (it) {
          var inner = [pill(it.kind, "sd-pill-fill sd-c-" + it.c), h("span", { class: "sd-o-stack-xxs" }, h("span", { class: "sd-o-text", text: it.text }), h("span", { class: "sd-muted sd-o-small", text: it.whenText || when(it.at) + (it.campaign ? " · " + it.campaign : "") }))];
          return it.open ? h("button", { type: "button", class: "sd-o-action", onclick: it.open }, inner) : h("div", { class: "sd-o-action" }, inner);
        }),
        items.length > shown.length ? h("span", { class: "sd-muted sd-o-small", text: "+" + (items.length - shown.length) + " more (use “Needs action only” on the lead board)" }) : null);
    }
    function funnelCard(f, label) {
      var max = Math.max.apply(null, f.map(function (x) { return x.value; }).concat([1]));
      return h("section", { class: "sd-card sd-o-pad sd-o-stack-s", "aria-label": "Funnel" },
        head("Funnel: signal to new client", label, 13),
        h("ol", { class: "sd-o-funnel" }, f.map(function (x, i) {
          var rate = i === 3 || i === 4 || x.pct === null ? "" : x.pct + "%"; // as in the mockup: no % into "Pushed" and "Reached" (different bases)
          return h("li", { class: "sd-o-funnel-row", title: x.scope === "workbook" ? "From the master sheet: counts all campaigns, not only the selected ones" : null }, h("span", { class: "sd-o-text", text: x.name }),
            h("span", { class: "sd-o-funnel-track", "aria-hidden": "true" }, h("span", { class: "sd-o-funnel-fill sd-f-" + i, width: Math.max(1, Math.round(100 * x.value / max)) + "%" })),
            h("span", { class: "sd-o-funnel-val" }, fmt(x.value) + " ", h("span", { class: "sd-muted sd-o-small", text: rate })));
        })));
    }
    function compareView(cd) {
      var rows = [["Leads", "leads"], ["Reached", "reached"], ["Email reply %", "email"], ["Invite accept %", "invite"], ["LI message reply %", "message"], ["Call connect %", "call"], ["Meetings", "meetings"], ["New jobs", "jobs"], ["Deals", "deals"], ["New clients", "clients"]];
      return h("section", { class: "sd-card sd-o-pad sd-o-stack-s sd-o-accent-border", "aria-label": "Compare campaigns" }, head("Compare", cd.period.label),
        table([""].concat(cd.campaigns.map(function (c) { return c.name; })), rows.map(function (r) {
          return { cells: [r[0]].concat(cd.campaigns.map(function (c) { var v = c[r[1]]; return v && typeof v === "object" ? rateText(v) : fmt(v); })) };
        }), { caption: "Campaigns side by side" }));
    }
    function renderBody() {
      clear(body);
      if (st.error) body.appendChild(notice("error", st.error));
      if (st.loading && !st.data) { body.appendChild(h("p", { class: "sd-o-loading" }, h("span", { class: "sd-spinner" }), " Reading lemlist data…")); return; }
      if (!st.data) return;
      var d = st.data.dashboard;
      if (!st.data.campaigns.length) { body.appendChild(notice("warn", "No running lemlist campaigns yet. They appear after the next sync (every 15 minutes).")); return; }
      var plabel = d.period.p === "today" ? "Today" : "Last " + d.period.label;
      if (st.compare && st.compareData) body.appendChild(compareView(st.compareData));
      var t = d.tiles;
      body.appendChild(h("section", { class: "sd-o-tiles", "aria-label": "Reach and results" },
        tile("Leads reached (any channel)", t.reached, "unique people", "accent"), tile("Reached by email", t.email, "delivered, not bounced", "email"),
        tile("Reached on LinkedIn", t.linkedin, "invite or message", "invite"),
        tile("Reached by phone", t.phone, "real conversations, of " + fmt(t.calls.value) + (t.calls.value === 1 ? " call" : " calls"), "call"),
        tile("Meetings", t.meetings, "from all channels", "meet"), tile("New jobs", t.jobs, "roles opened in Atlas", "win"),
        tile("Deals", t.deals, "Atlas opportunities won", "win"), tile("New clients", t.clients, "first placement or contract", "int")));
      body.appendChild(h("section", { class: "sd-o-stack-s", "aria-label": "Channels" }, head("By channel", plabel + " · each channel counted separately", 13, true),
        h("div", { class: "sd-o-channels" }, CHANNELS.map(function (def) { return channelCard(def, d.channels[def.k]); }))));
      body.appendChild(h("div", { class: "sd-o-split" },
        h("section", { class: "sd-card sd-o-pad sd-o-stack-s sd-o-main-col", "aria-label": "Results by channel" },
          head("From reach to business", d.attribution === "all_touches" ? "Credited to every channel that reached the lead" : "Credited to the channel that got the first positive response"),
          table(["Channel", "Reached", "Responded", "Meetings", "New jobs", "Deals", "New clients"], d.business.map(function (r) {
            return { total: r.channel === "Total", cells: [h("span", { class: "sd-o-dotlabel" }, dot("sd-sq sd-c-" + (BIZ_COLOR[r.channel] || "accent")), r.channel), fmt(r.reached), fmt(r.responded), fmt(r.meeting), fmt(r.job), fmt(r.deal), fmt(r.client)] };
          }), { caption: "From reach to business" }),
          h("span", { class: "sd-muted sd-o-small", text: "Meetings from lemlist and the reply-outcome buttons. New jobs, deals and clients from Atlas (roles, opportunities, placements), matched to the lead's company." })),
        needsCard(d.needs)));
      body.appendChild(funnelCard(d.funnel, plabel));
      var running = {};
      st.data.campaigns.forEach(function (c) { running[c.id] = c.running; });
      body.appendChild(h("section", { class: "sd-card sd-o-pad sd-o-stack-s", "aria-label": "Campaigns" },
        head("Running campaigns"),
        table(["Campaign", "Status", "Leads", "Email reply", "Invite accept", "LI message reply", "Call connect", "Meetings"], d.campaigns.map(function (r) {
          var tot = r.id === "total";
          return { total: tot, cells: [r.name, tot ? "" : pill(running[r.id] === false ? "Stopped" : "Running", "sd-pill-fill " + (running[r.id] === false ? "sd-c-fin" : "sd-c-int")),
            r.leads === null ? "" : fmt(r.leads), rateText(r.email), rateText(r.invite), rateText(r.message), rateText(r.call), h("span", { class: "sd-o-num-strong", text: fmt(r.meetings) })] };
        }), { caption: "Running campaigns", left: 2, wide: true }),
        h("span", { class: "sd-muted sd-o-small", text: "Each cell: rate (responses / actions). Email reply = replies ÷ delivered · Invite accept = accepted ÷ sent · LI message reply = replies ÷ messages sent · Call connect = conversations ÷ calls made." })));
    }

    // ---------------------------------------------------------- 7. live lead board
    function chips(c) {
      return h("span", { class: "sd-o-chips", role: "list", "aria-label": "Channels" }, CHANNELS.map(function (def) {
        var v = c[def.k] || "";
        var state = RESPONDED[v] ? "sd-chip-on" : NOT_YET[v] ? "sd-chip-off" : "sd-chip-done";
        if (v === "bounced" || v === "negative") state += " sd-chip-bad";
        return h("span", { role: "listitem", class: "sd-chip sd-c-" + def.k + " " + state, text: def.chip + ": " + (v || "not sent") });
      }));
    }
    function statusPill(s) { return pill(OUTREACH_STATUS_LABEL[s] || s, "sd-pill-fill sd-st-" + statusSlug(s)); }
    function select(label, value, options, onchange) {
      var s = h("select", { class: "sd-input sd-select-pill", "aria-label": label });
      options.forEach(function (o) { var op = h("option", { value: o[0], text: o[1] }); if (o[0] === value) op.selected = true; s.appendChild(op); });
      s.addEventListener("change", function () { onchange(s.value); });
      return s;
    }
    function renderBoard() {
      clear(boardBox);
      var b = st.board, d = b.data, f = b.f;
      if (!st.data || !st.data.campaigns.length) { boardBox.classList.add("sd-hidden"); return; }
      boardBox.classList.remove("sd-hidden");
      var setF = function (k, v) { f[k] = v; b.page = 1; loadBoard(); };
      var all = d ? Object.keys(d.byStatus).reduce(function (s, k) { return s + d.byStatus[k]; }, 0) : 0;
      boardBox.appendChild(h("div", { class: "sd-o-board-head" },
        h("h2", { class: "sd-o-h2" }, "Live lead board ", h("span", { class: "sd-muted sd-o-h2-sub", text: d ? "· " + fmt(d.total) + " of " + fmt(all) + " running leads" : "" })),
        h("div", { class: "sd-o-group", role: "group", "aria-label": "View" },
          btn("Table", b.view === "table", function () { b.view = "table"; renderBoard(); }),
          btn("Board by stage", b.view === "board", function () { b.view = "board"; renderBoard(); }))));
      var search = h("input", { class: "sd-input sd-o-search", type: "search", placeholder: "Search name or company", value: f.q, "aria-label": "Search leads" });
      var tmr = null;
      search.addEventListener("input", function () { clearTimeout(tmr); tmr = setTimeout(function () { setF("q", search.value); }, 300); });
      var allChip = btn("All ", !f.status, function () { setF("status", ""); });
      allChip.setAttribute("aria-label", "All statuses");
      allChip.appendChild(h("span", { class: "sd-count", text: fmt(all) }));
      var statusChips = [allChip];
      if (d) Object.keys(d.byStatus).forEach(function (s) {
        if (!d.byStatus[s] && f.status !== s) return;
        var c = btn(s + " ", f.status === s, function () { setF("status", f.status === s ? "" : s); });
        c.appendChild(h("span", { class: "sd-count", text: fmt(d.byStatus[s]) }));
        statusChips.push(c);
      });
      var extra = ["campaign", "channel", "track", "group"].filter(function (k) { return f[k]; }).length + (f.needs ? 1 : 0);
      var more = h("button", { type: "button", class: "sd-btn" + (b.more || extra ? " sd-btn-on" : ""), "aria-expanded": b.more ? "true" : "false",
        onclick: function () { b.more = !b.more; renderBoard(); }, text: "More filters" + (extra ? " (" + extra + ")" : "") + " ▾" });
      boardBox.appendChild(h("div", { class: "sd-o-wrap" }, search, statusChips, more));
      var camps = st.data.campaigns.filter(function (c) { return st.data.selection.ids.indexOf(c.id) >= 0; });
      var needs = h("input", { type: "checkbox", class: "sd-check", checked: f.needs });
      needs.addEventListener("change", function () { setF("needs", needs.checked); });
      if (b.more) boardBox.appendChild(h("div", { class: "sd-card sd-o-filters sd-o-wrap", role: "group", "aria-label": "More filters" },
        select("Campaign", f.campaign, [["", "All selected campaigns"]].concat(camps.map(function (c) { return [c.id, c.name]; })), function (v) { setF("campaign", v); }),
        select("Channel state", f.channel, [["", "Any channel state"], ["email:replied", "Email replied"], ["email:bounced", "Email bounced"], ["email:sent", "Email sent, no reply"],
          ["invite:accepted", "Invite accepted"], ["invite:pending", "Invite pending"], ["message:replied", "LinkedIn replied"], ["message:sent", "LinkedIn sent, no reply"],
          ["call:positive", "Call positive"], ["call:answered", "Call answered"], ["call:no answer", "Call no answer"], ["call:not called", "Not called yet"]], function (v) { setF("channel", v); }),
        select("IT or HR", f.track, [["", "IT and HR"], ["IT", "IT signals"], ["HR", "HR signals"]], function (v) { setF("track", v); }),
        select("Role group", f.group, [["", "All role groups"]].concat((d ? d.roleGroups : []).map(function (g) { return [g, g]; })), function (v) { setF("group", v); }),
        h("label", { class: "sd-o-checkrow sd-pill-box" }, needs, "Needs action only"),
        extra ? btn("Clear filters", null, function () { ["campaign", "channel", "track", "group"].forEach(function (k) { f[k] = ""; }); f.needs = false; b.page = 1; loadBoard(); }) : null));
      if (b.loading && !d) { boardBox.appendChild(h("p", { class: "sd-o-loading" }, h("span", { class: "sd-spinner" }), " Loading leads…")); return; }
      if (!d) return;
      if (b.view === "board") {
        var cols = d.board.filter(function (c) { return c.count; });
        boardBox.appendChild(h("div", { class: "sd-o-scroll" }, h("div", { class: "sd-o-board" }, cols.map(function (col) {
          return h("div", { class: "sd-o-board-col" },
            h("div", { class: "sd-o-between" }, pill(col.status, "sd-pill-fill sd-st-" + statusSlug(col.status)), h("span", { class: "sd-o-board-count", text: fmt(col.count) })),
            col.cards.map(function (c) {
              return h("button", { type: "button", class: "sd-o-card-btn", onclick: function () { openLead(c.id); } },
                h("span", { class: "sd-o-strong", text: c.name }), h("span", { class: "sd-muted sd-o-small", text: [c.company, c.track].filter(Boolean).join(" · ") }),
                h("span", { class: "sd-o-small", text: c.level }), c.last ? h("span", { class: "sd-muted sd-o-xsmall", text: c.last + " · " + when(c.at) }) : null);
            }),
            col.count > col.cards.length ? h("span", { class: "sd-muted sd-o-xsmall", text: "+" + (col.count - col.cards.length) + " more (use the table)" }) : null);
        }))));
        return;
      }
      if (!d.rows.length) { boardBox.appendChild(h("p", { class: "sd-muted", text: "No leads match these filters." })); return; }
      boardBox.appendChild(h("div", { class: "sd-o-scroll sd-o-framed" }, h("div", { class: "sd-o-rows" },
        h("div", { class: "sd-row sd-row-head", "aria-hidden": "true" }, ["Lead", "Company · job signal", "Campaign", "Level", "Channels", "Last activity", "Status"].map(function (x) { return h("span", { text: x }); })),
        d.rows.map(function (r) {
          var l = r.level;
          return h("button", { type: "button", class: "sd-lead", "aria-label": "Open timeline for " + r.name, onclick: function () { openLead(r.id); } },
            h("span", { class: "sd-row" },
              h("span", { class: "sd-o-cell" }, h("span", { class: "sd-o-strong", text: r.name }), h("span", { class: "sd-muted sd-o-small", text: r.title || "" })),
              h("span", { class: "sd-o-cell" }, h("span", { class: "sd-o-text", text: r.company }),
                h("span", { class: "sd-o-signal", text: r.jobTitle ? (r.track || "IT") + " · " + r.jobTitle : "no approved signal" })),
              h("span", { class: "sd-o-small", text: r.campaign }),
              h("span", { class: "sd-o-cell sd-o-cell-gap" }, h("span", { class: "sd-o-strong", text: l.label }),
                h("span", { class: "sd-o-progress", "aria-hidden": "true" }, h("span", { width: (l.total ? Math.round(100 * l.n / l.total) : 0) + "%" }))),
              chips(r.channels),
              h("span", { class: "sd-o-cell" }, h("span", { class: "sd-o-text", text: r.lastActivity ? r.lastActivity.label : "—" }), h("span", { class: "sd-muted sd-o-small", text: r.lastActivity ? when(r.lastActivity.at) : "" })),
              h("span", { class: "sd-o-cell" }, statusPill(r.status), r.needsAction ? h("span", { class: "sd-o-needs", text: "needs action" }) : null)));
        }))));
      boardBox.appendChild(h("span", { class: "sd-muted sd-o-small", text: "Channel chips: filled = responded (replied, accepted, call answered) · outlined = done, no response yet · faded = not reached on that channel yet." }));
      if (d.pages > 1) boardBox.appendChild(h("div", { class: "sd-o-between" },
        h("button", { type: "button", class: "sd-btn", disabled: d.page <= 1, onclick: function () { b.page = d.page - 1; loadBoard(); }, text: "← Previous" }),
        h("span", { class: "sd-muted sd-o-small", text: "Page " + d.page + " of " + d.pages }),
        h("button", { type: "button", class: "sd-btn", disabled: d.page >= d.pages, onclick: function () { b.page = d.page + 1; loadBoard(); }, text: "Next →" })));
    }

    // ---------------------------------------------------------- lead timeline panel (below the board, as in the mockup)
    var TL = { signal: "sd-f-0", approval: "sd-f-2", pushed: "sd-f-3", email: "sd-c-email", invite: "sd-c-invite", message: "sd-c-message", call: "sd-c-call", meeting: "sd-c-meet", other: "sd-c-muted" };
    // repeated events in a row (e.g. 30 "Email opened") become one line "Email opened ×30"
    function collapseTimeline(list) {
      var out = [];
      list.forEach(function (e) {
        var prev = out[out.length - 1];
        if (prev && prev.label === e.label && prev.detail === e.detail && prev.kind === e.kind) { prev.n++; prev.last = e.at; }
        else out.push({ at: e.at, last: e.at, label: e.label, detail: e.detail, kind: e.kind, n: 1 });
      });
      return out;
    }
    function renderPanel(scroll) {
      clear(panel);
      if (!st.lead) return;
      var l = st.lead;
      var close = function () { st.lead = null; renderPanel(false); };
      var box = h("section", { class: "sd-card sd-o-tl", "aria-label": "Lead timeline", tabindex: "-1" });
      var links = [];
      if (l.timeline) {
        var li = safeHref(l.linkedin), lem = safeHref(l.lemlistUrl), job = safeHref(l.signal && l.signal.jobUrl);
        if (li) links.push(h("a", { href: li, target: "_blank", rel: "noopener noreferrer", class: "sd-btn sd-btn-link", text: "LinkedIn" }));
        if (lem) links.push(h("a", { href: lem, target: "_blank", rel: "noopener noreferrer", class: "sd-btn sd-btn-link", text: "Open in lemlist" }));
      }
      var sigLine = l.timeline ? (l.signal.jobTitle ? "From " + (l.signal.track || "IT") + " signal: " + l.signal.jobTitle : "No approved signal linked")
        + " · Atlas: " + (l.atlas ? (l.atlas.matching !== null ? fmt(l.atlas.matching) + " matching titles" : fmt(l.atlas.pool) + " in pool") : "no pool") : "";
      box.appendChild(h("div", { class: "sd-o-tl-head" },
        h("div", { class: "sd-o-stack-xxs sd-o-gap4" }, h("h2", { class: "sd-o-h2 sd-o-h2-lg", text: l.name || "Lead" }),
          h("span", { class: "sd-muted sd-o-text", text: [l.title, l.company].filter(Boolean).join(" · ") }),
          l.timeline ? h("span", { class: "sd-o-signal sd-o-signal-lg", text: sigLine }) : null),
        h("div", { class: "sd-o-wrap" }, links, btn("Close", null, close))));
      if (st.leadLoading) box.appendChild(h("p", { class: "sd-o-loading" }, h("span", { class: "sd-spinner" }), " Loading…"));
      if (l.error) box.appendChild(notice("error", l.error));
      if (l.timeline) {
        box.appendChild(h("div", { class: "sd-o-tl-body" },
          h("ol", { class: "sd-o-timeline" }, collapseTimeline(l.timeline).map(function (e) {
            return h("li", null, h("span", { class: "sd-muted sd-o-small", text: when(e.at) + (e.n > 1 && when(e.last) !== when(e.at) ? " – " + when(e.last) : "") }), dot("sd-tl-dot " + (TL[e.kind] || TL.other)),
              h("span", { class: "sd-o-text", text: e.label + (e.n > 1 ? " ×" + e.n : "") + (e.detail ? " · " + e.detail : "") }));
          })),
          h("div", { class: "sd-o-tl-side" }, h("span", { class: "sd-o-strong", text: "Outcome" }),
            h("div", { class: "sd-o-wrap" }, l.outcomes.map(function (o) {
              var on = !!(l.outcome && l.outcome.outcome === o);
              var b2 = btn(o, on, function () { setOutcome(on ? null : o); });
              if (on) b2.title = "Set by " + l.outcome.by_user + " " + ago(l.outcome.at) + ". Click again to clear.";
              return b2;
            })),
            h("span", { class: "sd-muted sd-o-small", text: "Saved in Signal Desk; counts in \"From reach to business\". New job, deal and client are confirmed from Atlas when they exist there." }))));
      }
      panel.appendChild(box);
      if (scroll) { box.scrollIntoView({ behavior: "smooth", block: "start" }); box.focus({ preventScroll: true }); }
    }

    document.addEventListener("keydown", function (ev) {
      if (ev.key !== "Escape") return;
      if (st.picker) { st.picker = false; st.draft = null; renderTop(); } else if (st.lead) { st.lead = null; renderPanel(false); }
    });
    renderTop(); renderBody();
    load();
    setInterval(function () {
      var a = document.activeElement;
      var typing = a && app.contains(a) && (a.tagName === "INPUT" || a.tagName === "SELECT" || a.tagName === "TEXTAREA");
      if (!document.hidden && !st.picker && !typing) load(true);
    }, 60000);
  }

  // ================================================================ Lists page (Atlas list map)
  // Families and their Atlas lists, with "New – please review" for auto-created ones. Edits only change
  // storage/atlas-list-map.json on our server, never Atlas.
  function listsPage() {
    var st = { view: null, loading: true, error: null, notice: null, refreshing: false, onlyReview: false, indexing: false };
    var header = h("section", { class: "space-y-3 rounded-2xl border border-line bg-card/80 p-3" });
    var msgs = h("div", { class: "space-y-2" });
    var list = h("ul", { class: "space-y-3" });
    clear(app);
    if (!atlasOn) { app.appendChild(notice("warn", "Atlas is not configured. Add ATLAS_API_KEY to config.php (see DEPLOY-20I.md).")); return; }
    app.appendChild(h("div", { class: "space-y-4" }, header, msgs, list));

    function load() {
      st.loading = true; render();
      return api("/api/atlas/map").then(function (v) { st.view = v; st.error = null; }, function (e) { st.error = e.message; })
        .then(function () { st.loading = false; render(); });
    }
    function when(iso) { return iso ? iso.replace("T", " ").slice(0, 16) + " UTC" : "never"; }
    function refresh() {
      st.refreshing = true; st.notice = null; render();
      api("/api/atlas/refresh", { method: "POST", body: {} })
        .then(function (r) {
          st.notice = { kind: "ok", text: "Refreshed from Atlas: " + r.cleared + " cached file(s) cleared" + (r.changes.length ? "; changes:\n• " + r.changes.join("\n• ") : "; no list changes.") };
          return load();
        }, function (e) { st.notice = { kind: e.code === "REFRESH_TOO_SOON" ? "warn" : "error", text: e.message }; })
        .then(function () { st.refreshing = false; render(); });
    }

    // "Rebuild index": starts a build (at most hourly) and keeps it going in ~20 s steps while this page is open.
    function rebuild(action) {
      st.indexing = true; st.notice = null; render();
      api("/api/atlas/index", { method: "POST", body: { action: action } })
        .then(function (r) {
          if (st.view) st.view.index = r;
          if (r.result === "done") { st.notice = { kind: "ok", text: "Title index built: " + fmt(r.entries) + " people." }; st.indexing = false; render(); return; }
          if (r.state === "running") { render(); setTimeout(function () { rebuild("continue"); }, r.result === "paused" ? 20000 : 300); return; }
          st.indexing = false; render();
        }, function (e) { st.notice = { kind: e.code === "REBUILD_TOO_SOON" ? "warn" : "error", text: e.message }; st.indexing = false; render(); });
    }
    function indexLine(ix) {
      if (!ix) return null;
      var text = ix.state === "running"
        ? "Title index: building… " + (ix.phase === "members" ? "reading pool members (" + ix.poolsScanned + " of " + ix.pools + " pools)" : fmt(ix.done) + " of " + fmt(ix.total) + " people")
        : st.indexing ? "Title index: starting… (reading the pools takes a moment)"
        : ix.builtAt ? "Title index: " + fmt(ix.entries) + " people · built " + when(ix.builtAt) : "Title index: not built yet";
      return h("div", { class: "flex flex-wrap items-center gap-2 text-sm text-white/70" },
        st.indexing ? h("span", { class: "sd-spinner" }) : null, h("span", { text: text }),
        h("button", { type: "button", class: "sd-btn-secondary", disabled: st.indexing, onclick: function () { rebuild(ix.state === "running" ? "continue" : "start"); },
          text: ix.state === "running" ? (st.indexing ? "Building…" : "Continue building") : "Rebuild index" }));
    }

    function renderHeader() {
      clear(header);
      var v = st.view;
      var toReview = v ? v.families.filter(function (f) { return !f.reviewed; }).length : 0;
      header.appendChild(h("div", { class: "flex flex-wrap items-start justify-between gap-2" },
        h("div", null, h("h1", { class: "text-lg font-semibold", text: "Atlas lists" }),
          h("p", { class: "text-sm text-white/70", text: v ? v.families.length + " families · " + toReview + " to review · last check " + when(v.discoveredAt) : "Loading…" })),
        h("button", { type: "button", class: "sd-btn-secondary", disabled: st.refreshing, onclick: refresh, text: st.refreshing ? "Refreshing…" : "Refresh from Atlas" })));
      header.appendChild(h("p", { class: "text-xs text-white/50", text: "New Atlas lists are picked up automatically (at most once an hour). Editing here changes only Signal Desk's matching rules (storage/atlas-list-map.json), never Atlas." }));
      if (v) header.appendChild(indexLine(v.index));
      if (toReview) header.appendChild(h("label", { class: "flex items-center gap-2 text-sm text-white/80" },
        h("input", { type: "checkbox", class: "h-5 w-5", checked: st.onlyReview, onchange: function (e) { st.onlyReview = e.target.checked; renderList(); } }), "Only families to review"));
    }
    function renderMsgs() {
      clear(msgs);
      if (st.notice) msgs.appendChild(notice(st.notice.kind, st.notice.text, function () { st.notice = null; renderMsgs(); }));
      if (st.error) msgs.appendChild(notice("error", st.error));
      if (st.loading && !st.view) msgs.appendChild(h("p", { class: "flex items-center gap-2 text-sm text-white/70" }, h("span", { class: "sd-spinner" }), " Reading Atlas lists…"));
    }

    function familyCard(f) {
      var kw = h("textarea", { class: fieldClass, rows: "4", "aria-label": "Keywords for " + f.name });
      kw.value = f.keywords.join("\n");
      var sg = h("input", { class: fieldClass, type: "text", value: f.roleSubGroups.join(", "), placeholder: "e.g. A4, G6", "aria-label": "Role Sub-Groups for " + f.name });
      var msg = h("div");
      var save = h("button", { type: "button", class: "rounded-xl bg-pink px-4 py-2 text-sm font-semibold text-white hover:brightness-110 disabled:opacity-50", text: f.reviewed ? "Save" : "Save & mark reviewed" });
      save.addEventListener("click", function () {
        save.disabled = true; clear(msg);
        var body = { family: f.name, keywords: kw.value.split(/\n|,/), roleSubGroups: sg.value.split(/,|\n/) };
        api("/api/atlas/map", { method: "POST", body: body }).then(function (r) {
          f.keywords = r.keywords; f.roleSubGroups = r.roleSubGroups; f.reviewed = true;
          kw.value = r.keywords.join("\n"); sg.value = r.roleSubGroups.join(", ");
          msg.appendChild(h("p", { class: "text-sm font-medium text-ok", role: "status", text: "✓ Saved" }));
          save.textContent = "Save"; renderHeader();
          var badge = card.querySelector(".sd-review-badge"); if (badge) badge.remove();
        }, function (e) { msg.appendChild(notice("error", e.message)); }).then(function () { save.disabled = false; });
      });
      var card = h("li", { class: "rounded-2xl border p-3 " + (f.reviewed ? "border-line bg-card" : "border-warn/50 bg-card-2") },
        h("div", { class: "flex flex-wrap items-start justify-between gap-2" },
          h("h3", { class: "font-semibold leading-snug", text: f.name }),
          h("div", { class: "flex flex-wrap gap-1" },
            !f.reviewed ? h("span", { class: "sd-review-badge", text: "New – please review" }) : null,
            f.auto ? chip("auto") : null)),
        h("ul", { class: "mt-2 space-y-1 text-sm" }, f.lists.map(function (l) {
          return h("li", { class: "flex flex-wrap items-center gap-1.5" },
            l.type !== "pool" ? chip(l.type, l.type === "permanent" ? "pink" : null) : null,
            h("span", { class: l.missing ? "text-warn" : "text-white/85", text: l.name }),
            l.missing ? h("span", { class: "sd-review-badge", text: "missing in Atlas" }) : h("span", { class: "text-xs text-white/50", text: (l.count === null ? "?" : l.count) + " members" }),
            l.auto ? h("span", { class: "text-xs text-white/40", text: "added automatically " + (l.addedAt || "") }) : null);
        })),
        h("details", { class: "group mt-2" },
          h("summary", { class: "text-sm text-white/60 hover:text-white" },
            h("span", { class: "group-open:hidden", text: "▸ Matching rules (" + f.keywords.length + " keywords, " + f.roleSubGroups.length + " sub-groups)" }),
            h("span", { class: "hidden group-open:inline", text: "▾ Matching rules" })),
          h("div", { class: "mt-2 space-y-2" },
            h("label", { class: "block text-sm" }, h("span", { class: "mb-1 block text-xs uppercase tracking-wide text-white/50", text: "Title keywords (one per line, whole words)" }), kw),
            h("label", { class: "block text-sm" }, h("span", { class: "mb-1 block text-xs uppercase tracking-wide text-white/50", text: "Role Sub-Groups (codes like A4 or full names, comma-separated)" }), sg),
            h("div", { class: "flex flex-wrap items-center gap-2" }, save, msg))));
      if (!f.reviewed) card.querySelector("details").setAttribute("open", "");
      return card;
    }
    function renderList() {
      clear(list);
      var v = st.view;
      if (!v) return;
      var fams = v.families.slice().sort(function (a, b) { return (a.reviewed === b.reviewed) ? 0 : a.reviewed ? 1 : -1; });
      fams.filter(function (f) { return !st.onlyReview || !f.reviewed; }).forEach(function (f) { list.appendChild(familyCard(f)); });
      if (v.ignoredLists.length) list.appendChild(h("li", { class: "text-xs text-white/50", text: "Ignored lists (not role families): " + v.ignoredLists.map(function (l) { return l.name; }).join("; ") }));
      if ((v.retiredLists || []).length) list.appendChild(h("li", { class: "text-xs text-white/50" }, h("details", null,
        h("summary", { text: "▸ Retired lists (" + v.retiredLists.length + "): the old Permanent/Freelance lists, no longer used" }),
        h("p", { class: "mt-1", text: v.retiredLists.map(function (l) { return l.name; }).join("; ") }))));
    }
    function render() { renderHeader(); renderMsgs(); renderList(); }
    load().then(function () { if (st.view && st.view.index && st.view.index.state === "running" && !st.indexing) rebuild("continue"); });
  }

  if (page === "signals") signalsPage();
  else if (page === "verified") verifiedPage();
  else if (page === "hr") verifiedPage(HR_CFG);
  else if (page === "dashboard") outreachPage();
  else if (page === "calls" || page === "manual" || page === "inbox") callsPage(page);
  else if (page === "candidates") candidatesPage();
  else if (page === "lists") listsPage();
  if (page !== "calls" && page !== "manual" && page !== "inbox") refreshInboxBadge();
  (function () { // phone: the top tabs scroll sideways inside the bar; bring the active one into view (the page itself does not move)
    var nav = document.querySelector(".sd-top-nav"), on = nav && nav.querySelector(".sd-top-tab-on");
    if (nav && on && nav.scrollWidth > nav.clientWidth) { var r = on.getBoundingClientRect(), n = nav.getBoundingClientRect(); nav.scrollLeft += r.left - n.left - (nav.clientWidth - r.width) / 2; }
  })();
  liveStatus();
  setInterval(function () { liveStatus(); }, 10000);
})();
