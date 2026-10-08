import { route } from "@/lib/api";
import { getService } from "@/lib/workbook";

export const runtime = "nodejs";
export const dynamic = "force-dynamic";
export const maxDuration = 60;

export const GET = route(async () => getService().getVerifiedView());
