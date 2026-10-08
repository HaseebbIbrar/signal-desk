"use client";
// Small shared UI pieces.

export function Notice({ kind, children, onClose }: { kind: "ok" | "warn" | "error" | "busy"; children: React.ReactNode; onClose?: () => void }) {
  const styles = {
    ok: "border-ok/50 bg-ok/10 text-ok",
    warn: "border-warn/50 bg-warn/10 text-warn",
    error: "border-bad/50 bg-bad/10 text-bad",
    busy: "border-pink/50 bg-pink/10 text-pink-soft",
  }[kind];
  return (
    <div role={kind === "error" ? "alert" : "status"} className={`flex items-start gap-2 rounded-xl border px-3 py-2 text-sm ${styles}`}>
      {kind === "busy" && <span className="sd-spinner mt-0.5 shrink-0" aria-hidden />}
      <div className="min-w-0 flex-1 whitespace-pre-line">{children}</div>
      {onClose && (
        <button type="button" onClick={onClose} className="shrink-0 px-1 text-white/60 hover:text-white" aria-label="Dismiss">
          ×
        </button>
      )}
    </div>
  );
}

export function Chip({ children, tone = "plain" }: { children: React.ReactNode; tone?: "plain" | "pink" }) {
  return (
    <span
      className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs ${tone === "pink" ? "border-pink/60 text-pink-soft" : "border-line text-white/75"}`}
    >
      {children}
    </span>
  );
}

export function DetailList({ items }: { items: [string, string][] }) {
  const shown = items.filter(([, v]) => v.trim());
  if (!shown.length) return <p className="text-sm text-white/50">No extra details.</p>;
  return (
    <dl className="space-y-2 text-sm">
      {shown.map(([k, v]) => (
        <div key={k}>
          <dt className="text-xs uppercase tracking-wide text-white/50">{k}</dt>
          <dd className="whitespace-pre-line break-words text-white/85">{v}</dd>
        </div>
      ))}
    </dl>
  );
}

export const fieldClass =
  "w-full rounded-xl border border-line bg-black/40 px-3 py-2.5 text-base text-white placeholder:text-white/40 focus:border-pink focus:outline-none sm:text-sm";
