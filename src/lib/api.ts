import "server-only";
import { NextResponse } from "next/server";
import { hasValidCsrf, isSameOrigin } from "./auth/request";
import { getSession, type Session } from "./auth/session";
import { AppError, ValidationError } from "./errors";

const MAX_BODY_BYTES = 20_000;

export function json(data: unknown, status = 200): NextResponse {
  return NextResponse.json(data, { status, headers: { "Cache-Control": "no-store" } });
}

export function errorResponse(e: unknown): NextResponse {
  if (e instanceof AppError) {
    return json({ error: e.message, code: e.code }, e.status);
  }
  console.error(JSON.stringify({ type: "unhandled_error", message: e instanceof Error ? e.message : String(e) }));
  return json({ error: "Something went wrong on the server. Please try again.", code: "INTERNAL" }, 500);
}

/** Parse a small JSON object body. */
export async function readJsonBody(req: Request): Promise<Record<string, unknown>> {
  const text = await req.text();
  if (text.length > MAX_BODY_BYTES) throw new ValidationError("Request is too large");
  let body: unknown;
  try {
    body = JSON.parse(text);
  } catch {
    throw new ValidationError("Invalid JSON");
  }
  if (!body || typeof body !== "object" || Array.isArray(body)) throw new ValidationError("Expected a JSON object");
  return body as Record<string, unknown>;
}

type Handler = (ctx: { req: Request; session: Session }) => Promise<unknown>;

/** Wrap a route: session required; write routes also need same Origin, JSON and the CSRF token. */
export function route(handler: Handler, opts: { write?: boolean } = {}) {
  return async (req: Request): Promise<NextResponse> => {
    try {
      const session = await getSession();
      if (!session) return json({ error: "Please sign in again.", code: "UNAUTHENTICATED" }, 401);
      if (opts.write) {
        if (!isSameOrigin(req)) return json({ error: "Cross-site request refused.", code: "BAD_ORIGIN" }, 403);
        if (!hasValidCsrf(req, session)) return json({ error: "Security token missing or expired. Reload the page.", code: "BAD_CSRF" }, 403);
        if (!(req.headers.get("content-type") ?? "").includes("application/json")) {
          return json({ error: "Expected JSON", code: "BAD_CONTENT_TYPE" }, 415);
        }
      }
      return json(await handler({ req, session }));
    } catch (e) {
      return errorResponse(e);
    }
  };
}
