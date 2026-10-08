// Turning raw sheet values into rows, and splitting the "Signals" tab into daily blocks.
//
// The nightly job appends one block per run: a separator row holding only a date in column A,
// followed by that day's signal rows.

import { type Cell, C, NUM_COLS } from "./constants";
import { cellToISODate } from "./excel-date";

export interface SheetRow {
  /** 1-based Excel row number. */
  rowNumber: number;
  /** Exactly 17 cells, A:Q. */
  cells: Cell[];
}

export interface UsedRange {
  /** 1-based row of values[0]. */
  startRow: number;
  /** 0-based column of values[i][0]. */
  startCol: number;
  values: Cell[][];
}

export interface Block {
  /** yyyy-mm-dd, or null for rows whose date could not be read. */
  date: string | null;
  /** Row number of the separator, or null for rows that sit before the first separator. */
  separatorRow: number | null;
  rows: SheetRow[];
}

export function isBlank(c: Cell | undefined): boolean {
  return c === null || c === undefined || (typeof c === "string" && c.trim() === "");
}

/** Map a used range onto absolute row numbers and exactly the A:Q columns. */
export function usedRangeToRows(used: UsedRange): SheetRow[] {
  return used.values.map((row, i) => {
    const cells: Cell[] = [];
    for (let col = 0; col < NUM_COLS; col++) {
      const idx = col - used.startCol;
      const v = idx >= 0 && idx < row.length ? row[idx] : null;
      cells.push(v === undefined ? null : v);
    }
    return { rowNumber: used.startRow + i, cells };
  });
}

export function isRowBlank(cells: Cell[]): boolean {
  return cells.every(isBlank);
}

export function isHeaderRow(cells: Cell[]): boolean {
  const a = cells[C.dateFound];
  return typeof a === "string" && a.trim().toLowerCase() === "date found";
}

/** A separator has a date in column A and nothing at all in B:Q. */
export function isSeparatorRow(cells: Cell[]): boolean {
  if (isBlank(cells[0])) return false;
  if (!cells.slice(1, NUM_COLS).every(isBlank)) return false;
  return cellToISODate(cells[0]) !== null;
}

/** A signal row has something in B:Q. */
export function isDataRow(cells: Cell[]): boolean {
  return !isHeaderRow(cells) && cells.slice(1, NUM_COLS).some((c) => !isBlank(c));
}

/** Highest row number that actually holds a value in A:Q (1 if only the header / nothing). Never trusts max_row. */
export function lastValueRow(rows: SheetRow[]): number {
  let last = 1;
  for (const r of rows) if (r.rowNumber > last && !isRowBlank(r.cells)) last = r.rowNumber;
  return last;
}

/** Split the Signals tab into blocks, in sheet order. Row 1 (header) is skipped. */
export function buildBlocks(rows: SheetRow[]): Block[] {
  const blocks: Block[] = [];
  let current: Block | null = null;
  const orphanByDate = new Map<string | null, Block>();

  for (const row of rows) {
    if (row.rowNumber < 2 || isHeaderRow(row.cells)) continue;
    if (isSeparatorRow(row.cells)) {
      current = { date: cellToISODate(row.cells[0]), separatorRow: row.rowNumber, rows: [] };
      blocks.push(current);
      continue;
    }
    if (!isDataRow(row.cells)) continue;
    if (current) {
      current.rows.push(row);
    } else {
      // Rows before the first separator (older history) are grouped by their own Date Found.
      const date = cellToISODate(row.cells[C.dateFound]);
      let orphan = orphanByDate.get(date);
      if (!orphan) {
        orphan = { date, separatorRow: null, rows: [] };
        orphanByDate.set(date, orphan);
        blocks.push(orphan);
      }
      orphan.rows.push(row);
    }
  }
  return blocks;
}

/** Distinct block dates, newest first. */
export function blockDates(blocks: Block[]): string[] {
  const set = new Set<string>();
  for (const b of blocks) if (b.date) set.add(b.date);
  return [...set].sort((a, b) => (a < b ? 1 : a > b ? -1 : 0));
}

/** The date of the latest block: the most recent separator date (falls back to dated orphan rows). */
export function latestBlockDate(blocks: Block[]): string | null {
  const separatorDates = blocks.filter((b) => b.separatorRow !== null && b.date).map((b) => b.date as string);
  const pool = separatorDates.length ? separatorDates : blocks.filter((b) => b.date).map((b) => b.date as string);
  return pool.length ? pool.reduce((a, b) => (b > a ? b : a)) : null;
}

/** All rows of every block with this date (a day that ran twice is shown as one list), with the block date. */
export function rowsForDate(blocks: Block[], date: string | null): { row: SheetRow; blockDate: string | null }[] {
  if (!date) return [];
  return blocks.filter((b) => b.date === date).flatMap((b) => b.rows.map((row) => ({ row, blockDate: b.date })));
}

/** Find a signal row by normalised Job URL, with its block date (latest occurrence wins). */
export function findSignal(
  blocks: Block[],
  key: string,
  keyOf: (row: SheetRow) => string,
): { row: SheetRow; blockDate: string | null } | null {
  let found: { row: SheetRow; blockDate: string | null } | null = null;
  for (const b of blocks) for (const row of b.rows) if (keyOf(row) === key) found = { row, blockDate: b.date };
  return found;
}
