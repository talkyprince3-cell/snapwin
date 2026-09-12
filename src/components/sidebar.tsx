"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { X } from "lucide-react";
import { sports, competitions } from "@/lib/data";
import { cn } from "@/lib/utils";

const BROWSE: { href: string; icon: string; label: string; active?: boolean; count?: number }[] = [
  { href: "/", icon: "🏠", label: "Sports", active: true },
  { href: "/live", icon: "🔴", label: "Live Now" },
  { href: "/my-bets", icon: "🎫", label: "My Bets" },
  { href: "/booking", icon: "📥", label: "Booking Code" },
  { href: "/account", icon: "⭐", label: "Favourites" },
  { href: "/account", icon: "🎁", label: "Promotions" },
];

function SidebarInner({ onNavigate }: { onNavigate?: () => void }) {
  const pathname = usePathname();
  return (
    <div className="flex flex-col gap-6 py-5 px-3">
      <Section label="Browse">
        {BROWSE.map((b, i) => {
          const active = b.href === "/" ? pathname === "/" : pathname.startsWith(b.href);
          return (
            <Link
              key={i}
              href={b.href}
              onClick={onNavigate}
              className={cn(
                "flex items-center justify-between rounded-lg px-3 py-2 text-[13px] font-medium transition-colors",
                active ? "bg-[var(--color-surface-2)] text-white" : "text-[var(--color-ink-dim)] hover:text-white hover:bg-white/5",
              )}
            >
              <span className="flex items-center gap-2.5">
                <span>{b.icon}</span> {b.label}
              </span>
              {b.count != null && (
                <span className="num text-[10px] font-bold text-[var(--color-cyan)] bg-[var(--color-cyan)]/10 rounded-md px-1.5 py-0.5">
                  {b.count}
                </span>
              )}
            </Link>
          );
        })}
      </Section>

      <Section label="Top Competitions">
        {competitions.map((c) => (
          <Link
            key={c.id}
            href="/"
            onClick={onNavigate}
            className="flex items-center justify-between rounded-lg px-3 py-2 text-[13px] text-[var(--color-ink-dim)] hover:text-white hover:bg-white/5 transition-colors"
          >
            <span className="flex items-center gap-2.5 min-w-0">
              <span>{c.flag}</span> <span className="truncate">{c.name}</span>
            </span>
          </Link>
        ))}
      </Section>

      <Section label="All Sports">
        {sports.map((s) => (
          <Link
            key={s.id}
            href="/"
            onClick={onNavigate}
            className="flex items-center justify-between rounded-lg px-3 py-2 text-[13px] text-[var(--color-ink-dim)] hover:text-white hover:bg-white/5 transition-colors"
          >
            <span className="flex items-center gap-2.5">
              <span>{s.icon}</span> {s.name}
            </span>
          </Link>
        ))}
      </Section>
    </div>
  );
}

function Section({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <div className="px-3 mb-1.5 font-mono text-[9.5px] uppercase tracking-[0.16em] text-[var(--color-ink-faint)]">
        {label}
      </div>
      <div className="space-y-0.5">{children}</div>
    </div>
  );
}

export function Sidebar() {
  return (
    <aside className="hidden lg:block w-[240px] shrink-0 sticky top-[60px] h-[calc(100dvh-60px)] overflow-y-auto no-scrollbar border-r border-[var(--color-line)]">
      <SidebarInner />
    </aside>
  );
}

export function MobileSidebar({ open, onClose }: { open: boolean; onClose: () => void }) {
  if (!open) return null;
  return (
    <div className="lg:hidden fixed inset-0 z-50 flex">
      <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={onClose} />
      <div className="relative w-[280px] max-w-[82vw] h-full bg-[var(--color-bg-2)] border-r border-[var(--color-line)] overflow-y-auto no-scrollbar animate-rise">
        <div className="flex items-center justify-between px-4 py-3.5 border-b border-[var(--color-line)] sticky top-0 glass z-10">
          <span className="font-mono text-[10px] uppercase tracking-[0.16em] text-[var(--color-ink-dim)]">Menu</span>
          <button onClick={onClose} className="text-[var(--color-ink-dim)] hover:text-white">
            <X size={20} />
          </button>
        </div>
        <SidebarInner onNavigate={onClose} />
      </div>
    </div>
  );
}
