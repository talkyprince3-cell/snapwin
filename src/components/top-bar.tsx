"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { Search, Plus, ChevronDown, Wallet, User, Menu } from "lucide-react";
import { Brand } from "./brand";
import { cn } from "@/lib/utils";
import { formatMoneyWithCurrency } from "@/lib/format-money";
import { getUserId, getUserName, clearUserSession } from "@/lib/user-session";
import { GlobalSearch } from "./global-search";

const NAV: { href: string; label: string; icon: string; badge?: string }[] = [
  { href: "/", label: "Sports", icon: "🏠" },
  { href: "/live", label: "Live", icon: "🔴" },
  { href: "/my-bets", label: "My Bets", icon: "🎫" },
  { href: "/bet-history", label: "History", icon: "📜" },
  { href: "/verify", label: "Verify", icon: "🎟️" },
];

function initials(name: string | null): string {
  if (!name) return "ME";
  const parts = name.trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return "ME";
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[1][0]).toUpperCase();
}

export function TopBar({ onMenu }: { onMenu?: () => void }) {
  const pathname = usePathname();
  const router = useRouter();
  const [searchOpen, setSearchOpen] = useState(false);
  const [profileOpen, setProfileOpen] = useState(false);

  // Auth state. `userId` starts null so SSR and the first client render both
  // show the logged-out (Log in / Sign up) buttons — matching markup, no
  // hydration mismatch. The effect runs after hydration and, if a session
  // exists in localStorage, swaps the cluster to the logged-in wallet view.
  const [userId, setUserId] = useState<string | null>(null);
  const [userName, setUserName] = useState<string | null>(null);
  const [balance, setBalance] = useState<number | null>(null);
  const [currency, setCurrency] = useState<string>("GHS");

  useEffect(() => {
    const id = getUserId();
    setUserId(id);
    setUserName(getUserName());
    if (!id) return;
    let alive = true;
    fetch(`/api/users/${id}`)
      .then((r) => (r.ok ? r.json() : null))
      .then((u) => {
        if (!alive || !u) return;
        setBalance(typeof u.balance === "number" ? u.balance : 0);
        if (u.currency) setCurrency(u.currency);
        if (u.name) setUserName(u.name);
      })
      .catch(() => {});
    return () => {
      alive = false;
    };
  }, [pathname]);

  const isActive = (href: string) => (href === "/" ? pathname === "/" : pathname.startsWith(href));

  const logout = () => {
    clearUserSession();
    setUserId(null);
    setUserName(null);
    setBalance(null);
    setProfileOpen(false);
    router.push("/login");
  };

  const loggedIn = !!userId;

  return (
    <>
      <header className="sticky top-0 z-40 glass">
        <div className="mx-auto max-w-[1600px] flex items-center gap-3 px-3 sm:px-5 h-[60px]">
          {/* mobile menu */}
          <button onClick={onMenu} className="lg:hidden text-[var(--color-ink-dim)] hover:text-white p-1 -ml-1">
            <Menu size={22} />
          </button>

          <Brand priority />

          {/* desktop nav */}
          <nav className="hidden lg:flex items-center gap-1 ml-4">
            {NAV.map((n) => (
              <Link
                key={n.href}
                href={n.href}
                className={cn(
                  "relative flex items-center gap-1.5 px-3 py-2 rounded-lg text-[13px] font-semibold transition-colors",
                  isActive(n.href)
                    ? "text-white"
                    : "text-[var(--color-ink-dim)] hover:text-white hover:bg-white/5",
                )}
              >
                <span className="text-[13px]">{n.icon}</span>
                {n.label}
                {n.badge && (
                  <span className="num text-[9px] font-bold grad-brand text-[var(--color-on-brand)] rounded-full min-w-[16px] h-4 grid place-items-center px-1">
                    {n.badge}
                  </span>
                )}
                {isActive(n.href) && (
                  <span className="absolute -bottom-[2px] left-2.5 right-2.5 h-[2.5px] rounded-t-sm grad-brand" />
                )}
              </Link>
            ))}
          </nav>

          <div className="flex-1" />

          {/* search */}
          <button
            onClick={() => setSearchOpen(true)}
            className="hidden sm:flex items-center gap-2 rounded-lg border border-[var(--color-line)] bg-[var(--color-surface)] px-3 py-2 text-[var(--color-ink-faint)] hover:border-[var(--color-line-2)] transition-colors w-[180px]"
          >
            <Search size={15} />
            <span className="text-[12px]">Search…</span>
          </button>

          {/* ── Auth cluster ─────────────────────────────────────────── */}
          {loggedIn ? (
            <>
              {/* deposit */}
              <Link
                href="/account"
                className="hidden sm:flex items-center gap-1.5 rounded-lg grad-brand text-[var(--color-on-brand)] px-3.5 py-2 font-display font-bold text-[13px] shadow-[0_6px_18px_-8px_rgba(249,115,22,.7)] hover:brightness-110 transition"
              >
                <Plus size={15} /> Deposit
              </Link>

              {/* wallet */}
              <Link
                href="/account"
                className="hidden md:flex items-center gap-2 rounded-lg border border-[var(--color-line)] bg-[var(--color-surface)] px-3 py-1.5 hover:border-[var(--color-line-2)] transition-colors"
              >
                <span className="w-1.5 h-1.5 rounded-full bg-[var(--color-emerald)] shadow-[0_0_8px_var(--color-emerald)]" />
                <div className="flex flex-col leading-tight">
                  <span className="text-[8.5px] uppercase tracking-wide text-[var(--color-ink-faint)]">Balance</span>
                  <span className="num text-[12.5px] font-bold">
                    {balance === null ? "…" : formatMoneyWithCurrency(balance, currency)}
                  </span>
                </div>
              </Link>

              {/* profile */}
              <div className="relative">
                <button
                  onClick={() => setProfileOpen((v) => !v)}
                  className="flex items-center gap-1 rounded-lg border border-[var(--color-line)] bg-[var(--color-surface)] p-1.5 pr-2 hover:border-[var(--color-line-2)] transition-colors"
                >
                  <span className="grid place-items-center w-7 h-7 rounded-md grad-brand text-[var(--color-on-brand)] font-display font-bold text-[12px]">
                    {initials(userName)}
                  </span>
                  <ChevronDown size={14} className="text-[var(--color-ink-faint)]" />
                </button>
                {profileOpen && (
                  <>
                    <div className="fixed inset-0 z-40" onClick={() => setProfileOpen(false)} />
                    <div className="absolute right-0 top-[calc(100%+8px)] z-50 w-52 card p-1.5 animate-rise shadow-2xl">
                      <ProfileItem href="/account" icon={<User size={15} />} label="Account" onClick={() => setProfileOpen(false)} />
                      <ProfileItem href="/account" icon={<Wallet size={15} />} label="Deposit / Withdraw" onClick={() => setProfileOpen(false)} />
                      <ProfileItem href="/transactions" icon="📄" label="Transactions" onClick={() => setProfileOpen(false)} />
                      <ProfileItem href="/bet-history" icon="🎟️" label="Bet History" onClick={() => setProfileOpen(false)} />
                      <div className="h-px bg-[var(--color-line)] my-1.5" />
                      <button
                        onClick={logout}
                        className="w-full flex items-center gap-2.5 px-2.5 py-2 rounded-lg text-[13px] font-medium text-[var(--color-rose)] hover:bg-[var(--color-rose)]/10 transition-colors"
                      >
                        <span className="w-4 grid place-items-center">⏻</span>
                        Logout
                      </button>
                    </div>
                  </>
                )}
              </div>
            </>
          ) : (
            // Logged out → Login + Sign Up at the top
            <div className="flex items-center gap-2">
              <Link
                href="/login"
                className="rounded-lg border border-[var(--color-line)] bg-[var(--color-surface)] px-3.5 py-2 font-display font-semibold text-[13px] text-[var(--color-ink-dim)] hover:text-white hover:border-[var(--color-line-2)] transition"
              >
                Log in
              </Link>
              <Link
                href="/register"
                className="rounded-lg grad-brand text-[var(--color-on-brand)] px-3.5 py-2 font-display font-bold text-[13px] shadow-[0_6px_18px_-8px_rgba(249,115,22,.7)] hover:brightness-110 transition"
              >
                Sign up
              </Link>
            </div>
          )}
        </div>
        {/* brand rule — the masthead's only chrome, in place of a grey hairline */}
        <div className="brand-rule" />
      </header>

      <GlobalSearch open={searchOpen} onClose={() => setSearchOpen(false)} />
    </>
  );
}

function ProfileItem({
  href, icon, label, danger, onClick,
}: { href: string; icon: React.ReactNode; label: string; danger?: boolean; onClick?: () => void }) {
  return (
    <Link
      href={href}
      onClick={onClick}
      className={cn(
        "flex items-center gap-2.5 px-2.5 py-2 rounded-lg text-[13px] font-medium transition-colors",
        danger ? "text-[var(--color-rose)] hover:bg-[var(--color-rose)]/10" : "text-[var(--color-ink-dim)] hover:text-white hover:bg-white/5",
      )}
    >
      <span className="w-4 grid place-items-center">{icon}</span>
      {label}
    </Link>
  );
}
