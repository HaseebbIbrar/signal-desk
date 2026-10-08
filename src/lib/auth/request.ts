import "server-only";
import { timingSafeEqual } from "node:crypto";
import type { Session } from "./session";

/** Write routes and login only accept requests whose Origin is this app. */
export function isSameOrigin(req: Request): boolean {
  const origin = req.headers.get("origin");
  if (!origin) return false;
  const fetchSite = req.headers.get("sec-fetch-site");
  if (fetchSite && fetchSite !== "same-origin") return false;

  const configured = process.env.APP_ORIGIN?.trim().replace(/\/$/, "");
  if (configured) return origin === configured;

  const allowed = new Set<string>([new URL(req.url).origin]);
  const host = req.headers.get("x-forwarded-host") ?? req.headers.get("host");
  if (host) {
    const proto = req.headers.get("x-forwarded-proto") ?? new URL(req.url).protocol.replace(":", "");
    allowed.add(`${proto}://${host}`);
  }
  return allowed.has(origin);
}

export function hasValidCsrf(req: Request, session: Session): boolean {
  const sent = req.headers.get("x-csrf-token") ?? "";
  const a = Buffer.from(sent);
  const b = Buffer.from(session.csrf);
  return a.length === b.length && timingSafeEqual(a, b);
}

export function clientIp(req: Request): string {
  return (
    req.headers.get("x-real-ip") ??
    req.headers.get("x-forwarded-for")?.split(",")[0]?.trim() ??
    "unknown"
  );
}
