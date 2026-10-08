import type { Cell } from "../constants";
import type { UsedRange } from "../blocks";

/**
 * Minimal workbook surface the app needs. Every write method MUST call `assertWriteAllowed`
 * before doing anything, so a write outside the verify tab's A:Q is impossible whichever
 * backend is in use.
 */
export interface WorkbookBackend {
  /** Human label for the UI banner, e.g. "MOCK DATA" or "DEV COPY". Empty for production. */
  readonly label: string;

  /** Values-only used range of a worksheet. */
  getUsedRange(sheet: string, sessionId?: string): Promise<UsedRange>;
  /** Values of an explicit range, e.g. "A12:Q12". */
  getRange(sheet: string, address: string, sessionId?: string): Promise<Cell[][]>;

  /** Run `fn` inside a workbook session (createSession persistChanges=true … closeSession). */
  withSession<T>(fn: (sessionId: string) => Promise<T>): Promise<T>;

  patchValues(sheet: string, address: string, values: Cell[][], sessionId: string): Promise<void>;
  patchNumberFormat(sheet: string, address: string, formats: string[][], sessionId: string): Promise<void>;
  clearContents(sheet: string, address: string, sessionId: string): Promise<void>;
  deleteShiftUp(sheet: string, address: string, sessionId: string): Promise<void>;
}
