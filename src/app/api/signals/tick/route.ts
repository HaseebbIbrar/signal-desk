import { readJsonBody, route } from "@/lib/api";
import { getService } from "@/lib/workbook";

export const runtime = "nodejs";
export const dynamic = "force-dynamic";
export const maxDuration = 60;

export const POST = route(
  async ({ req, session }) => {
    const body = await readJsonBody(req);
    return getService().tick(body.jobUrl, session.email);
  },
  { write: true },
);
