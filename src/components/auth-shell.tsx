import Link from "next/link";
import { ChevronLeft } from "lucide-react";
import { Brand } from "./brand";

export function AuthShell({
  title,
  subtitle,
  children,
}: {
  title: string;
  subtitle: string;
  children: React.ReactNode;
}) {
  return (
    <div className="relative min-h-dvh overflow-hidden grid lg:grid-cols-2">
      {/* left brand panel (desktop) */}
      <div className="relative hidden lg:flex flex-col justify-between p-12 overflow-hidden border-r border-[var(--color-line)]">
        <div className="absolute inset-0 pointer-events-none">
          <div className="absolute top-[-10%] left-[-5%] w-[400px] h-[400px] rounded-full bg-[var(--color-brand)]/25 blur-[110px] animate-[orb_18s_ease-in-out_infinite]" />
          <div className="absolute bottom-[5%] right-[-5%] w-[360px] h-[360px] rounded-full bg-[var(--color-brand-2)]/18 blur-[110px] animate-[orb_22s_ease-in-out_infinite]" />
        </div>
        <Brand size={34} />
        <div className="relative">
          <h2 className="font-display font-extrabold text-[34px] leading-tight tracking-tight">
            Bet smarter.<br /><span className="grad-text">Win bigger.</span>
          </h2>
          <p className="text-[14px] text-[var(--color-ink-dim)] mt-4 max-w-sm leading-relaxed">
            Premium odds across 23+ markets per match, instant mobile-money payouts, and verified tickets you can trust.
          </p>
          <div className="flex items-center gap-6 mt-8">
            <Stat val="4,820" label="Bets today" />
            <Stat val="348" label="Live now" />
            <Stat val="<10m" label="Payouts" />
          </div>
        </div>
        <p className="relative text-[11px] text-[var(--color-ink-faint)]">© 2026 SnapWin. 18+ · Play responsibly.</p>
      </div>

      {/* right form panel */}
      <div className="relative grid place-items-center px-5 py-10">
        <div className="absolute inset-0 lg:hidden pointer-events-none">
          <div className="absolute top-0 left-1/4 w-[300px] h-[300px] rounded-full bg-[var(--color-brand)]/18 blur-[90px]" />
        </div>
        <div className="relative w-full max-w-[400px]">
          <Link href="/" className="inline-flex items-center gap-1 text-[12.5px] text-[var(--color-ink-dim)] hover:text-white mb-6 transition">
            <ChevronLeft size={15} /> Back to home
          </Link>
          <div className="lg:hidden mb-6"><Brand size={32} /></div>
          <h1 className="font-display font-extrabold text-[24px]">{title}</h1>
          <p className="text-[13px] text-[var(--color-ink-dim)] mt-1.5">{subtitle}</p>
          <div className="mt-7">{children}</div>
        </div>
      </div>
    </div>
  );
}

function Stat({ val, label }: { val: string; label: string }) {
  return (
    <div>
      <div className="num text-[20px] font-extrabold grad-text">{val}</div>
      <div className="text-[11px] text-[var(--color-ink-faint)]">{label}</div>
    </div>
  );
}

export function Field({
  label,
  type = "text",
  placeholder,
  children,
}: {
  label: string;
  type?: string;
  placeholder?: string;
  children?: React.ReactNode;
}) {
  return (
    <div className="mb-4">
      <label className="block text-[12px] font-semibold text-[var(--color-ink-dim)] mb-1.5">{label}</label>
      {children ?? (
        <input
          type={type}
          placeholder={placeholder}
          className="w-full bg-[var(--color-surface)] border border-[var(--color-line)] rounded-xl px-3.5 py-3 text-[14px] outline-none focus:border-[var(--color-brand)]/60 focus:glow-brand transition placeholder:text-[var(--color-ink-faint)]"
        />
      )}
    </div>
  );
}
