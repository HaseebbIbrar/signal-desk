// Signal Desk business logic. All reads and writes go to the workbook live (reads cached ~60s).
//
// Write rules (also enforced again at the backend by assertWriteAllowed):
//  - only the "Signals to verify decision make" tab, only columns A:Q;
//  - exactly three operations: append a ticked row, write column M, remove an unticked row whose
//    column M is empty;
//  - rows are always located by Job URL right before writing, never by a remembered row number.

import { type AuditFn, consoleAudit } from "./audit";
import {
  buildBlocks,
  blockDates,
  findSignal,
  isBlank,
  isDataRow,
  isRowBlank,
  lastValueRow,
  latestBlockDate,
  rowsForDate,
  type SheetRow,
  usedRangeToRows,
} from "./blocks";
import { TtlCache, Mutex } from "./cache";
import {
  C,
  type Cell,
  DATE_NUMBER_FORMAT,
  DECISION_MAKER_COL_LETTER,
  JOB_URL_COL_LETTER,
  LAST_COL_LETTER,
  NUM_COLS,
  SIGNALS_SHEET,
  VERIFY_SHEET,
} from "./constants";
import { ConflictError, DecisionMakerPresentError, NotFoundError, UpstreamError, ValidationError } from "./errors";
import { cellToISODate, isoToSerial } from "./excel-date";
import { normaliseJobUrl } from "./job-url";
import { MAX_LINKS_PER_CELL, splitCellLinks, validateLinks } from "./linkedin";
import type {
  SaveDecisionMakerResult,
  SignalRowDTO,
  SignalsView,
  TickResult,
  UntickResult,
  VerifiedRowDTO,
  VerifiedView,
} from "./types";
import type { WorkbookBackend } from "./workbook/types";

export interface ServiceOptions {
  backend: WorkbookBackend;
  audit?: AuditFn;
  cacheTtlMs?: number;
}

export function display(c: Cell): string {
  if (c === null || c === undefined) return "";
  if (typeof c === "boolean") return c ? "TRUE" : "FALSE";
  return String(c);
}

const keyOf = (row: SheetRow) => normaliseJobUrl(row.cells[C.jobUrl]);

/** Strings Excel would treat as a formula are stored as text (leading apostrophe, which Excel hides). */
function protectText(c: Cell): Cell {
  if (typeof c === "string" && /^[=+\-@]/.test(c)) return `'${c}`;
  return c;
}

/** The 17 values to append for a ticked signal. Column A becomes a real Excel date serial. */
export function buildAppendValues(source: SheetRow, blockDate: string | null): { values: Cell[]; dateIsSerial: boolean } {
  const values: Cell[] = source.cells.slice(0, NUM_COLS).map((c) => (c === null || c === undefined ? "" : c));
  let dateIsSerial = false;
  const a = values[C.dateFound];
  if (typeof a === "number") {
    dateIsSerial = true;
  } else {
    const iso = cellToISODate(a) ?? (isBlank(a) ? blockDate : null);
    const serial = iso ? isoToSerial(iso) : null;
    if (serial !== null) {
      values[C.dateFound] = serial;
      dateIsSerial = true;
    }
  }
  return { values: values.map((v, i) => (i === C.dateFound && dateIsSerial ? v : protectText(v))), dateIsSerial };
}

export function createSignalService(opts: ServiceOptions) {
  const { backend } = opts;
  const audit = opts.audit ?? consoleAudit;
  const cache = new TtlCache<SheetRow[]>(opts.cacheTtlMs ?? 60_000);
  const writeLock = new Mutex();

  async function readFresh(sheet: string, sessionId?: string): Promise<SheetRow[]> {
    return usedRangeToRows(await backend.getUsedRange(sheet, sessionId));
  }

  function readCached(sheet: string): Promise<SheetRow[]> {
    return cache.getOrLoad(sheet, () => readFresh(sheet));
  }

  function invalidateAfterWrite() {
    // The app only ever writes the verify tab, so that is the cache that goes stale.
    cache.delete(VERIFY_SHEET);
  }

  function verifyDataRows(rows: SheetRow[]): SheetRow[] {
    return rows.filter((r) => r.rowNumber >= 2 && isDataRow(r.cells));
  }

  function verifyMatches(rows: SheetRow[], key: string): SheetRow[] {
    return verifyDataRows(rows).filter((r) => keyOf(r) === key);
  }

  function requireKey(jobUrl: unknown): string {
    const key = normaliseJobUrl(jobUrl);
    if (!key) throw new ValidationError("A Job URL is required");
    if (key.length > 2000) throw new ValidationError("Job URL is too long");
    return key;
  }

  // ---------------------------------------------------------------- reads

  async function getSignalsView(date?: string | null): Promise<SignalsView> {
    const [signalRows, verifyRows] = await Promise.all([readCached(SIGNALS_SHEET), readCached(VERIFY_SHEET)]);
    const blocks = buildBlocks(signalRows);
    const dates = blockDates(blocks);
    const latestDate = latestBlockDate(blocks);
    const selectedDate = date && dates.includes(date) ? date : latestDate;

    const verifyIndex = new Map<string, { row: number; hasDM: boolean }>();
    for (const r of verifyDataRows(verifyRows)) {
      const k = keyOf(r);
      if (k && !verifyIndex.has(k)) verifyIndex.set(k, { row: r.rowNumber, hasDM: !isBlank(r.cells[C.decisionMaker]) });
    }

    const rows: SignalRowDTO[] = rowsForDate(blocks, selectedDate).map(({ row, blockDate }) => {
      const key = keyOf(row);
      const v = key ? verifyIndex.get(key) : undefined;
      const c = row.cells;
      return {
        rowNumber: row.rowNumber,
        key,
        jobUrl: display(c[C.jobUrl]).trim(),
        dateFound: cellToISODate(c[C.dateFound]) ?? blockDate ?? display(c[C.dateFound]),
        day: display(c[C.day]),
        company: display(c[C.company]),
        domain: display(c[C.domain]),
        jobTitle: display(c[C.jobTitle]),
        matchedKeyword: display(c[C.matchedKeyword]),
        roleGroup: display(c[C.roleGroup]),
        companyType: display(c[C.companyType]),
        location: display(c[C.location]),
        status: display(c[C.status]),
        source: display(c[C.source]),
        priorOutreach: display(c[C.priorOutreach]),
        notes: display(c[C.notes]),
        verifiedEvidence: display(c[C.verifiedEvidence]),
        myComments: display(c[C.myComments]),
        ticked: Boolean(v),
        verifyRow: v?.row ?? null,
        hasDecisionMaker: v?.hasDM ?? false,
      };
    });

    return { dates, latestDate, selectedDate, rows };
  }

  async function getVerifiedView(): Promise<VerifiedView> {
    const rows = verifyDataRows(await readCached(VERIFY_SHEET));
    const counts = new Map<string, number>();
    for (const r of rows) {
      const k = keyOf(r);
      if (k) counts.set(k, (counts.get(k) ?? 0) + 1);
    }
    const out: VerifiedRowDTO[] = rows.map((r) => {
      const c = r.cells;
      const key = keyOf(r);
      const decisionMakers = splitCellLinks(c[C.decisionMaker]);
      return {
        rowNumber: r.rowNumber,
        key,
        jobUrl: display(c[C.jobUrl]).trim(),
        dateFound: cellToISODate(c[C.dateFound]) ?? display(c[C.dateFound]),
        company: display(c[C.company]),
        jobTitle: display(c[C.jobTitle]),
        roleGroup: display(c[C.roleGroup]),
        companyType: display(c[C.companyType]),
        location: display(c[C.location]),
        priorOutreach: display(c[C.priorOutreach]),
        notes: display(c[C.notes]),
        verifiedEvidence: display(c[C.verifiedEvidence]),
        myComments: display(c[C.myComments]),
        decisionMakers,
        hasDecisionMaker: decisionMakers.length > 0,
        duplicate: key ? (counts.get(key) ?? 0) > 1 : false,
      };
    });
    // Newest first: by Date Found descending, then by row (later appends first).
    out.sort((a, b) => (a.dateFound === b.dateFound ? b.rowNumber - a.rowNumber : a.dateFound < b.dateFound ? 1 : -1));
    return { rows: out };
  }

  // ---------------------------------------------------------------- writes

  /** Tick: append the signal's full A:Q row to the next empty row of the verify tab (append only). */
  async function tick(jobUrl: unknown, user: string): Promise<TickResult> {
    const key = requireKey(jobUrl);

    return writeLock.run(() =>
      backend.withSession(async (sid) => {
        const verifyRows = await readFresh(VERIFY_SHEET, sid);
        const existing = verifyMatches(verifyRows, key);
        if (existing.length > 0) {
          const row = existing[0];
          audit({ user, action: "tick_skip_duplicate", jobUrl: key, sheet: VERIFY_SHEET, row: row.rowNumber, outcome: "ok" });
          return { status: "already", row: row.rowNumber, hasDecisionMaker: !isBlank(row.cells[C.decisionMaker]) };
        }

        // The row to copy always comes from our own read of Signals, never from the browser.
        let source = findSignal(buildBlocks(await readCached(SIGNALS_SHEET)), key, keyOf);
        if (!source) {
          // The signal may be newer than the cache (nightly job just ran): read once more, fresh.
          source = findSignal(buildBlocks(await readFresh(SIGNALS_SHEET)), key, keyOf);
        }
        if (!source) throw new NotFoundError("That Job URL is not in the Signals tab");
        const { values, dateIsSerial } = buildAppendValues(source.row, source.blockDate);

        const target = lastValueRow(verifyRows) + 1;
        const address = `A${target}:${LAST_COL_LETTER}${target}`;
        // Append only: the target row must be empty right now, or we stop rather than overwrite.
        const current = await backend.getRange(VERIFY_SHEET, address, sid);
        if (!isRowBlank(current[0] ?? [])) {
          throw new ConflictError("The next row of the verify tab is not empty. Nothing was written. Please try again.");
        }

        await backend.patchValues(VERIFY_SHEET, address, [values], sid);
        if (dateIsSerial) await backend.patchNumberFormat(VERIFY_SHEET, `A${target}`, [[DATE_NUMBER_FORMAT]], sid);
        invalidateAfterWrite();

        const check = await backend.getRange(VERIFY_SHEET, `${JOB_URL_COL_LETTER}${target}`, sid);
        if (normaliseJobUrl(check[0]?.[0]) !== key) {
          audit({ user, action: "tick_append", jobUrl: key, sheet: VERIFY_SHEET, row: target, outcome: "error", detail: "read-back mismatch" });
          throw new UpstreamError("The row was written but could not be confirmed. Please reload and check.");
        }
        audit({ user, action: "tick_append", jobUrl: key, sheet: VERIFY_SHEET, row: target, outcome: "ok" });
        return { status: "appended", row: target, hasDecisionMaker: false };
      }),
    );
  }

  /** Untick: remove the row from the verify tab, but only while its Decision Maker cell is empty. */
  async function untick(jobUrl: unknown, user: string): Promise<UntickResult> {
    const key = requireKey(jobUrl);
    return writeLock.run(() =>
      backend.withSession(async (sid) => {
        const matches = verifyMatches(await readFresh(VERIFY_SHEET, sid), key);
        if (matches.length === 0) return { status: "not_ticked", row: null };
        if (matches.length > 1) {
          const rows = matches.map((m) => m.rowNumber).join(", ");
          audit({ user, action: "untick_refused", jobUrl: key, sheet: VERIFY_SHEET, row: null, outcome: "refused", detail: `duplicates at rows ${rows}` });
          throw new ConflictError(`This job appears more than once in the verify tab (rows ${rows}). Please tidy it up in Excel.`);
        }
        const row = matches[0].rowNumber;
        if (!isBlank(matches[0].cells[C.decisionMaker])) {
          audit({ user, action: "untick_refused", jobUrl: key, sheet: VERIFY_SHEET, row, outcome: "refused", detail: "decision maker present" });
          throw new DecisionMakerPresentError(
            `Can't untick: row ${row} of the verify tab already has a Decision Maker LinkedIn. Clear it on the Verified page first.`,
          );
        }

        // Re-read that exact row in this session: it must still be this job and still have an empty M.
        const address = `A${row}:${LAST_COL_LETTER}${row}`;
        const fresh = (await backend.getRange(VERIFY_SHEET, address, sid))[0] ?? [];
        if (normaliseJobUrl(fresh[C.jobUrl]) !== key) throw new ConflictError("The verify tab changed while removing. Please try again.");
        if (!isBlank(fresh[C.decisionMaker])) {
          audit({ user, action: "untick_refused", jobUrl: key, sheet: VERIFY_SHEET, row, outcome: "refused", detail: "decision maker present" });
          throw new DecisionMakerPresentError(`Can't untick: row ${row} now has a Decision Maker LinkedIn.`);
        }

        await backend.clearContents(VERIFY_SHEET, address, sid);
        await backend.deleteShiftUp(VERIFY_SHEET, address, sid);
        invalidateAfterWrite();
        audit({ user, action: "untick_remove", jobUrl: key, sheet: VERIFY_SHEET, row, outcome: "ok" });
        return { status: "removed", row };
      }),
    );
  }

  /** Write the decision-maker link(s) into column M of the row with this Job URL. */
  async function saveDecisionMaker(jobUrl: unknown, rawLinks: unknown, user: string): Promise<SaveDecisionMakerResult> {
    const key = requireKey(jobUrl);
    if (!Array.isArray(rawLinks) || rawLinks.some((l) => typeof l !== "string")) throw new ValidationError("links must be a list of strings");
    const { links, errors, tooMany } = validateLinks(rawLinks as string[]);
    const firstError = errors.find(Boolean);
    if (firstError) throw new ValidationError(firstError);
    if (tooMany) throw new ValidationError(`At most ${MAX_LINKS_PER_CELL} links per row`);
    const value = links.join("\n");
    const action = links.length ? "write_decision_maker" : "clear_decision_maker";

    return writeLock.run(() =>
      backend.withSession(async (sid) => {
        const matches = verifyMatches(await readFresh(VERIFY_SHEET, sid), key);
        if (matches.length === 0) throw new NotFoundError("That job is no longer in the verify tab");
        if (matches.length > 1) {
          throw new ConflictError(
            `This job appears more than once in the verify tab (rows ${matches.map((m) => m.rowNumber).join(", ")}). Please tidy it up in Excel.`,
          );
        }
        const row = matches[0].rowNumber;
        // Last check immediately before writing: column L of that row must still be this job.
        const l = await backend.getRange(VERIFY_SHEET, `${JOB_URL_COL_LETTER}${row}`, sid);
        if (normaliseJobUrl(l[0]?.[0]) !== key) throw new ConflictError("The verify tab changed while saving. Please try again.");

        await backend.patchValues(VERIFY_SHEET, `${DECISION_MAKER_COL_LETTER}${row}`, [[value]], sid);
        invalidateAfterWrite();

        const back = (await backend.getRange(VERIFY_SHEET, `${JOB_URL_COL_LETTER}${row}:${DECISION_MAKER_COL_LETTER}${row}`, sid))[0] ?? [];
        const saved = display(back[1] ?? "").replace(/\r\n?/g, "\n");
        if (normaliseJobUrl(back[0]) !== key || saved !== value) {
          audit({ user, action, jobUrl: key, sheet: VERIFY_SHEET, row, outcome: "error", detail: "read-back mismatch" });
          throw new UpstreamError("Saved, but the read-back did not match. Please reload and check the row.");
        }
        audit({ user, action, jobUrl: key, sheet: VERIFY_SHEET, row, outcome: "ok", detail: `${links.length} link(s)` });
        return { row, links: splitCellLinks(saved), value: saved };
      }),
    );
  }

  return { getSignalsView, getVerifiedView, tick, untick, saveDecisionMaker, label: backend.label };
}

export type SignalService = ReturnType<typeof createSignalService>;
