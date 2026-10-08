"use client";

export default function LogoutButton({ csrf }: { csrf: string }) {
  async function logout() {
    await fetch("/api/auth/logout", { method: "POST", headers: { "x-csrf-token": csrf }, credentials: "same-origin" }).catch(() => undefined);
    window.location.href = "/login";
  }
  return (
    <button type="button" onClick={logout} className="rounded-lg border border-line px-3 py-1.5 text-white/80 hover:border-pink hover:text-white">
      Log out
    </button>
  );
}
