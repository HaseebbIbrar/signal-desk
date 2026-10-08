import { randomBytes } from "node:crypto";
import bcrypt from "bcryptjs";
import { NextResponse } from "next/server";
import { errorResponse, json, readJsonBody } from "@/lib/api";
import { isLoginLocked, recordLoginFailure, resetLoginFailures } from "@/lib/auth/rate-limit";
import { clientIp, isSameOrigin } from "@/lib/auth/request";
import { createSessionToken, SESSION_COOKIE, sessionCookieOptions } from "@/lib/auth/session";
import { findUser } from "@/lib/auth/users";

export const runtime = "nodejs";
export const dynamic = "force-dynamic";

// Compared against when the email is unknown, so response time does not reveal which emails exist.
let dummyHash: string | null = null;
const getDummyHash = () => (dummyHash ??= bcrypt.hashSync(randomBytes(16).toString("hex"), 12));

const GENERIC = "Invalid email or password.";

export async function POST(req: Request) {
  try {
    if (!isSameOrigin(req)) return json({ error: "Cross-site request refused.", code: "BAD_ORIGIN" }, 403);
    const body = await readJsonBody(req);
    const email = typeof body.email === "string" ? body.email.trim().toLowerCase().slice(0, 200) : "";
    const password = typeof body.password === "string" ? body.password : "";
    if (!email || !password || password.length > 200) return json({ error: GENERIC, code: "INVALID_LOGIN" }, 401);

    const ip = clientIp(req);
    if (await isLoginLocked(email, ip)) {
      console.warn(JSON.stringify({ type: "auth", event: "login_locked", email, ip, time: new Date().toISOString() }));
      return json({ error: "Too many failed attempts. Please wait 15 minutes and try again.", code: "RATE_LIMITED" }, 429);
    }

    const user = findUser(email);
    const ok = await bcrypt.compare(password, user?.hash ?? getDummyHash());
    if (!user || !ok) {
      await recordLoginFailure(email, ip);
      console.warn(JSON.stringify({ type: "auth", event: "login_failed", email, ip, time: new Date().toISOString() }));
      return json({ error: GENERIC, code: "INVALID_LOGIN" }, 401);
    }

    await resetLoginFailures(email);
    console.log(JSON.stringify({ type: "auth", event: "login_ok", email, ip, time: new Date().toISOString() }));
    const res = NextResponse.json({ ok: true }, { headers: { "Cache-Control": "no-store" } });
    res.cookies.set(SESSION_COOKIE, await createSessionToken(user), sessionCookieOptions);
    return res;
  } catch (e) {
    return errorResponse(e);
  }
}
