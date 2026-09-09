"use client";

import { use, useEffect, useState } from "react";
import Link from "next/link";
import { ChevronLeft, Star, Share2, Lock } from "lucide-react";
import { AppShell } from "@/components/app-shell";
import type { Match as ApiMatch } from "@/lib/domain-types";
import type { Match as UiMatch } from "@/lib/types";
import { apiMatchToUi, buildMarketGroupsFromApi } from "@/lib/match-adapter";
import { useSlip } from "@/lib/store";
import { TeamBadge, CountryFlag } from "@/components/brand";
import { LiveClock } from "@/components/live-clock";
import { cn } from "@/lib/utils";

const TABS = ["All", "Main", "Goals", "Halves", "Players", "Specials"];

export default function MatchDetail({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const [tab, setTab] = useState("All");
  const { selections, toggle } = useSlip();

  const [api, setApi] = useState<ApiMatch | null>(null);
  const [status, setStatus] = useState<"loading" | "ready" | "notfound">("loading");

  useEffect(() => {
    let cancelled = false;
    async function load() {
      try {
        const res = await fetch(`/api/matches/${encodeURIComponent(id)}`);
        if (cancelled) return;
        if (!res.ok) {
          setStatus("notfound");
          return;
        }
        const data = (await res.json()) as { match?: ApiMatch };
        if (cancelled) return;
        if (!data.match) {
          setStatus("notfound");
          return;
        }
        setApi(data.match);
        setStatus("ready");
      } catch {
        if (!cancelled) setStatus("notfound");
      }
    }
    load();
    // Keep live scores/minute fresh while the page is open.
    const timer = setInterval(load, 30_000);
    return () => {
      cancelled = true;
      clearInterval(timer);
    };
  }, [id]);

  if (status === "loading") {
    return (
      <AppShell tabs={false}>
        <p className="text-[13px] text-[var(--color-ink-faint)] py-12 text-center">Loading match…</p>
      </AppShell>
    );
  }

  if (status === "notfound" || !api) {
    return (
      <AppShell tabs={false}>
        <Link href="/" className="inline-flex items-center gap-1 text-[13px] text-[var(--color-ink-dim)] hover:text-white mb-3 transition">
          <ChevronLeft size={16} /> Back to Sports
        </Link>
        <p className="text-[13px] text-[var(--color-ink-faint)] py-12 text-center">
          This match is no longer available. It may have finished or been removed from the feed.
        </p>
      </AppShell>
    );
  }

  const m: UiMatch = apiMatchToUi(api);
  const groups = buildMarketGroupsFromApi(api);

  return (
    <AppShell tabs={false}>
      <Link href="/" className="inline-flex items-center gap-1 text-[13px] text-[var(--color-ink-dim)] hover:text-white mb-3 transition">
        <ChevronLeft size={16} /> Back to Sports
      </Link>

      {/* match header */}
      <div className="grad-border overflow-hidden">
        <div className="relative p-5 sm:p-6">
          <div className="flex items-center justify-between mb-5 text-[12px] text-[var(--color-ink-dim)]">
            <span className="flex items-center gap-1.5"><CountryFlag url={m.leagueFlagUrl} emoji={m.leagueFlag} /> {m.league}</span>
            <div className="flex items-center gap-2">
              <button className="grid place-items-center w-8 h-8 rounded-lg border border-[var(--color-line)] hover:text-[var(--color-amber)] hover:border-[var(--color-amber)]/40 transition">
                <Star size={15} />
              </button>
              <button className="grid place-items-center w-8 h-8 rounded-lg border border-[var(--color-line)] hover:text-white transition">
                <Share2 size={15} />
              </button>
            </div>
          </div>

          <div className="flex items-center justify-between gap-4">
            <div className="flex flex-col items-center gap-2.5 flex-1">
              <TeamBadge short={m.homeShort} color={m.homeColor} size={60} logo={m.homeLogo} />
              <span className="font-display font-bold text-[15px] text-center">{m.home}</span>
            </div>
            <div className="flex flex-col items-center">
              {m.live ? (
                <>
                  <div className="num text-[36px] font-extrabold leading-none">
                    {m.scoreHome}<span className="text-[var(--color-ink-faint)] mx-2">:</span>{m.scoreAway}
                  </div>
                  <span className="flex items-center gap-1.5 mt-2 num text-[11px] text-[var(--color-rose)] font-bold">
                    <span className="live-dot" />{" "}
                    <LiveClock startTimeISO={m.startTimeISO} sport={m.sport} fallbackMinute={m.minute} />
                  </span>
                </>
              ) : (
                <>
                  <div className="font-display text-[20px] font-bold text-[var(--color-ink-dim)]">VS</div>
                  <span className="num text-[11px] text-[var(--color-cyan)] font-semibold mt-2">{m.kickoff}</span>
                </>
              )}
            </div>
            <div className="flex flex-col items-center gap-2.5 flex-1">
              <TeamBadge short={m.awayShort} color={m.awayColor} size={60} logo={m.awayLogo} />
              <span className="font-display font-bold text-[15px] text-center">{m.away}</span>
            </div>
          </div>

          <div className="flex items-center justify-center gap-5 mt-5 pt-4 border-t border-[var(--color-line)] text-center">
            <Stat label="Markets" value={`${m.marketCount}`} />
            <Stat label="Country" value={m.country} />
            <Stat label="League" value={m.league} />
          </div>
        </div>
      </div>

      {/* market tabs */}
      <div className="flex gap-2 overflow-x-auto no-scrollbar mt-5 sticky top-[60px] z-20 glass py-2 -mx-3 px-3 sm:mx-0 sm:px-0 sm:static sm:bg-transparent sm:backdrop-blur-none">
        {TABS.map((t) => (
          <button key={t} data-active={tab === t} onClick={() => setTab(t)} className="chip shrink-0 px-4 py-1.5">
            {t}
          </button>
        ))}
      </div>

      {m.locked && (
        <div className="mt-4 flex items-center gap-2 rounded-xl border border-[var(--color-line)] bg-[var(--color-surface-2)] px-4 py-3 text-[12.5px] text-[var(--color-ink-dim)]">
          <Lock size={14} className="text-[var(--color-ink-faint)]" />
          Betting is closed for this match{m.lockLabel ? ` — ${m.lockLabel.toLowerCase()}` : ""}.
        </div>
      )}

      {/* market groups */}
      <div className="space-y-3 mt-3">
        {groups.map((g, gi) => (
          <div key={gi} className="card p-4">
            <div className="flex items-center justify-between mb-3">
              <h3 className="font-display font-bold text-[13.5px]">{g.title}</h3>
              <Star size={14} className="text-[var(--color-ink-faint)] hover:text-[var(--color-amber)] cursor-pointer transition" />
            </div>
            <div className={cn("grid gap-2", g.cols === 2 ? "grid-cols-2" : "grid-cols-3")}>
              {g.picks.map((p, pi) => {
                const sid = `${m.id}-${gi}-${pi}`;
                const active = selections.some((x) => x.id === sid);
                return (
                  <button
                    key={pi}
                    data-active={active}
                    disabled={m.locked}
                    onClick={() => {
                      if (m.locked) return;
                      toggle({ id: sid, matchId: m.id, match: `${m.home} v ${m.away}`, league: m.league, country: m.country, market: g.title, pick: p.label, odds: p.odds });
                    }}
                    className="odds-btn group/o flex items-center justify-between gap-2 px-3 py-2.5 disabled:opacity-40 disabled:cursor-not-allowed"
                  >
                    <span className="text-[11.5px] font-medium text-[var(--color-ink-dim)] group-data-[active=true]/o:text-white/80 truncate">{p.label}</span>
                    <span className="num text-[13px] font-bold shrink-0">{p.odds.toFixed(2)}</span>
                  </button>
                );
              })}
            </div>
          </div>
        ))}
      </div>
    </AppShell>
  );
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex flex-col">
      <span className="num text-[15px] font-bold">{value}</span>
      <span className="text-[10px] text-[var(--color-ink-faint)] uppercase tracking-wide">{label}</span>
    </div>
  );
}
