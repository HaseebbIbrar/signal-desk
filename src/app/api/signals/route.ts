import { route } from "@/lib/api";
import { ValidationError } from "@/lib/errors";
import { getService } from "@/lib/workbook";

export const runtime = "nodejs";
export const dynamic = "force-dynamic";
export const maxDuration = 60;

export const GET = route(async ({ req }) => {
  const date = new URL(req.url).searchParams.get("date");
  if (date && !/^\d{4}-\d{2}-\d{2}$/.test(date)) throw new ValidationError("date must be yyyy-mm-dd");
  return getService().getSignalsView(date);
});
