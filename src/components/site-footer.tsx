import Link from "next/link";
import { Lock, Clock } from "lucide-react";
import { Brand } from "./brand";

type FooterLink = { label: string; href: string };

const COLUMNS: { title: string; links: FooterLink[] }[] = [
  {
    title: "Sports",
    links: [
      { label: "Football", href: "/" },
      { label: "Basketball", href: "/" },
      { label: "Tennis", href: "/" },
      { label: "Cricket", href: "/" },
      { label: "Esports", href: "/" },
      { label: "Boxing & MMA", href: "/" },
    ],
  },
  {
    title: "My Account",
    links: [
      { label: "Account Overview", href: "/account" },
      { label: "Bet History", href: "/bet-history" },
      { label: "Transactions", href: "/transactions" },
      { label: "Deposit", href: "/account" },
      { label: "Withdraw", href: "/account" },
      { label: "Verify Ticket", href: "/verify" },
      { label: "Load Booking Code", href: "/booking" },
    ],
  },
  {
    title: "Company",
    links: [
      { label: "About SnapWin", href: "#" },
      { label: "Careers", href: "#" },
      { label: "Press", href: "#" },
      { label: "Affiliates", href: "#" },
      { label: "Blog", href: "#" },
    ],
  },
  {
    title: "Legal & Help",
    links: [
      { label: "Terms of Service", href: "#" },
      { label: "Privacy Policy", href: "#" },
      { label: "Cookie Policy", href: "#" },
      { label: "Responsible Gambling", href: "#" },
      { label: "FAQ", href: "#" },
    ],
  },
];

export function SiteFooter() {
  return (
    <footer className="mt-10 border-t border-[var(--color-line)] bg-[var(--color-bg-2)]/40">
      <div className="mx-auto max-w-[1600px] px-5 sm:px-6 py-10">
        <div className="grid grid-cols-2 lg:grid-cols-6 gap-8">
          {/* Brand block */}
          <div className="col-span-2 lg:col-span-2">
            <Brand size={34} href="/" />
            <p className="text-[13px] text-[var(--color-ink-dim)] leading-relaxed mt-4 max-w-[280px]">
              Premium international sports betting. Live odds, instant payouts, verified tickets.
            </p>
            <div className="mt-5 space-y-2.5">
              <span className="flex items-center gap-2 text-[12px] font-mono text-[var(--color-ink-dim)]">
                <Lock size={14} className="text-[var(--color-brand)]" /> SSL Secured
              </span>
              <span className="flex items-center gap-2 text-[12px] font-mono text-[var(--color-ink-dim)]">
                <Clock size={14} className="text-[var(--color-brand)]" /> 24/7 Support
              </span>
            </div>
          </div>

          {/* Link columns */}
          {COLUMNS.map((col) => (
            <div key={col.title}>
              <h3 className="font-mono text-[11px] font-bold uppercase tracking-[0.16em] text-[var(--color-ink-faint)] mb-4">
                {col.title}
              </h3>
              <ul className="space-y-3">
                {col.links.map((l) => (
                  <li key={l.label}>
                    <Link
                      href={l.href}
                      className="text-[14px] text-[var(--color-ink-dim)] hover:text-white transition-colors"
                    >
                      {l.label}
                    </Link>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>

        <div className="mt-10 pt-6 border-t border-[var(--color-line)] flex flex-col sm:flex-row items-center justify-between gap-3">
          <p className="text-[12px] text-[var(--color-ink-faint)]">
            © {new Date().getFullYear()} SnapWin. All rights reserved.
          </p>
          <p className="text-[12px] text-[var(--color-ink-faint)] flex items-center gap-2">
            <span className="font-bold text-[var(--color-amber)] border border-[var(--color-amber)]/40 rounded px-1.5 py-0.5">
              18+
            </span>
            Please gamble responsibly.
          </p>
        </div>
      </div>
    </footer>
  );
}
