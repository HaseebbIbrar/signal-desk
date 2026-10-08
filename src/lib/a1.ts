// A1-style address helpers. Rows are 1-based (as in Excel); columns are 0-based indexes (A = 0).

export interface A1Range {
  startRow: number;
  startCol: number;
  endRow: number;
  endCol: number;
}

export function colToIndex(letters: string): number {
  let n = 0;
  for (const ch of letters.toUpperCase()) n = n * 26 + (ch.charCodeAt(0) - 64);
  return n - 1;
}

export function indexToCol(index: number): string {
  let s = "";
  let i = index + 1;
  while (i > 0) {
    const m = (i - 1) % 26;
    s = String.fromCharCode(65 + m) + s;
    i = Math.floor((i - 1) / 26);
  }
  return s;
}

const CELL = /^\$?([A-Za-z]{1,3})\$?(\d{1,7})$/;

/** Parse "A1", "A2:Q5" or "'Sheet name'!A1:Q5" (the sheet prefix is ignored). Throws on bad input. */
export function parseA1(address: string): A1Range {
  const bang = address.lastIndexOf("!");
  const local = (bang >= 0 ? address.slice(bang + 1) : address).trim();
  const parts = local.split(":");
  if (parts.length < 1 || parts.length > 2) throw new Error(`Invalid A1 address: ${address}`);
  const a = CELL.exec(parts[0]);
  const b = CELL.exec(parts[1] ?? parts[0]);
  if (!a || !b) throw new Error(`Invalid A1 address: ${address}`);
  const r1 = Number(a[2]);
  const r2 = Number(b[2]);
  const c1 = colToIndex(a[1]);
  const c2 = colToIndex(b[1]);
  if (r1 < 1 || r2 < 1) throw new Error(`Invalid A1 address: ${address}`);
  return {
    startRow: Math.min(r1, r2),
    endRow: Math.max(r1, r2),
    startCol: Math.min(c1, c2),
    endCol: Math.max(c1, c2),
  };
}
