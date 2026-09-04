"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { Home, Radio, Search, Ticket, User } from "lucide-react";
import { useState } from "react";
import { cn } from "@/lib/utils";
import { GlobalSearch } from "./global-search";

const ITEMS: { href: string; label: string; icon: typeof Home; badge?: string }[] = [
  { href: "/", label: "Sports", icon: Home },
  { href: "/live", label: "Live", icon: Radio },
  { href: "__search", label: "Search", icon: Search },
  { href: "/my-bets", label: "My Bets", icon: Ticket },
  { href: "/account", label: "Account", icon: User },
];

export function MobileNav() {
  const pathname = usePathname();
  const [searchOpen, setSearchOpen] = useState(false);

  return (
    <>
      {/* Floating bottom nav — detached pill that hovers over content and
          stays fixed while scrolling. The wrapper ignores pointer events so
          taps pass through its transparent margins; the pill re-enables them. */}
      <nav className="xl:hidden fixed inset-x-0 bottom-0 z-40 px-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] pointer-events-none">
        <div className="pointer-events-auto mx-auto max-w-md grid grid-cols-5 h-[60px] glass rounded-xl border border-[var(--color-line)] shadow-[0_10px_34px_-10px_rgba(0,0,0,.7)]">
          {ITEMS.map((it) => {
            const Icon = it.icon;
            const active = it.href !== "__search" && (it.href === "/" ? pathname === "/" : pathname.startsWith(it.href));
            const content = (
              <span className="relative flex flex-col items-center justify-center gap-1 h-full">
                <span className="relative">
                  <Icon size={20} className={cn(active ? "text-[var(--color-brand-hi)]" : "text-[var(--color-ink-faint)]")} strokeWidth={active ? 2.4 : 2} />
                  {it.badge && (
                    <span className="absolute -top-1.5 -right-2 num text-[8px] font-bold grad-brand text-white rounded-full min-w-[14px] h-[14px] grid place-items-center px-0.5">
                      {it.badge}
                    </span>
                  )}
                </span>
                <span className={cn("text-[9.5px] font-semibold", active ? "text-white" : "text-[var(--color-ink-faint)]")}>{it.label}</span>
                {active && <span className="absolute top-0 w-9 h-[2.5px] rounded-b-sm grad-brand" />}
              </span>
            );
            if (it.href === "__search") {
              return (
                <button key={it.label} onClick={() => setSearchOpen(true)}>
                  {content}
                </button>
              );
            }
            return (
              <Link key={it.label} href={it.href}>
                {content}
              </Link>
            );
          })}
        </div>
      </nav>
      <GlobalSearch open={searchOpen} onClose={() => setSearchOpen(false)} />
    </>
  );
}
