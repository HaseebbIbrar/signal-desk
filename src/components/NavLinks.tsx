"use client";
import Link from "next/link";
import { usePathname } from "next/navigation";

const LINKS = [
  { href: "/signals", label: "Today" },
  { href: "/verified", label: "Verified" },
];

export default function NavLinks() {
  const path = usePathname();
  return (
    <nav className="mx-auto flex max-w-3xl gap-1 px-3 sm:px-4" aria-label="Main">
      {LINKS.map((l) => {
        const active = path.startsWith(l.href);
        return (
          <Link
            key={l.href}
            href={l.href}
            aria-current={active ? "page" : undefined}
            className={`border-b-2 px-3 py-2 text-sm font-medium ${active ? "border-pink text-white" : "border-transparent text-white/60 hover:text-white"}`}
          >
            {l.label}
          </Link>
        );
      })}
    </nav>
  );
}
