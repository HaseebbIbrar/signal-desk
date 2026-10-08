// The Job URL (column L) is the unique key for a signal row.
//
// Normalisation: trim, drop the #fragment, drop the query string, drop trailing slashes, and
// lower-case the scheme + host (paths stay case-sensitive).
//
// Exception: a few query parameters ARE the job's identity on some boards (Indeed `viewjob?jk=…`,
// LinkedIn `?currentJobId=…`, Greenhouse `?gh_jid=…`). Dropping them would make different jobs
// collide on one key, so those (and only those) are kept, sorted. Empty this set to strip every
// query parameter.
export const IDENTITY_QUERY_PARAMS = new Set(["jk", "vjk", "currentjobid", "gh_jid", "jobid", "job_id"]);

export function normaliseJobUrl(raw: unknown): string {
  if (raw === null || raw === undefined) return "";
  let s = String(raw).trim();
  if (!s) return "";

  const hash = s.indexOf("#");
  if (hash >= 0) s = s.slice(0, hash);

  let query = "";
  const q = s.indexOf("?");
  if (q >= 0) {
    query = s.slice(q + 1);
    s = s.slice(0, q);
  }

  s = s.replace(/\/+$/, "");
  s = s.replace(/^([a-z][a-z0-9+.-]*:\/\/)([^/]+)/i, (_m, scheme: string, host: string) => scheme.toLowerCase() + host.toLowerCase());

  const kept = query
    .split("&")
    .filter((p) => p && IDENTITY_QUERY_PARAMS.has(p.split("=")[0].toLowerCase()))
    .sort();

  return kept.length ? `${s}?${kept.join("&")}` : s;
}
