import { redirect } from "next/navigation";
import { getSession } from "@/lib/auth/session";
import LoginForm from "./LoginForm";

export const dynamic = "force-dynamic";

export default async function LoginPage() {
  if (await getSession()) redirect("/signals");
  return (
    <main className="flex min-h-dvh items-center justify-center px-4">
      <div className="w-full max-w-sm rounded-2xl border border-line bg-card/90 p-6 shadow-2xl shadow-black/50">
        <div className="mb-6 flex items-center gap-2">
          <span className="h-3 w-3 rounded-full bg-pink" aria-hidden />
          <h1 className="text-xl font-semibold tracking-tight">Signal Desk</h1>
        </div>
        <p className="mb-6 text-sm text-white/70">SwooScale · sign in to review today&apos;s job signals.</p>
        <LoginForm />
      </div>
    </main>
  );
}
