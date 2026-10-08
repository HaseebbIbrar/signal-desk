// LIVE integration test against the DEV COPY workbook (never the master).
// Run: npm run test:dev-copy   (loads .env.local; requires APP_ENV=development, WORKBOOK_BACKEND=graph)
//
// Exercises the app's real write path (service + GraphBackend):
//   tick 2 signals from the latest block, untick 1, add a LinkedIn link to the other,
//   then re-reads both tabs fresh via Graph and checks the writes landed and "Signals" is untouched.
// It leaves ONE appended test row (with a test LinkedIn link) in the DEV COPY's verify tab.

import { describe, expect, it } from "vitest";
import { type AuditEntry, consoleAudit } from "@/lib/audit";
import { isBlank, usedRangeToRows } from "@/lib/blocks";
import { getServerConfig } from "@/lib/config";
import { C, MASTER_FILE_NAME, SIGNALS_SHEET, VERIFY_SHEET } from "@/lib/constants";
import { normaliseJobUrl } from "@/lib/job-url";
import { createSignalService } from "@/lib/service";
import { GraphBackend } from "@/lib/workbook/graph";

const TEST_LINK = "https://www.linkedin.com/in/signal-desk-dev-test";

async function graphGet(path: string): Promise<unknown> {
  const cfg = getServerConfig().graph!;
  const tok = await fetch(`https://login.microsoftonline.com/${cfg.tenantId}/oauth2/v2.0/token`, {
    method: "POST",
    body: new URLSearchParams({
      grant_type: "client_credentials",
      client_id: cfg.clientId,
      client_secret: cfg.clientSecret,
      scope: "https://graph.microsoft.com/.default",
    }),
  }).then((r) => r.json() as Promise<{ access_token: string }>);
  const res = await fetch(`https://graph.microsoft.com/v1.0/drives/${cfg.driveId}/items/${cfg.itemId}${path}`, {
    headers: { Authorization: `Bearer ${tok.access_token}` },
  });
  if (!res.ok) throw new Error(`GET ${path} -> ${res.status}`);
  return res.json();
}

describe("DEV COPY live write test", () => {
  it("tick 2, untick 1, save a decision maker on the other; Signals untouched", { timeout: 300_000 }, async () => {
    // ---- safety: refuse unless we are pointed at the DEV COPY
    const cfg = getServerConfig();
    expect(cfg.appEnv).toBe("development");
    expect(cfg.backend).toBe("graph");
    expect(cfg.graph!.itemId).toBe(process.env.GRAPH_DEV_ITEM_ID);
    expect(cfg.graph!.itemId).not.toBe(process.env.GRAPH_MASTER_ITEM_ID);
    const item = (await graphGet("?$select=name")) as { name: string };
    expect(item.name).not.toBe(MASTER_FILE_NAME);
    console.log(`Target workbook: ${item.name}`);

    const audits: AuditEntry[] = [];
    const backend = new GraphBackend({ ...cfg.graph!, label: cfg.label });
    const service = createSignalService({ backend, audit: (e) => (audits.push(e), consoleAudit(e)), cacheTtlMs: 0 });

    // ---- snapshot BEFORE (fresh reads)
    const sigBefore = await backend.getUsedRange(SIGNALS_SHEET);
    const verBefore = await backend.getUsedRange(VERIFY_SHEET);
    const verRowsBefore = usedRangeToRows(verBefore);
    const lastBefore = Math.max(...verRowsBefore.filter((r) => r.cells.some((c) => !isBlank(c))).map((r) => r.rowNumber));
    console.log(`Before: Signals ${sigBefore.values.length} rows; verify last data row ${lastBefore}`);

    // ---- pick 2 un-ticked signals with distinct Job URLs from the latest block
    const view = await service.getSignalsView();
    const candidates = view.rows.filter((r) => r.key && !r.ticked);
    const unique = candidates.filter((r, i) => candidates.findIndex((x) => x.key === r.key) === i);
    expect(unique.length).toBeGreaterThanOrEqual(2);
    const [a, b] = unique;
    console.log(`Latest block ${view.selectedDate}: ${view.rows.length} signals. Picked:\n  A: ${a.company} - ${a.jobTitle} (Signals row ${a.rowNumber})\n  B: ${b.company} - ${b.jobTitle} (Signals row ${b.rowNumber})`);

    // ---- 1) tick A and B
    const tA = await service.tick(a.jobUrl, "integration-test");
    const tB = await service.tick(b.jobUrl, "integration-test");
    expect(tA).toMatchObject({ status: "appended", row: lastBefore + 1 });
    expect(tB).toMatchObject({ status: "appended", row: lastBefore + 2 });

    // ---- 2) untick A (M empty) -> B shifts up into A's row
    const uA = await service.untick(a.jobUrl, "integration-test");
    expect(uA).toEqual({ status: "removed", row: lastBefore + 1 });

    // ---- 3) decision maker on B, located by Job URL (now at lastBefore + 1)
    const dm = await service.saveDecisionMaker(b.jobUrl, [TEST_LINK], "integration-test");
    expect(dm).toEqual({ row: lastBefore + 1, links: [TEST_LINK], value: TEST_LINK });

    // ---- re-read AFTER via Graph (fresh backend instance, no cache)
    const fresh = new GraphBackend({ ...cfg.graph!, label: cfg.label });
    const sigAfter = await fresh.getUsedRange(SIGNALS_SHEET);
    const verAfter = await fresh.getUsedRange(VERIFY_SHEET);
    const verRowsAfter = usedRangeToRows(verAfter);

    // Signals tab: identical, cell for cell
    expect(sigAfter.startRow).toBe(sigBefore.startRow);
    expect(sigAfter.startCol).toBe(sigBefore.startCol);
    expect(JSON.stringify(sigAfter.values)).toBe(JSON.stringify(sigBefore.values));

    // verify tab: every pre-existing row unchanged
    for (let r = 1; r <= lastBefore; r++) {
      const before = verRowsBefore.find((x) => x.rowNumber === r)!.cells;
      const after = verRowsAfter.find((x) => x.rowNumber === r)!.cells;
      expect(after, `row ${r} changed`).toEqual(before);
    }

    // exactly one new row (B) at lastBefore + 1; nothing below it
    const newRow = verRowsAfter.find((x) => x.rowNumber === lastBefore + 1)!.cells;
    const below = verRowsAfter.filter((x) => x.rowNumber > lastBefore + 1 && x.cells.some((c) => !isBlank(c)));
    expect(below).toEqual([]);
    expect(normaliseJobUrl(newRow[C.jobUrl])).toBe(b.key);
    expect(newRow[C.company]).toBe(b.company);
    expect(newRow[C.decisionMaker]).toBe(TEST_LINK);
    expect(typeof newRow[C.dateFound]).toBe("number"); // real Excel date serial

    // A is gone from the verify tab
    expect(verRowsAfter.some((x) => normaliseJobUrl(x.cells[C.jobUrl]) === a.key)).toBe(false);

    // the new row's 17 cells equal the source Signals row (M aside, which we wrote)
    const src = usedRangeToRows(sigAfter).find((x) => x.rowNumber === b.rowNumber)!.cells;
    newRow.forEach((v, i) => {
      if (i === C.decisionMaker) return;
      expect(v, `column ${i}`).toEqual(src[i] ?? "");
    });

    // date number format on column A of the new row
    const fmt = (await graphGet(
      `/workbook/worksheets('${encodeURIComponent(VERIFY_SHEET)}')/range(address='A${lastBefore + 1}')?$select=numberFormat,text`,
    )) as { numberFormat: string[][]; text: string[][] };
    expect(fmt.numberFormat[0][0]).toBe("yyyy-mm-dd");
    console.log(`New row ${lastBefore + 1}: A shows "${fmt.text[0][0]}", M = ${String(newRow[C.decisionMaker])}`);

    // audit trail
    expect(audits.map((x) => [x.action, x.row, x.outcome])).toEqual([
      ["tick_append", lastBefore + 1, "ok"],
      ["tick_append", lastBefore + 2, "ok"],
      ["untick_remove", lastBefore + 1, "ok"],
      ["write_decision_maker", lastBefore + 1, "ok"],
    ]);
    console.log("RESULT: all checks passed. Signals tab identical; verify tab +1 row.");
  });
});
