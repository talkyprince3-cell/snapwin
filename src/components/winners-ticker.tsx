"use client";

import { useEffect, useState } from "react";
import { Trophy } from "lucide-react";
import { formatMoneyWithCurrency } from "@/lib/format-money";

interface WinnerEntry {
  masked: string;
  amount: number;
  currency: string;
  settledAt: string;
  code: string;
}

export function WinnersTicker() {
  const [winners, setWinners] = useState<WinnerEntry[]>([]);

  useEffect(() => {
    let cancelled = false;
    async function load() {
      try {
        const res = await fetch("/api/winners");
        if (!res.ok) return;
        const data = (await res.json()) as { winners?: WinnerEntry[] };
        if (!cancelled) setWinners(data.winners ?? []);
      } catch {
        /* marketing prop — fail silently */
      }
    }
    load();
    const timer = setInterval(load, 60_000);
    return () => {
      cancelled = true;
      clearInterval(timer);
    };
  }, []);

  // Nothing to show until at least one real winner exists.
  if (winners.length === 0) return null;

  const items = [...winners, ...winners];

  return (
    <div className="mt-4 flex items-center h-9 overflow-hidden rounded-xl border border-[var(--color-amber)]/25 bg-[var(--color-amber)]/8">
      <span className="shrink-0 flex items-center gap-1.5 px-3 h-full border-r border-[var(--color-amber)]/20">
        <Trophy size={13} className="text-[var(--color-amber)]" />
        <span className="font-mono text-[10px] font-bold tracking-[0.14em] text-[var(--color-amber)]">WINNERS</span>
      </span>
      <div className="relative flex-1 overflow-hidden">
        <div className="flex items-center gap-8 whitespace-nowrap animate-[ticker_42s_linear_infinite] hover:[animation-play-state:paused] pl-8">
          {items.map((w, i) => (
            <span key={i} className="text-[12px] text-[var(--color-ink-dim)] font-medium">
              🏆 <span className="text-[var(--color-ink)] font-semibold">{w.masked}</span> won{" "}
              <span className="num font-bold text-[var(--color-amber)]">
                {formatMoneyWithCurrency(w.amount, w.currency)}
              </span>
            </span>
          ))}
        </div>
      </div>
    </div>
  );
}
