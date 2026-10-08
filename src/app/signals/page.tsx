import AppShell from "@/components/AppShell";
import { requirePageSession } from "@/lib/auth/session";
import SignalsClient from "./SignalsClient";

export const dynamic = "force-dynamic";

export default async function SignalsPage() {
  const session = await requirePageSession();
  return (
    <AppShell name={session.name} csrf={session.csrf}>
      <SignalsClient csrf={session.csrf} />
    </AppShell>
  );
}
