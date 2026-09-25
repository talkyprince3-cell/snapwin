"use client";

import Image from "next/image";
import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { ArrowRight, ChevronLeft, ChevronRight } from "lucide-react";
import { promos, quickActions, competitions } from "@/lib/data";
import type { Match } from "@/lib/types";
import { useSlip, useSupport } from "@/lib/store";
import { useMatches } from "@/lib/use-matches";
import { TeamBadge, CountryFlag } from "./brand";
import { LiveClock } from "./live-clock";
import { cn } from "@/lib/utils";

/* ============================================================
   Hero carousel
   The full-bleed promo banner a sportsbook home page opens with.
   Auto-advances, pauses on hover, and is swipeable on touch via
   native scroll-snap so there is no drag library to ship.
   ============================================================ */

const HERO_TONE: Record<string, string> = {
  gold: "from-[#0d9488]/18 via-[#14b8a6]/8 to-transparent border-[#0d9488]/30",
  amber: "from-[#facc15]/22 via-[#fde68a]/10 to-transparent border-[#facc15]/35",
  emerald: "from-[#0b9b3a]/18 via-[#22c55e]/8 to-transparent border-[#0b9b3a]/30",
  sky: "from-[#0284c7]/18 via-[#38bdf8]/8 to-transparent border-[#0284c7]/30",
};

export function HeroCarousel() {
  const [idx, setIdx] = useState(0);
  const [paused, setPaused] = useState(false);
  const trackRef = useRef<HTMLDivElement>(null);
  const settleRef = useRef<number | null>(null);

  // Auto-advance, paused while the pointer is over the banner so a reader
  // never has a promo yanked out from under them mid-sentence.
  useEffect(() => {
    if (paused) return;
    const t = setInterval(() => setIdx((i) => (i + 1) % promos.length), 5000);
    return () => clearInterval(t);
  }, [paused]);

  // Drive scroll position from `idx` so the dots, the arrows and a manual
  // swipe all stay in agreement about which slide is showing.
  useEffect(() => {
    const el = trackRef.current;
    if (!el) return;
    // The global reduced-motion rule sets scroll-behavior in CSS, which does
    // not reach this option — so honour the preference explicitly.
    const reduce = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    el.scrollTo({ left: idx * el.clientWidth, behavior: reduce ? "auto" : "smooth" });
  }, [idx]);

  // Keep the active slide aligned when the track is resized.
  useEffect(() => {
    const onResize = () => {
      const el = trackRef.current;
      if (el) el.scrollTo({ left: idx * el.clientWidth, behavior: "auto" });
    };
    window.addEventListener("resize", onResize);
    return () => window.removeEventListener("resize", onResize);
  }, [idx]);

  useEffect(() => () => {
    if (settleRef.current !== null) window.clearTimeout(settleRef.current);
  }, []);

  /**
   * Adopt the scrolled-to slide, but only once scrolling has actually stopped.
   *
   * Reading the offset on every scroll event would fight the programmatic
   * scroll above: mid-animation the track still sits nearer the previous
   * slide, so it rounds back to the old index, resets `idx`, and re-triggers
   * the effect — which snaps the carousel home and stops it advancing at all.
   * Waiting for the track to settle makes this a no-op for our own scrolls
   * and correct for a user swipe.
   */
  const onScroll = (e: React.UIEvent<HTMLDivElement>) => {
    const el = e.currentTarget;
    if (settleRef.current !== null) window.clearTimeout(settleRef.current);
    settleRef.current = window.setTimeout(() => {
      settleRef.current = null;
      if (!el.clientWidth) return;
      const i = Math.round(el.scrollLeft / el.clientWidth);
      setIdx((cur) => (i === cur ? cur : i));
    }, 140);
  };

  const go = (d: number) => setIdx((i) => (i + d + promos.length) % promos.length);

  return (
    <section
      className="relative -mx-3 sm:mx-0"
      onMouseEnter={() => setPaused(true)}
      onMouseLeave={() => setPaused(false)}
      aria-roledescription="carousel"
      aria-label="Promotions"
    >
      <div
        ref={trackRef}
        onScroll={onScroll}
        className="flex overflow-x-auto no-scrollbar snap-x snap-mandatory sm:rounded-[10px]"
      >
        {promos.map((p, i) => (
          <Link
            key={i}
            href={p.href}
            aria-label={p.title}
            className={cn(
              "relative shrink-0 w-full snap-start overflow-hidden border-y sm:border bg-gradient-to-r px-5 sm:px-7 py-6 sm:py-8",
              HERO_TONE[p.tone] ?? HERO_TONE.gold,
            )}
          >
            <div className="absolute -right-10 -top-10 w-48 h-48 rounded-full bg-[var(--color-brand)]/10 blur-3xl" />

            {/* Slide art. Sits behind the copy and is clipped by the banner, so
                a slide without an image simply runs text-only. aria-hidden and
                empty alt: it is decoration, the title already says the offer. */}
            {p.image && (
              <Image
                src={p.image}
                alt=""
                aria-hidden
                width={320}
                height={320}
                priority={i === 0}
                className="pointer-events-none select-none absolute right-2 sm:right-6 top-1/2 -translate-y-1/2 h-[125%] w-auto max-w-[42%] object-contain opacity-90 drop-shadow-[0_10px_30px_rgba(0,0,0,.55)]"
              />
            )}

            <div className="relative max-w-[64%] sm:max-w-[70%]">
              <div className="text-[11px] font-semibold text-[var(--color-brand-hi)]">{p.eyebrow}</div>
              <h2 className="font-display font-extrabold text-[22px] sm:text-[30px] leading-[1.1] mt-1.5 tracking-tight">
                {p.title}
              </h2>
              <p className="text-[12px] sm:text-[13px] text-[var(--color-ink-dim)] mt-1.5">{p.sub}</p>
              <span className="inline-flex items-center gap-1.5 mt-4 grad-brand text-[var(--color-on-brand)] font-display font-extrabold text-[12.5px] rounded-[var(--radius-ctl)] px-4 py-2">
                {p.cta} <ArrowRight size={14} />
              </span>
            </div>
          </Link>
        ))}
      </div>

      {/* Arrows are desktop-only; touch users swipe the scroll-snap track. */}
      <button
        onClick={() => go(-1)}
        aria-label="Previous promotion"
        className="hidden sm:grid place-items-center absolute left-2 top-1/2 -translate-y-1/2 w-8 h-8 rounded-full bg-black/55 border border-[var(--color-line)] text-[var(--color-ink-invert)]/85 hover:text-[var(--color-ink-invert)] hover:border-[var(--color-brand)]/60"
      >
        <ChevronLeft size={17} />
      </button>
      <button
        onClick={() => go(1)}
        aria-label="Next promotion"
        className="hidden sm:grid place-items-center absolute right-2 top-1/2 -translate-y-1/2 w-8 h-8 rounded-full bg-black/55 border border-[var(--color-line)] text-[var(--color-ink-invert)]/85 hover:text-[var(--color-ink-invert)] hover:border-[var(--color-brand)]/60"
      >
        <ChevronRight size={17} />
      </button>

      <div className="absolute bottom-3 left-5 sm:left-7 flex items-center gap-1.5">
        {promos.map((_, i) => (
          <button
            key={i}
            onClick={() => setIdx(i)}
            aria-label={`Go to promotion ${i + 1}`}
            aria-current={i === idx}
            className={cn(
              "h-1.5 rounded-full transition-all",
              i === idx ? "w-5 bg-[var(--color-brand)]" : "w-1.5 bg-[var(--color-ink)]/30 hover:bg-[var(--color-ink)]/50",
            )}
          />
        ))}
      </div>
    </section>
  );
}

/* ============================================================
   Quick actions — the icon grid that fans players out to the
   main surfaces in a single tap.
   ============================================================ */

const TILE =
  "group relative flex flex-col items-center justify-center gap-1.5 rounded-[10px] border border-[var(--color-line)] bg-[var(--color-surface)] py-3.5 hover:border-[var(--color-brand)]/50 hover:bg-[var(--color-surface-2)] transition-colors";

export function QuickActions() {
  const { live } = useMatches();
  const openSupport = useSupport((s) => s.setOpen);

  return (
    <nav aria-label="Quick actions" className="grid grid-cols-3 sm:grid-cols-6 gap-2 mt-3">
      {quickActions.map((a) => {
        const inner = (
          <>
            <span className="text-[20px] leading-none">{a.icon}</span>
            <span className="text-[11px] font-semibold text-[var(--color-ink-dim)] group-hover:text-[var(--color-ink)] transition-colors">
              {a.label}
            </span>
            {a.live && live.length > 0 && (
              <span className="absolute top-1.5 right-1.5 num text-[9px] font-bold grad-brand text-[var(--color-on-brand)] rounded-full min-w-[16px] h-4 grid place-items-center px-1">
                {live.length}
              </span>
            )}
          </>
        );

        // Support opens the chat panel in place rather than routing anywhere.
        return a.action === "support" ? (
          <button key={a.id} onClick={() => openSupport(true)} className={TILE}>
            {inner}
          </button>
        ) : (
          <Link key={a.id} href={a.href} className={TILE}>
            {inner}
          </Link>
        );
      })}
    </nav>
  );
}

/* ============================================================
   Popular leagues — horizontal chip rail
   ============================================================ */

export function PopularLeagues() {
  return (
    <div className="mt-4">
      <div className="flex items-center gap-2.5 mb-2">
        <span className="title-bar" />
        <h2 className="font-display font-extrabold text-[13px] tracking-tight">Popular Leagues</h2>
      </div>
      <div className="flex gap-2 overflow-x-auto no-scrollbar">
        {competitions.map((c) => (
          <button key={c.id} className="chip shrink-0 px-3 py-1.5">
            {c.flag} {c.name}
          </button>
        ))}
      </div>
    </div>
  );
}

/* ============================================================
   Live-now rail — compact scrolling strip of in-play games
   ============================================================ */

export function LiveNowRail({ matches }: { matches: Match[] }) {
  if (matches.length === 0) return null;
  return (
    <div className="mt-4">
      <div className="flex items-center gap-2.5 mb-2">
        <span className="title-bar" style={{ background: "linear-gradient(180deg,#f43f5e,#dc2626)" }} />
        <h2 className="font-display font-extrabold text-[13px] tracking-tight">Live Now</h2>
        <span className="num text-[10px] font-bold text-[var(--color-rose)]">{matches.length}</span>
        <Link href="/live" className="ml-auto text-[11.5px] font-semibold text-[var(--color-brand-hi)] hover:underline">
          All live →
        </Link>
      </div>
      <div className="flex gap-2 overflow-x-auto no-scrollbar pb-1">
        {matches.slice(0, 8).map((m) => (
          <Link key={m.id} href={`/match/${m.id}`} className="shrink-0 w-[210px] card card-hover p-3">
            <div className="flex items-center gap-1.5 mb-2">
              <span className="live-dot" />
              <LiveClock
                startTimeISO={m.startTimeISO}
                sport={m.sport}
                fallbackMinute={m.minute}
                className="num text-[10px] font-bold text-[var(--color-rose)]"
              />
              <CountryFlag url={m.leagueFlagUrl} emoji={m.leagueFlag} className="ml-auto text-[11px]" />
            </div>
            <div className="flex items-center justify-between gap-2">
              <span className="text-[12px] font-semibold truncate">{m.home}</span>
              <span className="num text-[13px] font-extrabold text-[var(--color-brand)]">{m.scoreHome ?? 0}</span>
            </div>
            <div className="flex items-center justify-between gap-2 mt-1">
              <span className="text-[12px] font-semibold truncate">{m.away}</span>
              <span className="num text-[13px] font-extrabold text-[var(--color-brand)]">{m.scoreAway ?? 0}</span>
            </div>
          </Link>
        ))}
      </div>
    </div>
  );
}

/* ============================================================
   Featured match — one big card with 1 X 2 straight off the home page
   ============================================================ */

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
          <div className="absolute top-0 left-1/4 w-40 h-40 rounded-full bg-[var(--color-brand)]/15 blur-3xl animate-[orb_14s_ease-in-out_infinite]" />
          <div className="absolute bottom-0 right-1/4 w-40 h-40 rounded-full bg-[var(--color-brand-2)]/12 blur-3xl animate-[orb_18s_ease-in-out_infinite]" />
        </div>

        <div className="relative">
          <div className="flex items-center gap-2 mb-5">
            <span className="chip px-2.5 py-1 grad-brand text-[var(--color-on-brand)] font-bold border-transparent">
              ⭐ FEATURED
            </span>
            {m.live && (
              <span className="flex items-center gap-1.5 chip px-2.5 py-1 bg-[var(--color-rose)]/12 border-[var(--color-rose)]/30 text-[var(--color-rose)]">
                <span className="live-dot" /> LIVE{" "}
                <LiveClock startTimeISO={m.startTimeISO} sport={m.sport} fallbackMinute={m.minute} />
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
              <span className="font-display font-bold text-[15px] text-center group-hover:text-[var(--color-brand)] transition">
                {m.home}
              </span>
            </Link>

            <div className="flex flex-col items-center px-2">
              {m.live ? (
                <>
                  <div className="num text-[34px] font-extrabold leading-none tracking-tight">
                    {m.scoreHome ?? 0}
                    <span className="text-[var(--color-ink-faint)] mx-1.5">:</span>
                    {m.scoreAway ?? 0}
                  </div>
                  <LiveClock
                    startTimeISO={m.startTimeISO}
                    sport={m.sport}
                    fallbackMinute={m.minute}
                    className="num text-[10px] text-[var(--color-rose)] font-bold mt-1.5"
                  />
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
              <span className="font-display font-bold text-[15px] text-center group-hover:text-[var(--color-brand)] transition">
                {m.away}
              </span>
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
                    toggle({
                      id,
                      matchId: m.id,
                      match: `${m.home} v ${m.away}`,
                      league: m.league,
                      country: m.country,
                      market: "Match Result",
                      pick: p.name,
                      odds: p.odds,
                    });
                  }}
                  className="odds-btn group/o flex items-center justify-between px-4 py-3 disabled:opacity-40 disabled:cursor-not-allowed"
                >
                  <span className="text-[12px] font-medium text-[var(--color-ink-dim)] group-data-[active=true]/o:text-[var(--color-on-brand)]/70 truncate">
                    {p.name}
                  </span>
                  <span className="num text-[15px] font-bold">{p.odds.toFixed(2)}</span>
                </button>
              );
            })}
          </div>

          <Link
            href={`/match/${m.id}`}
            className="flex items-center justify-center gap-1.5 mt-4 text-[12px] font-semibold text-[var(--color-brand-hi)] hover:underline"
          >
            View all {m.marketCount} markets <ArrowRight size={13} />
          </Link>
        </div>
      </div>
    </div>
  );
}
