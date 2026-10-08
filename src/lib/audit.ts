// Every write (and every refused write) is logged as one JSON line to the server logs.

export type AuditAction =
  | "tick_append"
  | "tick_skip_duplicate"
  | "untick_remove"
  | "untick_refused"
  | "write_decision_maker"
  | "clear_decision_maker";

export interface AuditEntry {
  user: string;
  action: AuditAction;
  jobUrl: string;
  sheet: string;
  row: number | null;
  outcome: "ok" | "refused" | "error";
  detail?: string;
}

export type AuditFn = (entry: AuditEntry) => void;

export const consoleAudit: AuditFn = (entry) => {
  console.log(JSON.stringify({ type: "audit", time: new Date().toISOString(), ...entry }));
};
