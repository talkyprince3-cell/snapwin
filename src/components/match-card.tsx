"use client";

import Link from "next/link";
import { useMemo, useState } from "react";
import { ChevronRight, BarChart3, Lock, ChevronDown } from "lucide-react";
import type { Match } from "@/lib/types";
import { useSlip } from "@/lib/store";
import { TeamBadge, CountryFlag } from "./brand";
import { LiveClock } from "./live-clock";
import { cn } from "@/lib/utils";

const PICK_LABEL: Record<string, (m: Match) => string> = {
  "1": (m) => m.home,
  X: () => "Draw",
  "2": (m) => m.away,
};

function OddsCell({ m, idx }: { m: Match; idx: number }) {
  const mk = m.markets[idx];
  const id = `${m.id}-1x2-${mk.label}`;
  const has = useSlip((s) => s.selections.some((x) => x.id === id));
  const toggle = useSlip((s) => s.toggle);
  const locked = m.locked;
  return (
    <button
      data-active={has}
      disabled={locked}
      onClick={(e) => {
        e.preventDefault();
        if (locked) return;
        toggle({
          id,
          matchId: m.id,
          match: `${m.home} v ${m.away}`,
          league: m.league,
          country: m.country,
          market: "Match Result",
          pick: PICK_LABEL[mk.label](m),
          odds: mk.odds,
        });
      }}
      className="odds-btn group/odds flex flex-col items-center justify-center gap-0.5 py-2 px-1 disabled:opacity-40 disabled:cursor-not-allowed"
    >
      <span className="text-[10px] font-medium text-[var(--color-ink-faint)] group-data-[active=true]/odds:text-[var(--color-ink)]/80">
        {mk.label}
      </span>
      <span className="num text-[13px]">{mk.odds.toFixed(2)}</span>
    </button>
  );
}

export function MatchCard({ m }: { m: Match }) {
  return (
    <div className="card card-hover group p-3.5 sm:p-4">
      <Link href={`/match/${m.id}`} className="block">
        {/* header row */}
        <div className="flex items-center justify-between mb-3">
          <div className="flex items-center gap-2 text-[var(--color-ink-dim)]">
            <CountryFlag url={m.leagueFlagUrl} emoji={m.leagueFlag} className="text-sm" />
            <span className="text-[11px] font-medium truncate max-w-[140px]">{m.league}</span>
          </div>
          {m.live ? (
            <span className="flex items-center gap-1.5 rounded-full px-2 py-0.5 bg-[var(--color-rose)]/12 border border-[var(--color-rose)]/30 text-[var(--color-rose)]">
              <span className="live-dot" />
              <LiveClock startTimeISO={m.startTimeISO} sport={m.sport} fallbackMinute={m.minute} className="num text-[10px] font-bold" />
            </span>
          ) : m.locked ? (
            <span className="flex items-center gap-1 rounded-full px-2 py-0.5 bg-[var(--color-surface-2)] border border-[var(--color-line)] text-[var(--color-ink-faint)]">
              <Lock size={9} />
              <span className="text-[9.5px] font-bold uppercase tracking-wide">{m.lockLabel ?? "Locked"}</span>
            </span>
          ) : (
            <span className="num text-[10.5px] text-[var(--color-ink-faint)]">{m.kickoff}</span>
          )}
        </div>

        {/* teams */}
        <div className="flex items-center justify-between gap-3">
          <div className="flex flex-col gap-2.5 min-w-0 flex-1">
            <div className="flex items-center gap-2.5">
              <TeamBadge short={m.homeShort} color={m.homeColor} size={32} logo={m.homeLogo} />
              <span className="font-display font-semibold text-[14px] truncate">{m.home}</span>
            </div>
            <div className="flex items-center gap-2.5">
              <TeamBadge short={m.awayShort} color={m.awayColor} size={32} logo={m.awayLogo} />
              <span className="font-display font-semibold text-[14px] truncate">{m.away}</span>
            </div>
          </div>
          {m.live && (
            <div className="flex flex-col items-center gap-2.5 px-3">
              <span className="num text-[18px] font-extrabold leading-none">{m.scoreHome}</span>
              <span className="num text-[18px] font-extrabold leading-none">{m.scoreAway}</span>
            </div>
          )}
        </div>
      </Link>

      {/* odds + markets */}
      <div className="mt-3.5 flex items-center gap-2">
        <div className="grid grid-cols-3 gap-2 flex-1">
          <OddsCell m={m} idx={0} />
          <OddsCell m={m} idx={1} />
          <OddsCell m={m} idx={2} />
        </div>
        <Link
          href={`/match/${m.id}`}
          className="flex items-center gap-1 shrink-0 rounded-lg border border-[var(--color-line)] bg-[var(--color-surface-2)] px-2.5 py-2 text-[var(--color-ink-dim)] hover:text-[var(--color-ink)] hover:border-[var(--color-brand)]/50 transition-colors"
        >
          <BarChart3 size={13} />
          <span className="num text-[11px] font-bold">+{m.marketCount}</span>
          <ChevronRight size={13} className="opacity-60" />
        </Link>
      </div>
    </div>
  );
}

export function MatchRow({ m }: { m: Match }) {
  return <MatchCard m={m} />;
}

/* ============================================================
   Dense fixture list
   The layout a sportsbook actually uses: fixtures grouped under
   their league, one flat row each, odds locked to a 1 / X / 2
   column grid so the numbers line up down the whole page.
   ============================================================ */

/** One odds cell in the dense row — same behaviour as OddsCell, flatter chrome. */
function RowOdds({ m, idx }: { m: Match; idx: number }) {
  const mk = m.markets[idx];
  const id = `${m.id}-1x2-${mk.label}`;
  const has = useSlip((s) => s.selections.some((x) => x.id === id));
  const toggle = useSlip((s) => s.toggle);
  return (
    <button
      data-active={has}
      disabled={m.locked}
      onClick={(e) => {
        e.preventDefault();
        if (m.locked) return;
        toggle({
          id,
          matchId: m.id,
          match: `${m.home} v ${m.away}`,
          league: m.league,
          country: m.country,
          market: "Match Result",
          pick: PICK_LABEL[mk.label](m),
          odds: mk.odds,
        });
      }}
      className="odds-btn num h-[38px] text-[13px] disabled:opacity-35 disabled:cursor-not-allowed"
    >
      {mk.odds.toFixed(2)}
    </button>
  );
}

/** A single flat fixture row: time · teams · 1 X 2 · more-markets. */
export function FixtureRow({ m }: { m: Match }) {
  const [d, t] = splitKickoff(m);
  return (
    <div className="fixture-row flex items-stretch gap-2 px-2.5 py-2">
      {/* time / live clock */}
      <Link href={`/match/${m.id}`} className="w-[46px] shrink-0 flex flex-col justify-center">
        {m.live ? (
          <span className="flex items-center gap-1 text-[var(--color-rose)]">
            <span className="live-dot" />
            <LiveClock
              startTimeISO={m.startTimeISO}
              sport={m.sport}
              fallbackMinute={m.minute}
              className="num text-[10px] font-bold"
            />
          </span>
        ) : (
          <>
            <span className="num text-[12px] font-semibold leading-tight">{t}</span>
            <span className="text-[9.5px] text-[var(--color-ink-faint)] uppercase leading-tight">{d}</span>
          </>
        )}
      </Link>

      {/* teams (+ live score) */}
      <Link href={`/match/${m.id}`} className="flex-1 min-w-0 flex items-center gap-2">
        <div className="min-w-0 flex-1 flex flex-col gap-[3px]">
          <span className="text-[12.5px] font-semibold truncate leading-tight">{m.home}</span>
          <span className="text-[12.5px] font-semibold truncate leading-tight">{m.away}</span>
        </div>
        {m.live ? (
          <div className="shrink-0 flex flex-col items-center gap-[3px] px-1.5">
            <span className="num text-[12.5px] font-extrabold leading-tight text-[var(--color-brand)]">{m.scoreHome ?? 0}</span>
            <span className="num text-[12.5px] font-extrabold leading-tight text-[var(--color-brand)]">{m.scoreAway ?? 0}</span>
          </div>
        ) : m.locked ? (
          <Lock size={11} className="shrink-0 text-[var(--color-ink-faint)]" />
        ) : null}
      </Link>

      {/* 1 X 2 */}
      <div className="shrink-0 grid grid-cols-3 gap-1 w-[168px] sm:w-[190px]">
        <RowOdds m={m} idx={0} />
        <RowOdds m={m} idx={1} />
        <RowOdds m={m} idx={2} />
      </div>

      {/* more markets */}
      <Link
        href={`/match/${m.id}`}
        aria-label={`${m.marketCount} more markets`}
        className="shrink-0 w-[42px] grid place-items-center rounded-[var(--radius-ctl)] border border-[var(--color-line)] bg-[var(--color-surface-2)] text-[var(--color-ink-dim)] hover:text-[var(--color-ink)] hover:border-[var(--color-brand)]/50 transition-colors"
      >
        <span className="num text-[11px] font-bold">+{m.marketCount}</span>
      </Link>
    </div>
  );
}

/** Kickoff string → ["SAT", "20:00"]. Falls back to whatever the feed gave us. */
function splitKickoff(m: Match): [string, string] {
  const iso = m.startTimeISO ? new Date(m.startTimeISO) : null;
  if (iso && !Number.isNaN(iso.getTime())) {
    return [
      iso.toLocaleDateString(undefined, { weekday: "short" }),
      iso.toLocaleTimeString(undefined, { hour: "2-digit", minute: "2-digit", hour12: false }),
    ];
  }
  return ["", m.kickoff];
}

/** A collapsible league block: header, 1 X 2 captions, then its fixtures. */
export function LeagueGroup({ league, matches }: { league: string; matches: Match[] }) {
  const [open, setOpen] = useState(true);
  const head = matches[0];
  return (
    <div className="mb-2.5">
      <button onClick={() => setOpen((v) => !v)} className="league-head w-full text-left">
        <CountryFlag url={head.leagueFlagUrl} emoji={head.leagueFlag} className="text-sm shrink-0" />
        <span className="font-display font-bold text-[12px] truncate">{league}</span>
        <span className="num text-[10px] text-[var(--color-ink-faint)]">{matches.length}</span>
        <span className="flex-1" />
        <span className="hidden sm:flex items-center gap-1 w-[190px] shrink-0">
          {["1", "X", "2"].map((c) => (
            <span key={c} className="market-head flex-1 text-center">{c}</span>
          ))}
        </span>
        <span className="hidden sm:block w-[42px] shrink-0" />
        <ChevronDown
          size={14}
          className={cn("shrink-0 text-[var(--color-ink-faint)] transition-transform", !open && "-rotate-90")}
        />
      </button>
      {open && matches.map((m) => <FixtureRow key={m.id} m={m} />)}
    </div>
  );
}

/**
 * Groups a flat fixture list by league, preserving the order the leagues
 * first appear in the feed, and renders one LeagueGroup per league.
 */
export function FixtureList({ matches, empty }: { matches: Match[]; empty: string }) {
  const groups = useMemo(() => {
    const by = new Map<string, Match[]>();
    for (const m of matches) {
      const list = by.get(m.league);
      if (list) list.push(m);
      else by.set(m.league, [m]);
    }
    return [...by.entries()];
  }, [matches]);

  if (groups.length === 0) {
    return <p className="text-[13px] text-[var(--color-ink-faint)] py-3">{empty}</p>;
  }
  return (
    <div>
      {groups.map(([league, ms]) => (
        <LeagueGroup key={league} league={league} matches={ms} />
      ))}
    </div>
  );
}

export function SectionHead({
  title,
  more,
  href,
  accent,
}: {
  title: string;
  more?: string;
  href?: string;
  accent?: string;
}) {
  return (
    <div className="flex items-center justify-between mb-3 mt-6">
      <div className="flex items-center gap-2.5">
        <span className="title-bar" style={accent ? { background: accent } : undefined} />
        <h2 className="font-display font-extrabold text-[15px] tracking-tight">{title}</h2>
      </div>
      {more &&
        (href ? (
          <Link href={href} className="text-[11.5px] font-semibold text-[var(--color-brand-hi)] hover:underline">
            {more} →
          </Link>
        ) : (
          <span className="text-[11.5px] font-medium text-[var(--color-ink-faint)]">{more} →</span>
        ))}
    </div>
  );
}
