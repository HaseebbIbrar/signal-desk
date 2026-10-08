// Decision-maker LinkedIn link validation. Shared by the browser (instant feedback) and the server
// (the authority). Only linkedin.com/in/… and linkedin.com/sales/… links are accepted.

export const MAX_LINKS_PER_CELL = 10;
const MAX_LINK_LENGTH = 500;
const HOST = /^(?:www\.|[a-z]{2}\.)?linkedin\.com$/;

export type LinkResult = { ok: true; url: string } | { ok: false; error: string };

export function normaliseLinkedInUrl(input: string): LinkResult {
  let s = String(input ?? "").trim();
  if (!s) return { ok: false, error: "Empty link" };
  if (s.length > MAX_LINK_LENGTH) return { ok: false, error: "Link is too long" };
  if (/\s/.test(s)) return { ok: false, error: "Link must not contain spaces" };
  if (!/^[a-z][a-z0-9+.-]*:\/\//i.test(s)) s = `https://${s}`;

  let u: URL;
  try {
    u = new URL(s);
  } catch {
    return { ok: false, error: "Not a valid link" };
  }
  if (u.protocol !== "https:" && u.protocol !== "http:") return { ok: false, error: "Not a valid link" };
  if (u.username || u.password || u.port) return { ok: false, error: "Not a valid link" };
  if (!HOST.test(u.hostname.toLowerCase())) return { ok: false, error: "Must be a linkedin.com link" };

  const m = /^\/(in|sales)\/[^/]+/.exec(u.pathname);
  if (!m) return { ok: false, error: "Use a linkedin.com/in/… or linkedin.com/sales/… link" };

  const path = u.pathname.replace(/\/+$/, "");
  // Profile links: tracking query strings (e.g. ?originalSubdomain=be) are dropped.
  // Sales Navigator links can carry meaningful query parameters, so they are kept.
  const url = m[1] === "in" ? `https://www.linkedin.com${path}` : `https://www.linkedin.com${path}${u.search}`;
  return { ok: true, url };
}

/**
 * Validate a list of inputs. Blank inputs are ignored. `errors` is aligned with `inputs`.
 * `links` holds the normalised, de-duplicated links in input order.
 */
export function validateLinks(inputs: readonly string[]): { links: string[]; errors: (string | null)[]; tooMany: boolean } {
  const links: string[] = [];
  const errors: (string | null)[] = inputs.map(() => null);
  inputs.forEach((raw, i) => {
    if (!String(raw ?? "").trim()) return;
    const r = normaliseLinkedInUrl(raw);
    if (!r.ok) errors[i] = r.error;
    else if (!links.includes(r.url)) links.push(r.url);
  });
  return { links, errors, tooMany: links.length > MAX_LINKS_PER_CELL };
}

/** Split a column M cell into individual links (one per line). */
export function splitCellLinks(value: unknown): string[] {
  if (value === null || value === undefined) return [];
  return String(value)
    .split(/\r?\n|\r/)
    .map((s) => s.trim())
    .filter(Boolean);
}
