// Browser-side fetch helper. When the server answers SHEET_BUSY (workbook locked, e.g. while the
// nightly job saves), it waits and retries, reporting each attempt so the UI can show
// "Sheet busy, retrying…". It never fails silently: after the last attempt it throws.

export class ApiError extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    message: string,
  ) {
    super(message);
  }
}

interface ApiOptions {
  method?: "GET" | "POST";
  body?: unknown;
  csrf?: string;
  /** Called with the retry attempt number while the sheet is busy, and with 0 when done. */
  onBusy?: (attempt: number) => void;
  maxBusyRetries?: number;
}

const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));

export async function apiFetch<T>(path: string, opts: ApiOptions = {}): Promise<T> {
  const { method = "GET", body, csrf, onBusy, maxBusyRetries = 5 } = opts;
  try {
    for (let attempt = 0; ; attempt++) {
      let res: Response;
      try {
        res = await fetch(path, {
          method,
          headers: {
            ...(body !== undefined ? { "Content-Type": "application/json" } : {}),
            ...(csrf ? { "x-csrf-token": csrf } : {}),
          },
          body: body !== undefined ? JSON.stringify(body) : undefined,
          credentials: "same-origin",
          cache: "no-store",
        });
      } catch {
        throw new ApiError(0, "NETWORK", "Network error. Check your connection and try again.");
      }

      if (res.status === 401) {
        window.location.href = "/login";
        throw new ApiError(401, "UNAUTHENTICATED", "Please sign in again.");
      }

      const data = (await res.json().catch(() => ({}))) as { error?: string; code?: string };
      if (res.status === 503 && data.code === "SHEET_BUSY") {
        if (attempt < maxBusyRetries) {
          onBusy?.(attempt + 1);
          await sleep(1500 * (attempt + 1));
          continue;
        }
        throw new ApiError(503, "SHEET_BUSY", "The sheet is still busy (probably the nightly update). Nothing was lost. Please try again in a minute.");
      }
      if (!res.ok) throw new ApiError(res.status, data.code ?? "ERROR", data.error ?? `Request failed (${res.status})`);
      return data as T;
    }
  } finally {
    onBusy?.(0);
  }
}

export function busyText(attempt: number): string | null {
  return attempt > 0 ? `Sheet busy, retrying… (attempt ${attempt})` : null;
}
