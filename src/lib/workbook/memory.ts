// In-memory workbook used by the tests and by WORKBOOK_BACKEND=mock for local UI work.
// It follows the same write rules as the Graph backend (every write goes through the guard).

import { parseA1 } from "../a1";
import { isBlank, type UsedRange } from "../blocks";
import type { Cell } from "../constants";
import type { AppError } from "../errors";
import { assertWriteAllowed } from "./guard";
import type { WorkbookBackend } from "./types";

export interface MemoryWrite {
  op: "patchValues" | "patchNumberFormat" | "clearContents" | "deleteShiftUp";
  sheet: string;
  address: string;
}

export class MemoryBackend implements WorkbookBackend {
  readonly label: string;
  /** grid[sheet][r][c]: r=0 is Excel row 1, c=0 is column A. */
  readonly grid: Map<string, Cell[][]>;
  readonly formats = new Map<string, string>(); // "sheet!A12" -> format
  readonly writes: MemoryWrite[] = [];
  /** Errors to throw on the next calls (FIFO), e.g. SheetBusyError to simulate a locked file. */
  readonly failures: AppError[] = [];
  private sessionCounter = 0;

  constructor(sheets: Record<string, Cell[][]>, label = "MOCK DATA") {
    this.label = label;
    this.grid = new Map(Object.entries(sheets).map(([k, v]) => [k, v.map((r) => [...r])]));
  }

  private maybeFail() {
    const f = this.failures.shift();
    if (f) throw f;
  }

  private sheet(name: string): Cell[][] {
    const g = this.grid.get(name);
    if (!g) throw new Error(`No such worksheet: ${name}`);
    return g;
  }

  private cell(name: string, r: number, c: number): Cell {
    const v = this.sheet(name)[r]?.[c];
    return v === undefined ? null : v;
  }

  private setCell(name: string, r: number, c: number, v: Cell) {
    const g = this.sheet(name);
    while (g.length <= r) g.push([]);
    const row = g[r];
    while (row.length <= c) row.push(null);
    row[c] = v;
  }

  async getUsedRange(sheet: string): Promise<UsedRange> {
    this.maybeFail();
    const g = this.sheet(sheet);
    let lastRow = -1;
    let lastCol = -1;
    g.forEach((row, r) =>
      row.forEach((v, c) => {
        if (!isBlank(v)) {
          lastRow = Math.max(lastRow, r);
          lastCol = Math.max(lastCol, c);
        }
      }),
    );
    if (lastRow < 0) return { startRow: 1, startCol: 0, values: [[""]] };
    const values: Cell[][] = [];
    for (let r = 0; r <= lastRow; r++) {
      const row: Cell[] = [];
      for (let c = 0; c <= lastCol; c++) row.push(this.cell(sheet, r, c) ?? "");
      values.push(row);
    }
    return { startRow: 1, startCol: 0, values };
  }

  async getRange(sheet: string, address: string): Promise<Cell[][]> {
    this.maybeFail();
    const a = parseA1(address);
    const out: Cell[][] = [];
    for (let r = a.startRow - 1; r <= a.endRow - 1; r++) {
      const row: Cell[] = [];
      for (let c = a.startCol; c <= a.endCol; c++) row.push(this.cell(sheet, r, c) ?? "");
      out.push(row);
    }
    return out;
  }

  async withSession<T>(fn: (sessionId: string) => Promise<T>): Promise<T> {
    this.maybeFail();
    return fn(`mem-session-${++this.sessionCounter}`);
  }

  async patchValues(sheet: string, address: string, values: Cell[][]): Promise<void> {
    const a = assertWriteAllowed(sheet, address);
    this.maybeFail();
    this.writes.push({ op: "patchValues", sheet, address });
    for (let i = 0; i <= a.endCol - a.startCol; i++) {
      let v = values[0]?.[i] ?? "";
      // Excel treats a leading apostrophe as a "store as text" marker and does not keep it.
      if (typeof v === "string" && v.startsWith("'")) v = v.slice(1);
      this.setCell(sheet, a.startRow - 1, a.startCol + i, v);
    }
  }

  async patchNumberFormat(sheet: string, address: string, formats: string[][]): Promise<void> {
    const a = assertWriteAllowed(sheet, address);
    this.maybeFail();
    this.writes.push({ op: "patchNumberFormat", sheet, address });
    for (let i = 0; i <= a.endCol - a.startCol; i++) {
      this.formats.set(`${sheet}!${a.startCol + i}:${a.startRow}`, formats[0]?.[i] ?? "General");
    }
  }

  async clearContents(sheet: string, address: string): Promise<void> {
    const a = assertWriteAllowed(sheet, address);
    this.maybeFail();
    this.writes.push({ op: "clearContents", sheet, address });
    for (let c = a.startCol; c <= a.endCol; c++) this.setCell(sheet, a.startRow - 1, c, null);
  }

  async deleteShiftUp(sheet: string, address: string): Promise<void> {
    const a = assertWriteAllowed(sheet, address);
    this.maybeFail();
    this.writes.push({ op: "deleteShiftUp", sheet, address });
    const g = this.sheet(sheet);
    const r0 = a.startRow - 1;
    for (let c = a.startCol; c <= a.endCol; c++) {
      for (let r = r0; r < g.length; r++) this.setCell(sheet, r, c, this.cell(sheet, r + 1, c));
    }
  }
}
