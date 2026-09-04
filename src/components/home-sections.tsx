"use client";

import Link from "next/link";
import { ArrowRight } from "lucide-react";
import { promos } from "@/lib/data";
import type { Match } from "@/lib/types";
import { useSlip } from "@/lib/store";
import { useMatches } from "@/lib/use-matches";
import { TeamBadge, CountryFlag } from "./brand";
import { LiveClock } from "./live-clock";
import { cn } from "@/lib/utils";

const TONE: Record<string, string> = {
  brand: "from-[#c2410c]/30 to-[#e11d48]/10 border-[#f97316]/30",
  magenta: "from-[#9f1239]/35 to-[#e11d48]/10 border-[#e11d48]/30",
  emerald: "from-[#059669]/30 to-[#34d399]/10 border-[#34d399]/30",
  gold: "from-[#d97706]/30 to-[#fbbf24]/10 border-[#fbbf24]/30",
};

export function PromoStrip() {
  return (
    <div className="flex gap-3 overflow-x-auto no-scrollbar -mx-3 px-3 sm:mx-0 sm:px-0 pb-1">
      {promos.map((p, i) => (
        <Link
          key={i}
          href="/account"
          className={cn(
            "group relative shrink-0 w-[230px] sm:w-[260px] rounded-[10px] border bg-gradient-to-br p-4 overflow-hidden card-hover",
            TONE[p.tone],
          )}
        >
          <div className="absolute -right-6 -top-6 w-24 h-24 rounded-full bg-white/5 blur-xl group-hover:bg-white/10 transition" />
          <div className="relative">
            <div className="text-[11px] font-semibold text-white/80">{p.eyebrow}</div>
            <div className="font-display font-extrabold text-[17px] mt-1.5 leading-tight">{p.title}</div>
            <div className="text-[11.5px] text-[var(--color-ink-dim)] mt-1">{p.sub}</div>
            <div className="flex items-center gap-1 mt-3 text-[12px] font-bold text-white">
              {p.cta} <ArrowRight size={13} className="group-hover:translate-x-0.5 transition" />
            </div>
          </div>
        </Link>
      ))}
    </div>
  );
}

export function StatRibbon() {
  const { live, today, all } = useMatches();
  // Real, feed-derived counts only — no fabricated figures.
  const tiles = [
    { val: `${live.length}`, label: "Live Now" },
    { val: `${today.length}`, label: "Starting Today" },
    { val: `${all.length}`, label: "Total Fixtures" },
  ];
  return (
    <div className="flex gap-2.5 overflow-x-auto no-scrollbar mt-4">
      <Link
        href="/booking"
        className="shrink-0 flex items-center gap-2 rounded-[10px] border border-[var(--color-brand)]/35 bg-[var(--color-brand)]/10 px-3.5 py-2.5 hover:bg-[var(--color-brand)]/18 transition"
      >
        <span className="text-[15px]">📥</span>
        <span className="font-display font-bold text-[12.5px] text-[var(--color-brand)]">Load Booking Code</span>
      </Link>
      <Link
        href="/verify"
        className="shrink-0 flex items-center gap-2 rounded-[10px] border border-[var(--color-brand-2)]/35 bg-[var(--color-brand-2)]/10 px-3.5 py-2.5 hover:bg-[var(--color-brand-2)]/18 transition"
      >
        <span className="text-[15px]">🎟️</span>
        <span className="font-display font-bold text-[12.5px] text-[var(--color-brand-2-hi)]">Verify Tickets</span>
      </Link>
      {tiles.map((s, i) => (
        <div key={i} className="shrink-0 flex items-center gap-2.5 rounded-[10px] border border-[var(--color-line)] bg-[var(--color-surface)] px-3.5 py-2.5">
          <span className="num text-[15px] font-extrabold">{s.val}</span>
          <span className="text-[10px] text-[var(--color-ink-dim)] whitespace-nowrap">{s.label}</span>
        </div>
      ))}
    </div>
  );
}

export function FeaturedMatch({ m }: { m: Match }) {
  const toggle = useSlip((s) => s.toggle);
  const sels = useSlip((s) => s.selections);
  const picks = [
    { label: "1", name: m.home, odds: m.markets[0].odds },
    { label: "X", name: "Draw", odds: m.markets[1].odds },
    { label: "2", name: m.away, odds: m.markets[2].odds },
  ];
  return (
    <div className="relative grad-border overflow-hidden mt-4">
      <div className="relative p-5 sm:p-6">
        <div className="absolute inset-0 opacity-50 pointer-events-none">
          <div className="absolute top-0 left-1/4 w-40 h-40 rounded-full bg-[var(--color-brand)]/20 blur-3xl animate-[orb_14s_ease-in-out_infinite]" />
          <div className="absolute bottom-0 right-1/4 w-40 h-40 rounded-full bg-[var(--color-brand-2)]/15 blur-3xl animate-[orb_18s_ease-in-out_infinite]" />
        </div>

        <div className="relative">
          <div className="flex items-center gap-2 mb-5">
            <span className="chip px-2.5 py-1 grad-gold text-black font-bold border-transparent">⭐ FEATURED</span>
            {m.live && (
              <span className="flex items-center gap-1.5 chip px-2.5 py-1 bg-[var(--color-rose)]/12 border-[var(--color-rose)]/30 text-[var(--color-rose)]">
                <span className="live-dot" /> LIVE <LiveClock startTimeISO={m.startTimeISO} sport={m.sport} fallbackMinute={m.minute} />
              </span>
            )}
            {!m.live && m.locked && (
              <span className="chip px-2.5 py-1 bg-[var(--color-surface-2)] border-[var(--color-line)] text-[var(--color-ink-faint)] uppercase text-[10px] font-bold tracking-wide">
                {m.lockLabel ?? "Locked"}
              </span>
            )}
            <span className="flex items-center gap-1.5 text-[11.5px] text-[var(--color-ink-dim)] ml-auto">
              <CountryFlag url={m.leagueFlagUrl} emoji={m.leagueFlag} /> {m.league}
            </span>
          </div>

          <div className="flex items-center justify-between gap-4">
            <Link href={`/match/${m.id}`} className="flex flex-col items-center gap-2 flex-1 group">
              <TeamBadge short={m.homeShort} color={m.homeColor} size={56} logo={m.homeLogo} />
              <span className="font-display font-bold text-[15px] text-center group-hover:text-[var(--color-brand-hi)] transition">{m.home}</span>
            </Link>

            <div className="flex flex-col items-center px-2">
              {m.live ? (
                <>
                  <div className="num text-[34px] font-extrabold leading-none tracking-tight">
                    {m.scoreHome ?? 0}<span className="text-[var(--color-ink-faint)] mx-1.5">:</span>{m.scoreAway ?? 0}
                  </div>
                  <LiveClock startTimeISO={m.startTimeISO} sport={m.sport} fallbackMinute={m.minute} className="num text-[10px] text-[var(--color-rose)] font-bold mt-1.5" />
                </>
              ) : (
                <>
                  <div className="font-display text-[22px] font-bold text-[var(--color-ink-dim)]">VS</div>
                  <span className="num text-[10px] text-[var(--color-brand-hi)] font-semibold mt-1.5">{m.kickoff}</span>
                </>
              )}
            </div>

            <Link href={`/match/${m.id}`} className="flex flex-col items-center gap-2 flex-1 group">
              <TeamBadge short={m.awayShort} color={m.awayColor} size={56} logo={m.awayLogo} />
              <span className="font-display font-bold text-[15px] text-center group-hover:text-[var(--color-brand-hi)] transition">{m.away}</span>
            </Link>
          </div>

          <div className="grid grid-cols-3 gap-2.5 mt-6">
            {picks.map((p) => {
              const id = `${m.id}-1x2-${p.label}`;
              const active = sels.some((x) => x.id === id);
              return (
                <button
                  key={p.label}
                  data-active={active}
                  disabled={m.locked}
                  onClick={() => {
                    if (m.locked) return;
                    toggle({ id, matchId: m.id, match: `${m.home} v ${m.away}`, market: "Match Result", pick: p.name, odds: p.odds });
                  }}
                  className="odds-btn group/o flex items-center justify-between px-4 py-3 disabled:opacity-40 disabled:cursor-not-allowed"
                >
                  <span className="text-[12px] font-medium text-[var(--color-ink-dim)] group-data-[active=true]/o:text-white/80 truncate">{p.name}</span>
                  <span className="num text-[15px] font-bold">{p.odds.toFixed(2)}</span>
                </button>
              );
            })}
          </div>

          <Link href={`/match/${m.id}`} className="flex items-center justify-center gap-1.5 mt-4 text-[12px] font-semibold text-[var(--color-brand-hi)] hover:underline">
            View all {m.marketCount} markets <ArrowRight size={13} />
          </Link>
        </div>
      </div>
    </div>
  );
}
