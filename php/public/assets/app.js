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
    return "/candidates" + (q.length ? "?" + q.join("&") : "") + (jobUrl ? "#jd" : "");
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
  function verifiedPage() {
    var st = { jobs: null, warnings: [], loading: true, error: null, busy: null, notice: null, filter: "waiting", search: "", sticky: {} };
    var header = h("section", { class: "space-y-3 rounded-2xl border border-line bg-card/80 p-3" });
    var msgs = h("div", { class: "space-y-2" });
    var list = h("div", { class: "space-y-4" });
    clear(app);
    app.appendChild(h("div", { class: "space-y-4" }, header, msgs, list));

    function cardId(c) { return c.row + "|" + c.jobUrl; }
    function load() {
      st.loading = true; st.error = null; render();
      return api("/api/verified", { onBusy: function (n) { st.busy = busyText(n); renderMsgs(); } })
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
      header.appendChild(h("div", null, h("h1", { class: "text-lg font-semibold", text: "Signals to verify" }),
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
        if (!v.links.length && card.decisionMakers.length && !window.confirm("Clear the LinkedIn cell (column M) on verify row " + card.row + "?")) return;
        saveBtn.disabled = true; saveBtn.textContent = "Saving…"; clear(msg);
        api("/api/linkedin", { method: "POST", body: Object.assign({ links: v.links }, body), onBusy: function (n) { clear(msg); if (n) msg.appendChild(notice("busy", busyText(n))); } })
          .then(function (res) {
            st.sticky[cardId(card)] = true;
            updateCard(card.row, { decisionMakers: res.links, status: res.links.length ? "ready" : "waiting", canUndo: false, savedRow: res.row, sentDate: null });
            renderList();
          }, function (e) { saveBtn.disabled = false; saveBtn.textContent = "Save"; changedError(e, msg); });
      }
      function approve() {
        if (!window.confirm("Approve " + (card.dmName || "this decision maker") + " for " + job.company + " and add them to the \"Decision Makers\" tab?")) return;
        clear(approveBox); approveBox.appendChild(h("p", { class: "flex items-center gap-2 text-sm text-white/70" }, h("span", { class: "sd-spinner" }), " Adding to Decision Makers…"));
        api("/api/approve", { method: "POST", body: body, onBusy: function (n) { clear(msg); if (n) msg.appendChild(notice("busy", busyText(n))); } })
          .then(function (res) {
            st.sticky[cardId(card)] = true;
            st.jobs.forEach(function (j) { j.cards.forEach(function (c) { c.canUndo = false; }); });
            updateCard(card.row, { status: "sent", sentDate: res.sentDate, canUndo: res.status === "appended", approvedNote: res.status === "already" ? "already" : "new", approveError: null });
            renderList();
          }, function (e) { st.sticky[cardId(card)] = true; updateCard(card.row, { approveError: e.message, approveCode: e.code }); renderList(); });
      }
      function undo() {
        if (!window.confirm("Undo: remove the row just added to \"Decision Makers\" for " + (card.dmName || job.company) + "?")) return;
        api("/api/undo-approve", { method: "POST", body: body, onBusy: function (n) { clear(msg); if (n) msg.appendChild(notice("busy", busyText(n))); } })
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
          card.approvedNote === "already" ? h("span", { class: "text-xs text-white/60", text: "(already in Decision Makers, nothing added)" }) : null,
          card.canUndo ? h("button", { type: "button", class: "sd-btn-secondary", onclick: undo, text: "Undo" }) : null));
      } else if (card.status === "ready" && editable) {
        approveBox.appendChild(h("button", { type: "button", class: "sd-btn-approve", onclick: approve, text: "✓ Approve → Decision Makers" }));
      }
      if (card.approvedNote === "undone") approveBox.appendChild(h("p", { class: "mt-2 text-sm text-ok", text: "Undone: the row was removed from Decision Makers." }));
      if (card.approveError) changedError({ message: card.approveError, code: card.approveCode }, approveBox.appendChild(h("div", { class: "mt-2" })));

      var badge = card.status === "sent" ? chip("✓ Sent", "ok") : card.status === "ready" ? chip("Ready to review", "pink") : chip("Waiting for decision maker");
      return h("li", { class: "sd-dm-card " + (card.status === "sent" ? "border-ok/40" : card.status === "ready" ? "border-pink/50" : "border-line") },
        h("div", { class: "flex items-start justify-between gap-2" },
          h("div", { class: "min-w-0" },
            h("h4", { class: "font-semibold leading-snug", text: card.dmName || ("Decision maker " + (index + 1)) }),
            h("p", { class: "text-xs text-white/50", text: (card.dmTitle ? card.dmTitle + " · " : "") + "verify row " + card.row })),
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
        api("/api/add-decision-maker", { method: "POST", body: { row: job.firstRow, jobUrl: job.jobUrl, link: r.url }, onBusy: function (n) { clear(addMsg); if (n) addMsg.appendChild(notice("busy", busyText(n))); } })
          .then(function (res) {
            st.sticky[res.row + "|" + job.jobUrl] = true;
            st.notice = { kind: "ok", text: "Added a decision maker for " + job.company + " (verify row " + res.row + ")." };
            return load();
          }, function (e) { addBtn.disabled = false; addBtn.textContent = "Add"; changedError(e, addMsg); });
      });
      var addForm = job.key ? h("details", { class: "group mt-3" },
        h("summary", { class: "text-sm font-medium text-pink-soft hover:text-white", text: "+ Add another decision maker" }),
        h("div", { class: "mt-2 space-y-2" }, h("div", { class: "flex gap-2" }, addInput, addBtn),
          h("p", { class: "text-xs text-white/50", text: "Adds a new row to the verify tab for this job, with this LinkedIn in column M. Name, title and note (S/T/U) are filled in Excel." }),
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
  else if (page === "candidates") candidatesPage();
  else if (page === "lists") listsPage();
})();
