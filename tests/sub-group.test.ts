// Column R "Role Sub-Group" (added 9 Oct 2026): A–R rows, old-style rows with an empty R, and a
// verify tab whose header R1 is missing.

import { afterEach, describe, expect, it, vi } from "vitest";
import { type Cell, VERIFY_SHEET } from "@/lib/constants";
import { header, headerAR, separator, setup, signal } from "./helpers";

const D_OLD = "2026-10-08"; // before the change: old group names, empty R
const D_NEW = "2026-10-09"; // after the change: A–K groups + sub-group in R

const NEW_URL = "https://jobs.test/servicedesk";
const OLD_URL = "https://jobs.test/legacy-data";

function signalsTab(): Cell[][] {
  return [
    headerAR(),
    separator(D_OLD),
    signal(D_OLD, "Legacy Co", OLD_URL, { 6: "B - Data & AI", 17: "" }),
    separator(D_NEW),
    signal(D_NEW, "Helpdesk NV", NEW_URL, { 6: "K - IT Service, Support & Workplace", 17: "K1 - Service Desk 2nd / 3rd Line" }),
    signal(D_NEW, "Backend BV", "https://jobs.test/backend", { 6: "C - Software Development", 17: "C1 - Back-end (Java, .NET, Python, PHP)" }),
  ];
}

const verifyWithR1 = (): Cell[][] => [headerAR(), signal("2026-10-01", "Kept One", "https://jobs.test/k1", { 17: "" })];
const verifyWithoutR1 = (): Cell[][] => [header(), signal("2026-10-01", "Kept One", "https://jobs.test/k1")];

/** Cells of one memory-sheet row (1-based), A:S, so we can see whether R or S was touched. */
function rowAS(backend: { grid: Map<string, Cell[][]> }, sheet: string, rowNumber: number): Cell[] {
  const r = backend.grid.get(sheet)?.[rowNumber - 1] ?? [];
  return Array.from({ length: 19 }, (_, c) => r[c] ?? null);
}

afterEach(() => vi.restoreAllMocks());

describe("Role Sub-Group (column R)", () => {
  it("an A–R row: tick appends A:R including the sub-group, and both pages show it", async () => {
    const { backend, service, audits } = setup({ signals: signalsTab(), verify: verifyWithR1() });

    const res = await service.tick(NEW_URL, "jp");
    expect(res).toMatchObject({ status: "appended", row: 3 });
    expect(backend.writes[0]).toEqual({ op: "patchValues", sheet: VERIFY_SHEET, address: "A3:R3" });

    const row = rowAS(backend, VERIFY_SHEET, 3);
    expect(row[2]).toBe("Helpdesk NV");
    expect(row[6]).toBe("K - IT Service, Support & Workplace");
    expect(row[17]).toBe("K1 - Service Desk 2nd / 3rd Line");
    expect(row[18]).toBeNull(); // nothing past R
    expect(audits.at(-1)).toMatchObject({ action: "tick_append", outcome: "ok", detail: "A:R" });

    const today = await service.getSignalsView(D_NEW);
    expect(today.rows.find((r) => r.company === "Helpdesk NV")).toMatchObject({
      roleGroup: "K - IT Service, Support & Workplace",
      roleSubGroup: "K1 - Service Desk 2nd / 3rd Line",
      ticked: true,
    });
    const verified = await service.getVerifiedView();
    expect(verified.rows.find((r) => r.company === "Helpdesk NV")?.roleSubGroup).toBe("K1 - Service Desk 2nd / 3rd Line");
  });

  it("an old-style row with empty R: appended as A:R with an empty R, old group name kept", async () => {
    const { backend, service } = setup({ signals: signalsTab(), verify: verifyWithR1() });

    await service.tick(OLD_URL, "jp");
    expect(backend.writes[0].address).toBe("A3:R3");
    const row = rowAS(backend, VERIFY_SHEET, 3);
    expect(row[6]).toBe("B - Data & AI");
    expect(row[17]).toBe("");

    const old = (await service.getSignalsView(D_OLD)).rows[0];
    expect(old).toMatchObject({ roleGroup: "B - Data & AI", roleSubGroup: "", ticked: true });
  });

  it("old and new group names both reach the page as-is (nothing is mapped or hard-coded)", async () => {
    const { service } = setup({ signals: signalsTab(), verify: verifyWithR1() });
    const groups = [
      ...(await service.getSignalsView(D_OLD)).rows,
      ...(await service.getSignalsView(D_NEW)).rows,
    ].map((r) => r.roleGroup);
    expect(groups).toEqual(["B - Data & AI", "K - IT Service, Support & Workplace", "C - Software Development"]);
  });

  it("a verify tab without R1: appends A:Q only, logs a warning, never writes R or the header", async () => {
    const warn = vi.spyOn(console, "warn").mockImplementation(() => {});
    const { backend, service, audits } = setup({ signals: signalsTab(), verify: verifyWithoutR1() });

    const res = await service.tick(NEW_URL, "jp");
    expect(res).toMatchObject({ status: "appended", row: 3 });
    expect(backend.writes.map((w) => w.address)).toEqual(["A3:Q3", "A3"]); // values A:Q, then the date format on A
    expect(rowAS(backend, VERIFY_SHEET, 3)[17]).toBeNull(); // R untouched
    expect(rowAS(backend, VERIFY_SHEET, 1)[17]).toBeNull(); // header R1 not written
    expect(warn).toHaveBeenCalledTimes(1);
    expect(String(warn.mock.calls[0][0])).toContain('R1 is not \\"Role Sub-Group\\"');
    expect(audits.at(-1)).toMatchObject({ action: "tick_append", outcome: "ok" });
    expect(audits.at(-1)?.detail).toMatch(/A:Q only/);
  });

  it("a verify tab without R1: content in column R is not shown as a sub-group and doesn't move appends", async () => {
    const verify = verifyWithoutR1();
    verify[1][17] = "JP's private note in R";
    verify.push(Array<Cell>(17).fill(""), [...Array<Cell>(17).fill(""), "stray value lower down in R"]);
    const { backend, service } = setup({ signals: signalsTab(), verify });

    const v = await service.getVerifiedView();
    expect(v.rows).toHaveLength(1);
    expect(v.rows[0].roleSubGroup).toBe("");

    vi.spyOn(console, "warn").mockImplementation(() => {});
    const res = await service.tick(NEW_URL, "jp");
    expect(res.row).toBe(3); // right after the last A:Q data row, not after the stray R value
    expect(rowAS(backend, VERIFY_SHEET, 2)[17]).toBe("JP's private note in R");
  });

  it("untick removes the same width it appended: A:R with R1, A:Q without", async () => {
    const withR = setup({ signals: signalsTab(), verify: verifyWithR1() });
    await withR.service.tick(NEW_URL, "jp");
    withR.backend.writes.length = 0;
    await withR.service.untick(NEW_URL, "jp");
    expect(withR.backend.writes.map((w) => [w.op, w.address])).toEqual([
      ["clearContents", "A3:R3"],
      ["deleteShiftUp", "A3:R3"],
    ]);
    expect(rowAS(withR.backend, VERIFY_SHEET, 3)[17]).toBeNull(); // no orphaned sub-group left behind

    vi.spyOn(console, "warn").mockImplementation(() => {});
    const withoutR = setup({ signals: signalsTab(), verify: verifyWithoutR1() });
    await withoutR.service.tick(NEW_URL, "jp");
    withoutR.backend.writes.length = 0;
    await withoutR.service.untick(NEW_URL, "jp");
    expect(withoutR.backend.writes.map((w) => w.address)).toEqual(["A3:Q3", "A3:Q3"]);
  });
});
