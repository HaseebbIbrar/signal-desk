import { NextResponse } from "next/server";
import { hasValidCsrf, isSameOrigin } from "@/lib/auth/request";
import { getSession, SESSION_COOKIE, sessionCookieOptions } from "@/lib/auth/session";

export const runtime = "nodejs";
export const dynamic = "force-dynamic";

export async function POST(req: Request) {
  const session = await getSession();
  if (session && (!isSameOrigin(req) || !hasValidCsrf(req, session))) {
    return NextResponse.json({ error: "Refused", code: "BAD_CSRF" }, { status: 403 });
  }
  const res = NextResponse.json({ ok: true }, { headers: { "Cache-Control": "no-store" } });
  res.cookies.set(SESSION_COOKIE, "", { ...sessionCookieOptions, maxAge: 0 });
  return res;
}
