import { describe, expect, it } from "vitest";
import {
  blockDates,
  buildBlocks,
  isSeparatorRow,
  lastValueRow,
  latestBlockDate,
  rowsForDate,
  usedRangeToRows,
} from "@/lib/blocks";
import type { Cell } from "@/lib/constants";
import { isoToSerial } from "@/lib/excel-date";
import { header, separator, signal } from "./helpers";

const rowsOf = (values: Cell[][]) => usedRangeToRows({ startRow: 1, startCol: 0, values });

describe("separator detection", () => {
  it("recognises a date-only row as a separator", () => {
    expect(isSeparatorRow(separator("2026-10-06"))).toBe(true);
    expect(isSeparatorRow(["2026-10-06", ...Array(16).fill("")])).toBe(true); // date typed as text
  });

  it("does not treat signal rows, blank rows or junk as separators", () => {
    expect(isSeparatorRow(signal("2026-10-06", "Acme", "https://x.test/1"))).toBe(false);
    expect(isSeparatorRow([isoToSerial("2026-10-06"), "", "Acme", ...Array(14).fill("")])).toBe(false);
    expect(isSeparatorRow(Array(17).fill(""))).toBe(false);
    expect(isSeparatorRow(["Total", ...Array(16).fill("")])).toBe(false);
  });
});

describe("finding the latest block", () => {
  const sheet: Cell[][] = [
    header(),
    signal("2026-09-28", "Legacy NV", "https://x.test/legacy"), // history before the first separator
    separator("2026-10-04"),
    signal("2026-10-04", "Alpha", "https://x.test/a"),
    signal("2026-10-04", "Beta", "https://x.test/b"),
    Array(17).fill(""), // stray blank row
    separator("2026-10-05"),
    signal("2026-10-05", "Gamma", "https://x.test/g"),
    separator("2026-10-06"),
    signal("2026-10-06", "Delta", "https://x.test/d"),
    signal("2026-10-06", "Epsilon", "https://x.test/e"),
    signal("2026-10-06", "Zeta", "https://x.test/z"),
  ];

  it("splits rows into blocks under each separator", () => {
    const blocks = buildBlocks(rowsOf(sheet));
    expect(blocks.map((b) => [b.date, b.rows.length])).toEqual([
      ["2026-09-28", 1],
      ["2026-10-04", 2],
      ["2026-10-05", 1],
      ["2026-10-06", 3],
    ]);
  });

  it("picks the rows under the most recent separator date", () => {
    const blocks = buildBlocks(rowsOf(sheet));
    const latest = latestBlockDate(blocks);
    expect(latest).toBe("2026-10-06");
    expect(rowsForDate(blocks, latest).map((r) => r.row.cells[2])).toEqual(["Delta", "Epsilon", "Zeta"]);
    expect(rowsForDate(blocks, latest).map((r) => r.row.rowNumber)).toEqual([10, 11, 12]);
  });

  it("lists block dates newest first for the date picker", () => {
    expect(blockDates(buildBlocks(rowsOf(sheet)))).toEqual(["2026-10-06", "2026-10-05", "2026-10-04", "2026-09-28"]);
  });

  it("merges two runs on the same day and shows an empty latest block honestly", () => {
    const twice = [header(), separator("2026-10-06"), signal("2026-10-06", "A", "https://x.test/1"), separator("2026-10-06"), signal("2026-10-06", "B", "https://x.test/2")];
    const blocks = buildBlocks(rowsOf(twice));
    expect(rowsForDate(blocks, "2026-10-06")).toHaveLength(2);

    const emptyLatest = [header(), separator("2026-10-05"), signal("2026-10-05", "A", "https://x.test/1"), separator("2026-10-06")];
    const b2 = buildBlocks(rowsOf(emptyLatest));
    expect(latestBlockDate(b2)).toBe("2026-10-06");
    expect(rowsForDate(b2, "2026-10-06")).toHaveLength(0);
  });

  it("uses the most recent DATE, even if a block was appended out of order", () => {
    const outOfOrder = [header(), separator("2026-10-06"), signal("2026-10-06", "New", "https://x.test/n"), separator("2026-10-02"), signal("2026-10-02", "Old", "https://x.test/o")];
    expect(latestBlockDate(buildBlocks(rowsOf(outOfOrder)))).toBe("2026-10-06");
  });

  it("maps a used range that does not start at A1 onto the right rows and columns", () => {
    const rows = usedRangeToRows({ startRow: 3, startCol: 1, values: [["Mon", "Acme"]] });
    expect(rows[0].rowNumber).toBe(3);
    expect(rows[0].cells[0]).toBeNull();
    expect(rows[0].cells[1]).toBe("Mon");
    expect(rows[0].cells[2]).toBe("Acme");
    expect(rows[0].cells).toHaveLength(18); // A:R
  });

  it("finds the last row with a value, ignoring formatted-but-empty rows", () => {
    const values: Cell[][] = [header(), signal("2026-10-01", "A", "u1"), Array(17).fill(""), signal("2026-10-01", "B", "u2"), Array(17).fill(""), Array(17).fill("")];
    expect(lastValueRow(rowsOf(values))).toBe(4);
    expect(lastValueRow(rowsOf([header()]))).toBe(1);
  });
});
