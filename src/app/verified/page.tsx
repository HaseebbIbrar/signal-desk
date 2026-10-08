import AppShell from "@/components/AppShell";
import { requirePageSession } from "@/lib/auth/session";
import VerifiedClient from "./VerifiedClient";

export const dynamic = "force-dynamic";

export default async function VerifiedPage() {
  const session = await requirePageSession();
  return (
    <AppShell name={session.name} csrf={session.csrf}>
      <VerifiedClient csrf={session.csrf} />
    </AppShell>
  );
}
