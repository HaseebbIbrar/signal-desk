import "server-only";
import { randomBytes } from "node:crypto";
import { jwtVerify, SignJWT } from "jose";
import { cookies } from "next/headers";
import { redirect } from "next/navigation";
import { findUser, type AppUser } from "./users";

// Stateless session: an HS256-signed JWT in an httpOnly cookie. It carries a per-session CSRF
// token, which the pages hand to the browser and write routes require in the x-csrf-token header.

const isProd = process.env.NODE_ENV === "production";
export const SESSION_COOKIE = isProd ? "__Host-sd_session" : "sd_session";
export const SESSION_MAX_AGE_S = 12 * 60 * 60;
const ISSUER = "signal-desk";

export interface Session {
  email: string;
  name: string;
  csrf: string;
}

function secretKey(): Uint8Array {
  const s = process.env.SESSION_SECRET ?? "";
  if (s.length < 32) throw new Error("SESSION_SECRET must be set to at least 32 random characters");
  return new TextEncoder().encode(s);
}

export async function createSessionToken(user: AppUser): Promise<string> {
  const csrf = randomBytes(32).toString("base64url");
  return new SignJWT({ name: user.name, csrf })
    .setProtectedHeader({ alg: "HS256" })
    .setSubject(user.email)
    .setIssuer(ISSUER)
    .setAudience(ISSUER)
    .setIssuedAt()
    .setExpirationTime(`${SESSION_MAX_AGE_S}s`)
    .sign(secretKey());
}

export async function readSessionToken(token: string | undefined): Promise<Session | null> {
  if (!token) return null;
  try {
    const { payload } = await jwtVerify(token, secretKey(), { algorithms: ["HS256"], issuer: ISSUER, audience: ISSUER });
    if (typeof payload.sub !== "string" || typeof payload.csrf !== "string") return null;
    const user = findUser(payload.sub); // removing a user from AUTH_USERS revokes their session
    if (!user) return null;
    return { email: user.email, name: user.name, csrf: payload.csrf };
  } catch {
    return null;
  }
}

export async function getSession(): Promise<Session | null> {
  const jar = await cookies();
  return readSessionToken(jar.get(SESSION_COOKIE)?.value);
}

/** For pages: redirect to /login when there is no valid session. */
export async function requirePageSession(): Promise<Session> {
  const s = await getSession();
  if (!s) redirect("/login");
  return s;
}

export const sessionCookieOptions = {
  httpOnly: true,
  secure: isProd,
  sameSite: "lax" as const,
  path: "/",
  maxAge: SESSION_MAX_AGE_S,
};
