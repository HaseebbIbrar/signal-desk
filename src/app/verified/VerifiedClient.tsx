"use client";
import { useCallback, useEffect, useMemo, useState } from "react";
import { Chip, DetailList, fieldClass, Notice } from "@/components/ui";
import { apiFetch, busyText } from "@/lib/client-api";
import { MAX_LINKS_PER_CELL, validateLinks } from "@/lib/linkedin";
import { safeHref } from "@/lib/safe-href";
import type { SaveDecisionMakerResult, VerifiedRowDTO, VerifiedView } from "@/lib/types";

type Filter = "all" | "missing" | "done";

export default function VerifiedClient({ csrf }: { csrf: string }) {
  const [rows, setRows] = useState<VerifiedRowDTO[] | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [filter, setFilterState] = useState<Filter>("missing");
  const [search, setSearch] = useState("");
  // Rows saved during this visit stay visible under the current filter, so the green
  // "Saved to master sheet" confirmation is seen instead of the row vanishing.
  const [justSaved, setJustSaved] = useState<Set<string>>(new Set());

  function setFilter(f: Filter) {
    setFilterState(f);
    setJustSaved(new Set());
  }

  const onBusy = useCallback((n: number) => setBusy(busyText(n)), []);

  const load = useCallback(async () => {
    setLoading(true);
    setLoadError(null);
    try {
      setRows((await apiFetch<VerifiedView>("/api/verified", { onBusy })).rows);
    } catch (e) {
      setLoadError(e instanceof Error ? e.message : String(e));
    } finally {
      setLoading(false);
    }
  }, [onBusy]);

  useEffect(() => {
    void load();
  }, [load]);

  const all = rows ?? [];
  const missingCount = all.filter((r) => !r.hasDecisionMaker).length;
  const doneCount = all.length - missingCount;

  const groups = useMemo(() => {
    const q = search.trim().toLowerCase();
    const shown = all.filter((r) => {
      const sticky = justSaved.has(r.key);
      if (!sticky && filter === "missing" && r.hasDecisionMaker) return false;
      if (!sticky && filter === "done" && !r.hasDecisionMaker) return false;
      if (q && ![r.company, r.jobTitle, r.location].join(" ").toLowerCase().includes(q)) return false;
      return true;
    });
    const map = new Map<string, VerifiedRowDTO[]>();
    for (const r of shown) {
      const k = r.dateFound || "No date";
      map.set(k, [...(map.get(k) ?? []), r]);
    }
    return [...map.entries()];
  }, [all, filter, search, justSaved]);

  function onSaved(key: string, links: string[]) {
    setJustSaved((s) => new Set(s).add(key));
    setRows((rs) => rs && rs.map((r) => (r.key === key ? { ...r, decisionMakers: links, hasDecisionMaker: links.length > 0 } : r)));
  }

  const tab = (f: Filter, label: string, n: number) => (
    <button
      type="button"
      onClick={() => setFilter(f)}
      aria-pressed={filter === f}
      className={`rounded-full border px-3 py-1.5 text-sm ${filter === f ? "border-pink bg-pink/20 text-white" : "border-line text-white/70 hover:text-white"}`}
    >
      {label} <span className="text-white/60">{n}</span>
    </button>
  );

  return (
    <div className="space-y-4">
      <section className="space-y-3 rounded-2xl border border-line bg-card/80 p-3">
        <div>
          <h1 className="text-lg font-semibold">Signals to verify</h1>
          <p className="text-sm text-white/70">
            {rows ? (
              <>
                <strong className="text-pink-soft">{missingCount}</strong> still need a decision maker · <strong className="text-ok">{doneCount}</strong> done
              </>
            ) : (
              "Loading…"
            )}
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          {tab("missing", "Missing decision maker", missingCount)}
          {tab("done", "Done", doneCount)}
          {tab("all", "All", all.length)}
        </div>
        <input className={fieldClass} type="search" placeholder="Search company, title, location…" value={search} onChange={(e) => setSearch(e.target.value)} />
      </section>

      {busy && <Notice kind="busy">{busy}</Notice>}
      {loadError && (
        <Notice kind="error">
          {loadError}{" "}
          <button type="button" className="underline" onClick={() => void load()}>
            Retry
          </button>
        </Notice>
      )}
      {loading && !rows && (
        <p className="flex items-center gap-2 text-sm text-white/70">
          <span className="sd-spinner" /> Reading the master sheet…
        </p>
      )}
      {rows && groups.length === 0 && <p className="text-sm text-white/60">Nothing to show for this filter.</p>}

      {groups.map(([date, items]) => (
        <section key={date} className="space-y-2">
          <h2 className="sticky top-[6.25rem] z-10 -mx-1 rounded-lg bg-black/60 px-2 py-1 text-xs font-semibold uppercase tracking-wider text-white/70 backdrop-blur">
            {date} · {items.length}
          </h2>
          <ul className="space-y-3">
            {items.map((r) => (
              <VerifiedCard key={`${r.rowNumber}-${r.key}`} row={r} csrf={csrf} onSaved={onSaved} />
            ))}
          </ul>
        </section>
      ))}
    </div>
  );
}

function VerifiedCard({ row, csrf, onSaved }: { row: VerifiedRowDTO; csrf: string; onSaved: (key: string, links: string[]) => void }) {
  const [links, setLinks] = useState<string[]>(row.decisionMakers.length ? row.decisionMakers : [""]);
  const [errors, setErrors] = useState<(string | null)[]>([]);
  const [state, setState] = useState<"idle" | "saving" | "saved" | "error">("idle");
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const href = safeHref(row.jobUrl);
  const editable = Boolean(row.key) && !row.duplicate;

  const normalisedCurrent = links.map((s) => s.trim()).filter(Boolean);
  const dirty = JSON.stringify(normalisedCurrent) !== JSON.stringify(row.decisionMakers);

  function update(i: number, v: string) {
    setLinks((ls) => ls.map((x, j) => (j === i ? v : x)));
    setErrors((es) => es.map((x, j) => (j === i ? null : x)));
    if (state !== "saving") setState("idle");
  }

  async function save() {
    const v = validateLinks(links);
    if (v.errors.some(Boolean)) {
      setErrors(v.errors);
      return;
    }
    if (v.tooMany) {
      setState("error");
      setMessage(`At most ${MAX_LINKS_PER_CELL} links per row.`);
      return;
    }
    if (v.links.length === 0 && row.decisionMakers.length > 0 && !window.confirm(`Clear the Decision Maker LinkedIn cell for ${row.company}?`)) return;

    setState("saving");
    setMessage(null);
    try {
      const res = await apiFetch<SaveDecisionMakerResult>("/api/verified/decision-maker", {
        method: "POST",
        body: { jobUrl: row.jobUrl, links: v.links },
        csrf,
        onBusy: (n) => setBusy(busyText(n)),
      });
      setLinks(res.links.length ? res.links : [""]);
      setErrors([]);
      onSaved(row.key, res.links);
      setState("saved");
      setMessage(`Row ${res.row}`);
    } catch (e) {
      setState("error");
      setMessage(e instanceof Error ? e.message : String(e));
    }
  }

  return (
    <li className={`rounded-2xl border p-3 ${row.hasDecisionMaker ? "border-ok/40 bg-card" : "border-pink/50 bg-card-2"}`}>
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <h3 className="font-semibold leading-snug">{row.company || "(no company)"}</h3>
          <p className="text-sm text-white/85">{row.jobTitle}</p>
        </div>
        {row.hasDecisionMaker ? <Chip>✓ Done</Chip> : <Chip tone="pink">Needs decision maker</Chip>}
      </div>
      <div className="mt-1.5 flex flex-wrap items-center gap-1.5 text-sm">
        {row.roleGroup && <Chip tone="pink">Group {row.roleGroup}</Chip>}
        {row.companyType && <Chip>{row.companyType}</Chip>}
        {row.location && <Chip>{row.location}</Chip>}
        {href && (
          <a href={href} target="_blank" rel="noopener noreferrer" className="ml-1 font-medium text-pink-soft hover:underline">
            Open job ↗
          </a>
        )}
        <span className="text-xs text-white/40">row {row.rowNumber}</span>
      </div>

      <details className="group mt-2">
        <summary className="text-sm text-white/60 hover:text-white">
          <span className="group-open:hidden">▸ More details</span>
          <span className="hidden group-open:inline">▾ Fewer details</span>
        </summary>
        <div className="mt-2 rounded-xl border border-line/60 bg-black/20 p-3">
          <DetailList
            items={[
              ["Notes", row.notes],
              ["Lemlist / Prior Outreach", row.priorOutreach],
              ["Verified Evidence", row.verifiedEvidence],
              ["My comments", row.myComments],
            ]}
          />
        </div>
      </details>

      {!editable ? (
        <p className="mt-3 text-sm text-warn">
          {row.duplicate
            ? "This Job URL appears more than once in the verify tab, so it can't be edited safely here. Tidy it up in Excel."
            : "This row has no Job URL, so it can't be edited safely here."}
        </p>
      ) : (
        <div className="mt-3 space-y-2">
          <span className="block text-xs uppercase tracking-wide text-white/50">Decision maker LinkedIn</span>
          {links.map((l, i) => (
            <div key={i}>
              <div className="flex gap-2">
                <input
                  className={`${fieldClass} ${errors[i] ? "border-bad" : ""}`}
                  type="url"
                  inputMode="url"
                  autoCapitalize="off"
                  autoCorrect="off"
                  spellCheck={false}
                  placeholder="https://www.linkedin.com/in/…"
                  value={l}
                  onChange={(e) => update(i, e.target.value)}
                  aria-label={`Decision maker LinkedIn link ${i + 1}`}
                  aria-invalid={Boolean(errors[i])}
                />
                {links.length > 1 && (
                  <button
                    type="button"
                    onClick={() => {
                      setLinks((ls) => ls.filter((_, j) => j !== i));
                      setErrors((es) => es.filter((_, j) => j !== i));
                    }}
                    className="shrink-0 rounded-xl border border-line px-3 text-white/60 hover:text-white"
                    aria-label={`Remove link ${i + 1}`}
                  >
                    ×
                  </button>
                )}
              </div>
              {errors[i] && <p className="mt-1 text-xs text-bad">{errors[i]}</p>}
            </div>
          ))}
          <div className="flex flex-wrap items-center gap-2">
            <button
              type="button"
              onClick={() => setLinks((ls) => [...ls, ""])}
              disabled={links.length >= MAX_LINKS_PER_CELL}
              className="rounded-xl border border-line px-3 py-2 text-sm text-white/80 hover:border-pink disabled:opacity-40"
            >
              + Add another
            </button>
            <button
              type="button"
              onClick={() => void save()}
              disabled={state === "saving" || !dirty}
              className="rounded-xl bg-pink px-4 py-2 text-sm font-semibold text-white hover:brightness-110 disabled:opacity-50"
            >
              {state === "saving" ? "Saving…" : "Save"}
            </button>
            {state === "saved" && !dirty && (
              <span className="text-sm font-medium text-ok" role="status">
                ✓ Saved to master sheet <span className="text-white/40">({message})</span>
              </span>
            )}
          </div>
          {busy && <Notice kind="busy">{busy}</Notice>}
          {state === "error" && message && <Notice kind="error">{message}</Notice>}
        </div>
      )}
    </li>
  );
}
