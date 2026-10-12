// The single write gate. The app may write ONLY to "Signals to verify decision make", ONLY inside
// columns A:R (R = Role Sub-Group, used only when the tab's R1 header says so), ONLY one data row
// at a time (never the header row, so headers are never written), and never to any other tab.

import { parseA1, type A1Range } from "../a1";
import { READ_COLS, VERIFY_SHEET } from "../constants";
import { ForbiddenWriteError } from "../errors";

export function assertWriteAllowed(sheet: string, address: string): A1Range {
  if (sheet !== VERIFY_SHEET) {
    throw new ForbiddenWriteError(`Refused: the app never writes to the "${sheet}" tab`);
  }
  if (address.includes("!")) {
    throw new ForbiddenWriteError("Refused: write addresses must not name a sheet");
  }
  let range: A1Range;
  try {
    range = parseA1(address);
  } catch {
    throw new ForbiddenWriteError(`Refused: invalid address "${address}"`);
  }
  if (range.startRow !== range.endRow) {
    throw new ForbiddenWriteError("Refused: writes are limited to a single row");
  }
  if (range.startRow < 2) {
    throw new ForbiddenWriteError("Refused: the header row is never written");
  }
  if (range.startCol < 0 || range.endCol > READ_COLS - 1) {
    throw new ForbiddenWriteError("Refused: writes are limited to columns A:R");
  }
  return range;
}
