import { ArrowDownLeft, ArrowUpRight, Trophy, Ticket } from "lucide-react";
import type { Txn } from "@/lib/types";
import { cn, fmt } from "@/lib/utils";

const META = {
  deposit: { icon: ArrowDownLeft, tone: "emerald", label: "Deposit" },
  withdrawal: { icon: ArrowUpRight, tone: "rose", label: "Withdrawal" },
  winning: { icon: Trophy, tone: "amber", label: "Winnings" },
  bet: { icon: Ticket, tone: "brand", label: "Bet Placed" },
} as const;

const TONE_BG: Record<string, string> = {
  emerald: "bg-[var(--color-emerald)]/12 text-[var(--color-emerald)]",
  rose: "bg-[var(--color-rose)]/12 text-[var(--color-rose)]",
  amber: "bg-[var(--color-amber)]/12 text-[var(--color-amber)]",
  brand: "bg-[var(--color-brand)]/12 text-[var(--color-brand-ink)]",
};

const STATUS: Record<string, string> = {
  completed: "bg-[var(--color-emerald)]/12 text-[var(--color-emerald)] border-[var(--color-emerald)]/25",
  pending: "bg-[var(--color-amber)]/12 text-[var(--color-amber)] border-[var(--color-amber)]/25",
  failed: "bg-[var(--color-rose)]/12 text-[var(--color-rose)] border-[var(--color-rose)]/25",
};

export function TxnRow({ t }: { t: Txn }) {
  const meta = META[t.type];
  const Icon = meta.icon;
  const positive = t.amount >= 0;
  return (
    <div className="flex items-center gap-3 py-3">
      <span className={cn("grid place-items-center w-9 h-9 rounded-xl shrink-0", TONE_BG[meta.tone])}>
        <Icon size={16} />
      </span>
      <div className="min-w-0 flex-1">
        <div className="font-display font-semibold text-[13px] truncate">{meta.label}</div>
        <div className="text-[11px] text-[var(--color-ink-faint)] truncate">{t.method} · {t.date}</div>
      </div>
      <div className="flex flex-col items-end gap-1 shrink-0">
        <span className={cn("num text-[13px] font-bold", positive ? "text-[var(--color-emerald)]" : "text-[var(--color-ink)]")}>
          {positive ? "+" : "−"}GH₵ {fmt(Math.abs(t.amount))}
        </span>
        <span className={cn("text-[9px] font-bold uppercase tracking-wide px-1.5 py-0.5 rounded border", STATUS[t.status])}>
          {t.status}
        </span>
      </div>
    </div>
  );
}
