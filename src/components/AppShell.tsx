import { getServerConfig } from "@/lib/config";
import LogoutButton from "./LogoutButton";
import NavLinks from "./NavLinks";

export default function AppShell({ name, csrf, children }: { name: string; csrf: string; children: React.ReactNode }) {
  let label = "";
  try {
    label = getServerConfig().label;
  } catch {
    label = "CONFIG ERROR";
  }
  return (
    <div className="min-h-dvh">
      {label && (
        <div className="bg-warn px-4 py-1 text-center text-xs font-bold tracking-wide text-black">
          {label === "MOCK DATA" ? "MOCK DATA: not connected to SharePoint" : label === "DEV COPY" ? "DEV COPY: writing to the test copy of the workbook" : label}
        </div>
      )}
      <header className="sticky top-0 z-20 border-b border-line/60 bg-black/70 backdrop-blur">
        <div className="mx-auto flex max-w-3xl items-center justify-between gap-3 px-4 py-3">
          <div className="flex items-center gap-2">
            <span className="h-2.5 w-2.5 rounded-full bg-pink" aria-hidden />
            <span className="font-semibold tracking-tight">Signal Desk</span>
          </div>
          <div className="flex items-center gap-3 text-sm">
            <span className="hidden text-white/60 sm:inline">{name}</span>
            <LogoutButton csrf={csrf} />
          </div>
        </div>
        <NavLinks />
      </header>
      <main className="mx-auto max-w-3xl px-3 pb-16 pt-4 sm:px-4">{children}</main>
    </div>
  );
}
