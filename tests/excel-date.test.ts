import { describe, expect, it } from "vitest";
import { cellToISODate, isoToSerial, serialToISO } from "@/lib/excel-date";

describe("Excel date serial conversion", () => {
  it("converts known serials", () => {
    expect(serialToISO(1)).toBe("1900-01-01");
    expect(serialToISO(59)).toBe("1900-02-28");
    expect(serialToISO(60)).toBe("1900-02-29"); // Excel's fictional leap day
    expect(serialToISO(61)).toBe("1900-03-01");
    expect(serialToISO(25569)).toBe("1970-01-01");
    expect(serialToISO(44927)).toBe("2023-01-01");
    expect(serialToISO(45000)).toBe("2023-03-15");
    expect(serialToISO(46302)).toBe("2026-10-07");
  });

  it("ignores the time part of a date-time serial", () => {
    expect(serialToISO(45000.99)).toBe("2023-03-15");
  });

  it("rejects non-dates", () => {
    expect(serialToISO(0)).toBeNull();
    expect(serialToISO(-5)).toBeNull();
    expect(serialToISO(Number.NaN)).toBeNull();
    expect(serialToISO(3_000_000)).toBeNull();
  });

  it("converts ISO dates back to serials", () => {
    expect(isoToSerial("1900-01-01")).toBe(1);
    expect(isoToSerial("1900-02-28")).toBe(59);
    expect(isoToSerial("1900-03-01")).toBe(61);
    expect(isoToSerial("2023-03-15")).toBe(45000);
    expect(isoToSerial("2026-10-07")).toBe(46302);
    expect(isoToSerial("2026-02-30")).toBeNull();
    expect(isoToSerial("07/10/2026")).toBeNull();
  });

  it("round-trips every day across several years", () => {
    for (let s = 61; s < 47000; s += 7) expect(isoToSerial(serialToISO(s)!)).toBe(s);
  });

  it("reads dates from any cell shape", () => {
    expect(cellToISODate(46302)).toBe("2026-10-07");
    expect(cellToISODate("2026-10-07")).toBe("2026-10-07");
    expect(cellToISODate("2026-10-07T00:00:00Z")).toBe("2026-10-07");
    expect(cellToISODate("07/10/2026")).toBe("2026-10-07"); // dd/mm/yyyy (Belgian convention)
    expect(cellToISODate("46302")).toBe("2026-10-07");
    expect(cellToISODate("Acme NV")).toBeNull();
    expect(cellToISODate("")).toBeNull();
    expect(cellToISODate(null)).toBeNull();
    expect(cellToISODate(true)).toBeNull();
  });
});
