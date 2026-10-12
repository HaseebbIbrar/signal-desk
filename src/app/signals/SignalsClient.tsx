"use client";
import { useCallback, useEffect, useMemo, useState } from "react";
import { Chip, DetailList, fieldClass, Notice } from "@/components/ui";
import { ApiError, apiFetch, busyText } from "@/lib/client-api";
import { safeHref } from "@/lib/safe-href";
import type { SignalRowDTO, SignalsView, TickResult, UntickResult } from "@/lib/types";

type NoticeState = { kind: "ok" | "warn" | "error"; text: string } | null;

export default function SignalsClient({ csrf }: { csrf: string }) {
  const [view, setView] = useState<SignalsView | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [notice, setNotice] = useState<NoticeState>(null);
  const [pending, setPending] = useState<Set<string>>(new Set());

  const [roleGroup, setRoleGroup] = useState("");
  const [companyType, setCompanyType] = useState("");
  const [search, setSearch] = useState("");
  const [hideTicked, setHideTicked] = useState(false);

  const onBusy = useCallback((n: number) => setBusy(busyText(n)), []);

  const load = useCallback(
    async (date: string | null) => {
      setLoading(true);
      setLoadError(null);
      try {
        const qs = date ? `?date=${encodeURIComponent(date)}` : "";
        setView(await apiFetch<SignalsView>(`/api/signals${qs}`, { onBusy }));
      } catch (e) {
        setLoadError(e instanceof Error ? e.message : String(e));
      } finally {
        setLoading(false);
      }
    },
    [onBusy],
  );

  useEffect(() => {
    void load(null);
  }, [load]);

  const rows = view?.rows ?? [];
  const roleGroups = useMemo(() => [...new Set(rows.map((r) => r.roleGroup).filter(Boolean))].sort(), [rows]);
  const companyTypes = useMemo(() => [...new Set(rows.map((r) => r.companyType).filter(Boolean))].sort(), [rows]);

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    return rows.filter((r) => {
      if (roleGroup && r.roleGroup !== roleGroup) return false;
      if (companyType && r.companyType !== companyType) return false;
      if (hideTicked && r.ticked) return false;
      if (q) {
        const hay = [r.company, r.jobTitle, r.location, r.matchedKeyword, r.domain, r.notes, r.roleSubGroup].join(" ").toLowerCase();
        if (!hay.includes(q)) return false;
      }
      return true;
    });
  }, [rows, roleGroup, companyType, hideTicked, search]);

  const tickedCount = rows.filter((r) => r.ticked).length;
  const isLatest = view?.selectedDate && view.selectedDate === view.latestDate;

  function patchRows(update: (r: SignalRowDTO) => SignalRowDTO) {
    setView((v) => (v ? { ...v, rows: v.rows.map(update) } : v));
  }

  async function toggle(row: SignalRowDTO) {
    if (!row.key || pending.has(row.key)) return;
    setNotice(null);

    if (row.ticked) {
      if (row.hasDecisionMaker) {
        setNotice({
          kind: "warn",
          text: `Can't untick ${row.company}: a decision maker is already saved for it (verify tab row ${row.verifyRow}). Clear it on the Verified page first if you really want to remove it.`,
        });
        return;
      }
      const ok = window.confirm(
        `Remove "${row.company} – ${row.jobTitle}" from "Signals to verify decision make"?\n\nThis deletes row ${row.verifyRow ?? "?"} of that tab (columns A:Q; rows below move up).`,
      );
      if (!ok) return;
    }

    setPending((p) => new Set(p).add(row.key));
    try {
      if (row.ticked) {
        const res = await apiFetch<UntickResult>("/api/signals/untick", { method: "POST", body: { jobUrl: row.jobUrl }, csrf, onBusy });
        const removed = res.row;
        patchRows((r) => {
          if (r.key === row.key) return { ...r, ticked: false, verifyRow: null, hasDecisionMaker: false };
          // Rows below the removed one moved up by one.
          if (removed && r.verifyRow && r.verifyRow > removed) return { ...r, verifyRow: r.verifyRow - 1 };
          return r;
        });
        setNotice({ kind: "ok", text: res.status === "removed" ? `Removed ${row.company} from the verify tab.` : `${row.company} was not in the verify tab.` });
      } else {
        const res = await apiFetch<TickResult>("/api/signals/tick", { method: "POST", body: { jobUrl: row.jobUrl }, csrf, onBusy });
        patchRows((r) => (r.key === row.key ? { ...r, ticked: true, verifyRow: res.row, hasDecisionMaker: res.hasDecisionMaker } : r));
        setNotice({
          kind: "ok",
          text: res.status === "already" ? `${row.company} was already in the verify tab (row ${res.row}).` : `Added ${row.company} to the verify tab (row ${res.row}).`,
        });
      }
    } catch (e) {
      if (e instanceof ApiError && e.code === "HAS_DECISION_MAKER") patchRows((r) => (r.key === row.key ? { ...r, hasDecisionMaker: true } : r));
      setNotice({ kind: "error", text: e instanceof Error ? e.message : String(e) });
    } finally {
      setPending((p) => {
        const n = new Set(p);
        n.delete(row.key);
        return n;
      });
    }
  }

  return (
    <div className="space-y-4">
      <section className="space-y-3 rounded-2xl border border-line bg-card/80 p-3">
        <div className="flex flex-wrap items-end justify-between gap-2">
          <div>
            <h1 className="text-lg font-semibold">{isLatest ? "Today's signals" : "Signals"}</h1>
            <p className="text-sm text-white/70" aria-live="polite">
              {view ? (
                <>
                  <strong className="text-white">{rows.length}</strong> signals {isLatest ? "today" : `on ${view.selectedDate ?? "—"}`} ·{" "}
                  <strong className="text-pink-soft">{tickedCount}</strong> ticked
                </>
              ) : (
                "Loading…"
              )}
            </p>
          </div>
          <label className="text-sm">
            <span className="mb-1 block text-xs text-white/60">Block date</span>
            <select
              className={`${fieldClass} w-auto`}
              value={view?.selectedDate ?? ""}
              onChange={(e) => void load(e.target.value || null)}
              disabled={!view || loading}
            >
              {(view?.dates ?? []).map((d) => (
                <option key={d} value={d}>
                  {d}
                  {d === view?.latestDate ? " (latest)" : ""}
                </option>
              ))}
            </select>
          </label>
        </div>

        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
          <input className={`${fieldClass} col-span-2`} type="search" placeholder="Search company, title, location…" value={search} onChange={(e) => setSearch(e.target.value)} />
          <select className={fieldClass} value={roleGroup} onChange={(e) => setRoleGroup(e.target.value)} aria-label="Role group">
            <option value="">All role groups</option>
            {roleGroups.map((g) => (
              <option key={g} value={g}>
                {g}
              </option>
            ))}
          </select>
          <select className={fieldClass} value={companyType} onChange={(e) => setCompanyType(e.target.value)} aria-label="Company type">
            <option value="">All company types</option>
            {companyTypes.map((t) => (
              <option key={t} value={t}>
                {t}
              </option>
            ))}
          </select>
        </div>
        <label className="flex items-center gap-2 text-sm text-white/80">
          <input type="checkbox" className="h-5 w-5" checked={hideTicked} onChange={(e) => setHideTicked(e.target.checked)} />
          Hide already ticked
        </label>
      </section>

      {busy && <Notice kind="busy">{busy}</Notice>}
      {notice && (
        <Notice kind={notice.kind} onClose={() => setNotice(null)}>
          {notice.text}
        </Notice>
      )}
      {loadError && (
        <Notice kind="error">
          {loadError}{" "}
          <button type="button" className="underline" onClick={() => void load(view?.selectedDate ?? null)}>
            Retry
          </button>
        </Notice>
      )}

      {loading && !view && (
        <p className="flex items-center gap-2 text-sm text-white/70">
          <span className="sd-spinner" /> Reading the master sheet…
        </p>
      )}

      {view && !loading && rows.length === 0 && <p className="text-sm text-white/60">No signals in this block.</p>}
      {view && rows.length > 0 && filtered.length === 0 && <p className="text-sm text-white/60">No signals match these filters.</p>}

      <ul className={`space-y-3 ${loading ? "opacity-60" : ""}`}>
        {filtered.map((row) => (
          <SignalCard key={`${row.rowNumber}`} row={row} pending={pending.has(row.key)} onToggle={() => void toggle(row)} />
        ))}
      </ul>
    </div>
  );
}

function SignalCard({ row, pending, onToggle }: { row: SignalRowDTO; pending: boolean; onToggle: () => void }) {
  const href = safeHref(row.jobUrl);
  const canTick = Boolean(row.key);
  return (
    <li className={`rounded-2xl border p-3 transition-colors ${row.ticked ? "border-pink/70 bg-card-2" : "border-line bg-card"}`}>
      <div className="flex gap-3">
        <label className="flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center rounded-xl border border-line bg-black/30">
          {pending ? (
            <span className="sd-spinner" aria-label="Saving" />
          ) : (
            <input
              type="checkbox"
              className="h-6 w-6 cursor-pointer"
              checked={row.ticked}
              disabled={!canTick}
              onChange={onToggle}
              aria-label={`${row.ticked ? "Untick" : "Tick"} ${row.company} – ${row.jobTitle}`}
              title={canTick ? undefined : "This row has no Job URL, so it can't be ticked"}
            />
          )}
        </label>
        <div className="min-w-0 flex-1">
          <h2 className="font-semibold leading-snug">{row.company || "(no company)"}</h2>
          <p className="text-sm text-white/85">{row.jobTitle}</p>
          <div className="mt-1.5 flex flex-wrap gap-1.5">
            {row.roleGroup && <Chip tone="pink">{row.roleGroup}</Chip>}
            {row.roleSubGroup && <Chip tone="pink">{row.roleSubGroup}</Chip>}
            {row.companyType && <Chip>{row.companyType}</Chip>}
            {row.location && <Chip>{row.location}</Chip>}
          </div>
          <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
            {href ? (
              <a href={href} target="_blank" rel="noopener noreferrer" className="font-medium text-pink-soft underline-offset-2 hover:underline">
                Open job ↗
              </a>
            ) : (
              <span className="text-white/50">{row.jobUrl ? `Job URL: ${row.jobUrl}` : "No Job URL"}</span>
            )}
            {row.ticked && (
              <span className="text-xs text-white/60">
                In verify tab{row.verifyRow ? ` · row ${row.verifyRow}` : ""}
                {row.hasDecisionMaker ? " · decision maker saved" : ""}
              </span>
            )}
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
                  ["Matched keyword", row.matchedKeyword],
                  ["Source", row.source],
                  ["Status", row.status],
                  ["Domain", row.domain],
                  ["My comments", row.myComments],
                ]}
              />
            </div>
          </details>
        </div>
      </div>
    </li>
  );
}
