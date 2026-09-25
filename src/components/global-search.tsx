"use client";

import { useState, useMemo } from "react";
import Link from "next/link";
import { Search, X, TrendingUp } from "lucide-react";
import { matches } from "@/lib/data";

const TRENDING = ["Arsenal", "Champions League", "Man City", "El Clasico", "Hearts of Oak"];

export function GlobalSearch({ open, onClose }: { open: boolean; onClose: () => void }) {
  const [q, setQ] = useState("");

  const close = () => {
    setQ("");
    onClose();
  };

  const results = useMemo(() => {
    const t = q.trim().toLowerCase();
    if (!t) return [];
    return matches
      .filter(
        (m) =>
          m.home.toLowerCase().includes(t) ||
          m.away.toLowerCase().includes(t) ||
          m.league.toLowerCase().includes(t),
      )
      .slice(0, 8);
  }, [q]);

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-[60] flex items-start justify-center pt-[12vh] px-4">
      <div className="absolute inset-0 bg-black/70 backdrop-blur-md" onClick={close} />
      <div className="relative w-full max-w-[560px] card overflow-hidden animate-rise shadow-2xl">
        <div className="flex items-center gap-3 px-4 py-3.5 border-b border-[var(--color-line)]">
          <Search size={18} className="text-[var(--color-ink-faint)]" />
          <input
            autoFocus
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder="Search teams, leagues, ticket ID…"
            className="flex-1 bg-transparent outline-none text-[15px] placeholder:text-[var(--color-ink-faint)]"
          />
          <button onClick={close} className="text-[var(--color-ink-faint)] hover:text-[var(--color-ink)]">
            <X size={18} />
          </button>
        </div>

        <div className="max-h-[50vh] overflow-y-auto no-scrollbar p-2">
          {!q && (
            <div className="p-2">
              <div className="flex items-center gap-1.5 px-2 mb-2 text-[10px] font-mono uppercase tracking-[0.14em] text-[var(--color-ink-faint)]">
                <TrendingUp size={12} /> Trending
              </div>
              <div className="flex flex-wrap gap-2 px-2">
                {TRENDING.map((t) => (
                  <button key={t} onClick={() => setQ(t)} className="chip px-3 py-1.5">
                    {t}
                  </button>
                ))}
              </div>
            </div>
          )}

          {q && results.length === 0 && (
            <div className="py-10 text-center text-[13px] text-[var(--color-ink-faint)]">
              No matches for “{q}”.
            </div>
          )}

          {results.map((m) => (
            <Link
              key={m.id}
              href={`/match/${m.id}`}
              onClick={close}
              className="flex items-center justify-between gap-3 rounded-xl px-3 py-2.5 hover:bg-[var(--color-ink)]/5 transition-colors"
            >
              <div className="min-w-0">
                <div className="font-display font-semibold text-[13.5px] truncate">
                  {m.home} <span className="text-[var(--color-ink-faint)]">v</span> {m.away}
                </div>
                <div className="text-[11px] text-[var(--color-ink-dim)] flex items-center gap-1.5">
                  {m.leagueFlag} {m.league}
                </div>
              </div>
              <div className="flex items-center gap-1.5 shrink-0">
                {m.live && <span className="live-dot" />}
                <span className="num text-[11px] text-[var(--color-cyan)] font-bold">{m.markets[0].odds.toFixed(2)}</span>
              </div>
            </Link>
          ))}
        </div>
      </div>
    </div>
  );
}
