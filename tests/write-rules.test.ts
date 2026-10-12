import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { type Cell, SIGNALS_SHEET, VERIFY_SHEET } from "@/lib/constants";
import { DecisionMakerPresentError, ForbiddenWriteError, SheetBusyError } from "@/lib/errors";
import { isoToSerial } from "@/lib/excel-date";
import { assertWriteAllowed } from "@/lib/workbook/guard";
import { GraphBackend } from "@/lib/workbook/graph";
import { header, separator, setup, sheetAQ, signal } from "./helpers";

const D = "2026-10-06";

// These tests use the original 17-column verify tab (no R1 "Role Sub-Group"), so ticks append A:Q
// and log a warning by design (covered in sub-group.test.ts). Keep the test output quiet.
beforeEach(() => void vi.spyOn(console, "warn").mockImplementation(() => {}));
afterEach(() => vi.restoreAllMocks());

function baseSheets() {
  const signals: Cell[][] = [
    header(),
    separator("2026-10-05"),
    signal("2026-10-05", "Old Co", "https://jobs.test/old"),
    separator(D),
    signal(D, "Acme", "https://jobs.test/acme?utm=feed"),
    signal(D, "Beta", "https://jobs.test/beta"),
    signal(D, "Gamma", "https://jobs.test/gamma", { 14: "=HYPERLINK(evil)" }),
  ];
  const verify: Cell[][] = [
    header(),
    signal("2026-10-01", "Kept One", "https://jobs.test/k1", { 12: "https://www.linkedin.com/in/someone" }),
    signal("2026-10-01", "Kept Two", "https://jobs.test/k2"),
  ];
  return { signals, verify };
}

describe("refusing to write to Signals", () => {
  it("the guard rejects every write to the Signals tab", () => {
    expect(() => assertWriteAllowed(SIGNALS_SHEET, "A2:Q2")).toThrow(ForbiddenWriteError);
    expect(() => assertWriteAllowed(SIGNALS_SHEET, "M5")).toThrow(ForbiddenWriteError);
    expect(() => assertWriteAllowed("Companies", "A2")).toThrow(ForbiddenWriteError);
    expect(() => assertWriteAllowed("signals to verify decision make", "A2")).toThrow(ForbiddenWriteError); // exact name only
  });

  it("the guard limits verify-tab writes to one data row inside A:R", () => {
    expect(() => assertWriteAllowed(VERIFY_SHEET, "A2:Q2")).not.toThrow();
    expect(() => assertWriteAllowed(VERIFY_SHEET, "A2:R2")).not.toThrow(); // R = Role Sub-Group
    expect(() => assertWriteAllowed(VERIFY_SHEET, "M160")).not.toThrow();
    expect(() => assertWriteAllowed(VERIFY_SHEET, "A1:Q1")).toThrow(ForbiddenWriteError); // header
    expect(() => assertWriteAllowed(VERIFY_SHEET, "R1")).toThrow(ForbiddenWriteError); // headers are never written
    expect(() => assertWriteAllowed(VERIFY_SHEET, "A2:S2")).toThrow(ForbiddenWriteError); // past R
    expect(() => assertWriteAllowed(VERIFY_SHEET, "S5")).toThrow(ForbiddenWriteError);
    expect(() => assertWriteAllowed(VERIFY_SHEET, "A2:Q3")).toThrow(ForbiddenWriteError); // multi-row
    expect(() => assertWriteAllowed(VERIFY_SHEET, "Signals!A2")).toThrow(ForbiddenWriteError);
  });

  it("the memory backend refuses and leaves Signals untouched", async () => {
    const { backend } = setup(baseSheets());
    const before = JSON.stringify(backend.grid.get(SIGNALS_SHEET));
    await expect(backend.patchValues(SIGNALS_SHEET, "A2:Q2", [Array(17).fill("x")])).rejects.toThrow(ForbiddenWriteError);
    await expect(backend.deleteShiftUp(SIGNALS_SHEET, "A2:Q2")).rejects.toThrow(ForbiddenWriteError);
    expect(JSON.stringify(backend.grid.get(SIGNALS_SHEET))).toBe(before);
  });

  it("the Graph backend refuses before any network call is made", async () => {
    const calls: string[] = [];
    const fakeFetch = (async (url: string) => {
      calls.push(String(url));
      return new Response("{}", { status: 200 });
    }) as unknown as typeof fetch;
    const g = new GraphBackend({ tenantId: "t", clientId: "c", clientSecret: "s", driveId: "d", itemId: "i", label: "", fetchImpl: fakeFetch });
    await expect(g.patchValues(SIGNALS_SHEET, "A2:Q2", [[]], "sid")).rejects.toThrow(ForbiddenWriteError);
    await expect(g.clearContents(SIGNALS_SHEET, "A2:Q2", "sid")).rejects.toThrow(ForbiddenWriteError);
    await expect(g.deleteShiftUp(SIGNALS_SHEET, "A2:Q2", "sid")).rejects.toThrow(ForbiddenWriteError);
    expect(calls).toEqual([]);
  });

  it("no service operation ever writes outside the verify tab", async () => {
    const { backend, service } = setup(baseSheets());
    await service.tick("https://jobs.test/acme", "jp");
    await service.saveDecisionMaker("https://jobs.test/acme", ["linkedin.com/in/jane"], "jp");
    await service.saveDecisionMaker("https://jobs.test/acme", [], "jp");
    await service.untick("https://jobs.test/acme", "jp");
    expect(backend.writes.length).toBeGreaterThan(0);
    expect(backend.writes.every((w) => w.sheet === VERIFY_SHEET)).toBe(true);
  });
});

describe("append-only tick", () => {
  it("appends the full 17-column row to the next empty row and changes nothing else", async () => {
    const { backend, service, audits } = setup(baseSheets());
    const before = sheetAQ(backend, VERIFY_SHEET);

    const res = await service.tick("https://jobs.test/acme", "jp@swooscale.com");
    expect(res).toEqual({ status: "appended", row: 4, hasDecisionMaker: false });

    const after = sheetAQ(backend, VERIFY_SHEET);
    expect(after.slice(0, 3)).toEqual(before.slice(0, 3)); // existing rows untouched
    expect(after[3][0]).toBe(isoToSerial(D)); // real Excel date serial
    expect(after[3][2]).toBe("Acme");
    expect(after[3][11]).toBe("https://jobs.test/acme?utm=feed"); // copied as-is from Signals
    expect(backend.formats.get(`${VERIFY_SHEET}!0:4`)).toBe("yyyy-mm-dd");
    expect(backend.writes.map((w) => w.op)).toEqual(["patchValues", "patchNumberFormat"]);
    expect(backend.writes[0].address).toBe("A4:Q4");
    expect(audits.at(-1)).toMatchObject({ action: "tick_append", row: 4, outcome: "ok", user: "jp@swooscale.com" });
  });

  it("appends after the LAST row with a value, never into a gap", async () => {
    const sheets = baseSheets();
    sheets.verify.push(Array(17).fill(""), signal("2026-10-02", "After Gap", "https://jobs.test/gap"));
    const { backend, service } = setup(sheets);
    const res = await service.tick("https://jobs.test/beta", "jp");
    expect(res.row).toBe(6);
    expect(sheetAQ(backend, VERIFY_SHEET)[3].every((c) => c === "" || c === null)).toBe(true); // gap stays empty
  });

  it("skips the append when the Job URL is already in the verify tab", async () => {
    const { backend, service } = setup(baseSheets());
    const res = await service.tick("https://jobs.test/k2/", "jp"); // normalises to an existing row
    expect(res).toEqual({ status: "already", row: 3, hasDecisionMaker: false });
    expect(backend.writes).toEqual([]);

    await service.tick("https://jobs.test/beta", "jp");
    const again = await service.tick("https://jobs.test/beta?x=1", "jp");
    expect(again.status).toBe("already");
    expect(backend.writes.filter((w) => w.op === "patchValues")).toHaveLength(1);
  });

  it("stores formula-looking text as text", async () => {
    const { backend, service } = setup(baseSheets());
    await service.tick("https://jobs.test/gamma", "jp");
    expect(sheetAQ(backend, VERIFY_SHEET)[3][14]).toBe("=HYPERLINK(evil)"); // apostrophe consumed, stays text
  });

  it("shows ticks on reload, worked out from the verify tab", async () => {
    const { service } = setup(baseSheets());
    await service.tick("https://jobs.test/beta", "jp");
    const view = await service.getSignalsView();
    expect(view.selectedDate).toBe(D);
    expect(view.rows.map((r) => [r.company, r.ticked])).toEqual([
      ["Acme", false],
      ["Beta", true],
      ["Gamma", false],
    ]);
  });
});

describe("untick", () => {
  it("refuses to untick a row that already has a decision maker", async () => {
    const { backend, service, audits } = setup(baseSheets());
    await expect(service.untick("https://jobs.test/k1", "jp")).rejects.toThrow(DecisionMakerPresentError);
    expect(backend.writes).toEqual([]);
    expect(sheetAQ(backend, VERIFY_SHEET)[1][2]).toBe("Kept One");
    expect(audits.at(-1)).toMatchObject({ action: "untick_refused", outcome: "refused", row: 2 });
  });

  it("removes a row with an empty decision maker: clear A:Q, then delete with shift up", async () => {
    const sheets = baseSheets();
    sheets.verify[2][17] = "column R must not move"; // a value outside A:Q
    sheets.verify.push(signal("2026-10-02", "Below", "https://jobs.test/below"));
    const { backend, service } = setup(sheets);

    const res = await service.untick("https://jobs.test/k2", "jp");
    expect(res).toEqual({ status: "removed", row: 3 });
    expect(backend.writes.map((w) => [w.op, w.address])).toEqual([
      ["clearContents", "A3:Q3"],
      ["deleteShiftUp", "A3:Q3"],
    ]);
    const grid = backend.grid.get(VERIFY_SHEET)!;
    expect(grid[2][2]).toBe("Below"); // the row below moved up
    expect(grid[2][17]).toBe("column R must not move"); // only A:Q shifted
  });

  it("is a no-op when the job is not in the verify tab", async () => {
    const { backend, service } = setup(baseSheets());
    expect(await service.untick("https://jobs.test/acme", "jp")).toEqual({ status: "not_ticked", row: null });
    expect(backend.writes).toEqual([]);
  });
});

describe("decision maker save (column M)", () => {
  it("finds the row by Job URL at write time, even after rows moved", async () => {
    const { backend, service } = setup(baseSheets());
    await service.tick("https://jobs.test/acme", "jp"); // row 4
    await service.tick("https://jobs.test/beta", "jp"); // row 5
    await service.untick("https://jobs.test/k2", "jp"); // row 3 removed: Acme -> 3, Beta -> 4

    backend.writes.length = 0;
    const res = await service.saveDecisionMaker("https://jobs.test/beta", ["https://be.linkedin.com/in/jane-doe/?trk=x", "linkedin.com/sales/lead/ABC,NAME"], "jp");
    expect(res.row).toBe(4);
    expect(res.value).toBe("https://www.linkedin.com/in/jane-doe\nhttps://www.linkedin.com/sales/lead/ABC,NAME");
    expect(backend.writes).toEqual([{ op: "patchValues", sheet: VERIFY_SHEET, address: "M4" }]);
    const grid = sheetAQ(backend, VERIFY_SHEET);
    expect(grid[3][2]).toBe("Beta");
    expect(grid[2][12]).toBe(""); // Acme's M untouched
  });

  it("rejects non-LinkedIn links server-side", async () => {
    const { backend, service } = setup(baseSheets());
    await expect(service.saveDecisionMaker("https://jobs.test/k2", ["https://evil.test/in/x"], "jp")).rejects.toThrow(/linkedin/i);
    await expect(service.saveDecisionMaker("https://jobs.test/k2", ["https://www.linkedin.com/company/acme"], "jp")).rejects.toThrow();
    await expect(service.saveDecisionMaker("https://jobs.test/k2", "not-a-list", "jp")).rejects.toThrow();
    expect(backend.writes).toEqual([]);
  });
});

describe("busy workbook", () => {
  it("surfaces SheetBusyError (the client then shows 'Sheet busy, retrying…')", async () => {
    const { backend, service } = setup(baseSheets());
    backend.failures.push(new SheetBusyError());
    await expect(service.tick("https://jobs.test/acme", "jp")).rejects.toThrow(SheetBusyError);
    expect(backend.writes).toEqual([]);
    await expect(service.tick("https://jobs.test/acme", "jp")).resolves.toMatchObject({ status: "appended" });
  });

  it("Graph backend retries 423/429 then succeeds, but never blindly retries a range delete after a 503", async () => {
    const statuses = [423, 429, 200];
    const calls: string[] = [];
    const fakeFetch = (async (url: string, init?: RequestInit) => {
      const u = String(url);
      if (u.includes("oauth2")) return new Response(JSON.stringify({ access_token: "tok", expires_in: 3600 }), { status: 200 });
      calls.push(`${init?.method} ${u.split("/workbook")[1]}`);
      if (u.endsWith("/delete")) return new Response("busy", { status: 503 });
      const s = statuses.shift() ?? 200;
      return new Response(s === 200 ? JSON.stringify({ values: [["x"]] }) : "locked", { status: s });
    }) as unknown as typeof fetch;
    const g = new GraphBackend({ tenantId: "t", clientId: "c", clientSecret: "s", driveId: "d", itemId: "i", label: "", fetchImpl: fakeFetch, sleep: async () => {} });

    await expect(g.getRange(VERIFY_SHEET, "A2:Q2")).resolves.toEqual([["x"]]);
    expect(calls).toHaveLength(3);

    calls.length = 0;
    await expect(g.deleteShiftUp(VERIFY_SHEET, "A2:Q2", "sid")).rejects.toThrow(SheetBusyError);
    expect(calls).toHaveLength(1);
  });
});
