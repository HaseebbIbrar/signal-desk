import { type Cell, HEADERS, SIGNALS_SHEET, VERIFY_SHEET } from "@/lib/constants";
import { isoToSerial } from "@/lib/excel-date";
import { MemoryBackend } from "@/lib/workbook/memory";
import { createSignalService } from "@/lib/service";
import type { AuditEntry } from "@/lib/audit";

export const header = (): Cell[] => [...HEADERS];

export const separator = (iso: string): Cell[] => [isoToSerial(iso), ...Array<Cell>(16).fill("")];

export function signal(iso: string, company: string, url: string, extra: Partial<Record<number, Cell>> = {}): Cell[] {
  const row: Cell[] = [isoToSerial(iso), "Monday", company, `${company.toLowerCase()}.test`, "Security Architect", "security", "A", "End user", "Brussels", "New", "LinkedIn", url, "", "", "note", "evidence", ""];
  for (const [k, v] of Object.entries(extra)) row[Number(k)] = v ?? null;
  return row;
}

export function setup(sheets: { signals: Cell[][]; verify: Cell[][] }) {
  const backend = new MemoryBackend({ [SIGNALS_SHEET]: sheets.signals, [VERIFY_SHEET]: sheets.verify }, "TEST");
  const audits: AuditEntry[] = [];
  const service = createSignalService({ backend, audit: (e) => audits.push(e), cacheTtlMs: 60_000 });
  return { backend, service, audits };
}

/** Rows of a memory sheet trimmed to A:Q, as plain arrays (row 1 first). */
export function sheetAQ(backend: MemoryBackend, name: string): Cell[][] {
  return (backend.grid.get(name) ?? []).map((r) => Array.from({ length: 17 }, (_, c) => r[c] ?? null));
}
