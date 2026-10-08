// Microsoft Graph Excel API backend. Cells are edited in place through the workbook endpoints;
// the file is never downloaded or re-uploaded.

import { parseA1 } from "../a1";
import type { UsedRange } from "../blocks";
import type { Cell } from "../constants";
import { SheetBusyError, UpstreamError } from "../errors";
import { assertWriteAllowed } from "./guard";
import type { WorkbookBackend } from "./types";

/** Locked / throttled / temporarily unavailable (e.g. while the nightly job saves the file). */
export const RETRYABLE_STATUSES = [409, 423, 429, 503, 504];
/** For non-idempotent calls (range delete) only retry when Graph clearly did NOT apply the request. */
const RETRYABLE_NOT_APPLIED = [409, 423, 429];

export interface GraphBackendOptions {
  tenantId: string;
  clientId: string;
  clientSecret: string;
  driveId: string;
  itemId: string;
  label: string;
  /** Dev safety: refuse to run if the target item has this file name. */
  forbiddenFileName?: string;
  fetchImpl?: typeof fetch;
  sleep?: (ms: number) => Promise<void>;
  maxAttempts?: number;
}

interface RequestOptions {
  body?: unknown;
  sessionId?: string;
  idempotent?: boolean;
  skipTargetCheck?: boolean;
}

const defaultSleep = (ms: number) => new Promise<void>((r) => setTimeout(r, ms));

export class GraphBackend implements WorkbookBackend {
  readonly label: string;
  private token: { value: string; expires: number } | null = null;
  private targetCheck: Promise<void> | null = null;

  constructor(private readonly o: GraphBackendOptions) {
    this.label = o.label;
  }

  private get fetchImpl(): typeof fetch {
    return this.o.fetchImpl ?? fetch;
  }

  private get sleep() {
    return this.o.sleep ?? defaultSleep;
  }

  private async getToken(): Promise<string> {
    if (this.token && this.token.expires > Date.now()) return this.token.value;
    const res = await this.fetchImpl(
      `https://login.microsoftonline.com/${encodeURIComponent(this.o.tenantId)}/oauth2/v2.0/token`,
      {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({
          grant_type: "client_credentials",
          client_id: this.o.clientId,
          client_secret: this.o.clientSecret,
          scope: "https://graph.microsoft.com/.default",
        }),
        cache: "no-store",
      },
    );
    if (!res.ok) {
      console.error(JSON.stringify({ type: "graph_token_error", status: res.status }));
      throw new UpstreamError("Could not authenticate to Microsoft Graph (check tenant/client id and secret)");
    }
    const json = (await res.json()) as { access_token: string; expires_in: number };
    this.token = { value: json.access_token, expires: Date.now() + (json.expires_in - 120) * 1000 };
    return json.access_token;
  }

  private itemBase(): string {
    return `https://graph.microsoft.com/v1.0/drives/${encodeURIComponent(this.o.driveId)}/items/${encodeURIComponent(this.o.itemId)}`;
  }

  private ws(sheet: string): string {
    return `/workbook/worksheets('${encodeURIComponent(sheet.replace(/'/g, "''"))}')`;
  }

  private rangePath(sheet: string, address: string): string {
    parseA1(address); // validates the address before it goes into the URL
    return `${this.ws(sheet)}/range(address='${address}')`;
  }

  /** Dev safety net: make sure the configured item is not the production master file. */
  private ensureTarget(): Promise<void> {
    if (!this.o.forbiddenFileName) return Promise.resolve();
    if (!this.targetCheck) {
      this.targetCheck = this.request<{ name: string }>("GET", "?$select=name", { skipTargetCheck: true }).then((item) => {
        if (item.name === this.o.forbiddenFileName) {
          throw new Error(`Dev mode refused: GRAPH_DEV_ITEM_ID points at "${item.name}". Use a COPY of the workbook.`);
        }
      });
      this.targetCheck.catch(() => (this.targetCheck = null));
    }
    return this.targetCheck;
  }

  private backoff(attempt: number): number {
    return Math.min(4000, 500 * 2 ** (attempt - 1)) + Math.floor(Math.random() * 250);
  }

  private async request<T>(method: string, path: string, opts: RequestOptions = {}): Promise<T> {
    if (!opts.skipTargetCheck) await this.ensureTarget();
    const maxAttempts = this.o.maxAttempts ?? 4;
    const retryOn = opts.idempotent === false ? RETRYABLE_NOT_APPLIED : RETRYABLE_STATUSES;
    let refreshedToken = false;

    for (let attempt = 1; attempt <= maxAttempts; attempt++) {
      const token = await this.getToken();
      let res: Response;
      try {
        res = await this.fetchImpl(this.itemBase() + path, {
          method,
          headers: {
            Authorization: `Bearer ${token}`,
            "Content-Type": "application/json",
            ...(opts.sessionId ? { "workbook-session-id": opts.sessionId } : {}),
          },
          body: opts.body === undefined ? undefined : JSON.stringify(opts.body),
          cache: "no-store",
        });
      } catch {
        if (opts.idempotent === false || attempt === maxAttempts) throw new UpstreamError("Could not reach Microsoft Graph");
        await this.sleep(this.backoff(attempt));
        continue;
      }

      if (res.ok) {
        const text = await res.text();
        return (text ? JSON.parse(text) : undefined) as T;
      }

      if (res.status === 401 && !refreshedToken) {
        refreshedToken = true;
        this.token = null;
        attempt--; // a stale token is not a busy sheet
        continue;
      }

      if (retryOn.includes(res.status) && attempt < maxAttempts) {
        const retryAfter = Number(res.headers.get("retry-after"));
        const wait = Number.isFinite(retryAfter) && retryAfter > 0 ? Math.min(retryAfter * 1000, 5000) : this.backoff(attempt);
        console.warn(JSON.stringify({ type: "graph_retry", status: res.status, method, attempt, waitMs: wait }));
        await this.sleep(wait);
        continue;
      }

      if (RETRYABLE_STATUSES.includes(res.status)) throw new SheetBusyError();

      const detail = (await res.text().catch(() => "")).slice(0, 500);
      console.error(JSON.stringify({ type: "graph_error", status: res.status, method, path, detail }));
      if (res.status === 404) throw new UpstreamError("Workbook or worksheet not found. Check the GRAPH_* ids and tab names.");
      if (res.status === 403) throw new UpstreamError("Microsoft Graph denied access. Check the Sites.Selected grant.");
      throw new UpstreamError(`Microsoft Graph error ${res.status}`);
    }
    throw new SheetBusyError();
  }

  async getUsedRange(sheet: string, sessionId?: string): Promise<UsedRange> {
    const r = await this.request<{ address: string; values: Cell[][] }>(
      "GET",
      `${this.ws(sheet)}/usedRange(valuesOnly=true)?$select=address,values`,
      { sessionId },
    );
    const a = parseA1(r.address);
    return { startRow: a.startRow, startCol: a.startCol, values: r.values ?? [] };
  }

  async getRange(sheet: string, address: string, sessionId?: string): Promise<Cell[][]> {
    const r = await this.request<{ values: Cell[][] }>("GET", `${this.rangePath(sheet, address)}?$select=values`, { sessionId });
    return r.values ?? [];
  }

  async withSession<T>(fn: (sessionId: string) => Promise<T>): Promise<T> {
    const session = await this.request<{ id: string }>("POST", "/workbook/createSession", { body: { persistChanges: true } });
    try {
      return await fn(session.id);
    } finally {
      await this.request("POST", "/workbook/closeSession", { body: {}, sessionId: session.id, idempotent: false }).catch(() => undefined);
    }
  }

  async patchValues(sheet: string, address: string, values: Cell[][], sessionId: string): Promise<void> {
    assertWriteAllowed(sheet, address);
    await this.request("PATCH", this.rangePath(sheet, address), { body: { values }, sessionId });
  }

  async patchNumberFormat(sheet: string, address: string, formats: string[][], sessionId: string): Promise<void> {
    assertWriteAllowed(sheet, address);
    await this.request("PATCH", this.rangePath(sheet, address), { body: { numberFormat: formats }, sessionId });
  }

  async clearContents(sheet: string, address: string, sessionId: string): Promise<void> {
    assertWriteAllowed(sheet, address);
    await this.request("POST", `${this.rangePath(sheet, address)}/clear`, { body: { applyTo: "Contents" }, sessionId });
  }

  async deleteShiftUp(sheet: string, address: string, sessionId: string): Promise<void> {
    assertWriteAllowed(sheet, address);
    // Not idempotent: a blind retry after a "503 but actually applied" would delete the next row.
    await this.request("POST", `${this.rangePath(sheet, address)}/delete`, { body: { shift: "Up" }, sessionId, idempotent: false });
  }
}
