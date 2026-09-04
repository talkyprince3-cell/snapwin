"use client";

import { AppShell } from "@/components/app-shell";
import { FixtureList, SectionHead } from "@/components/match-card";
import {
  HeroCarousel,
  QuickActions,
  PopularLeagues,
  LiveNowRail,
  FeaturedMatch,
} from "@/components/home-sections";
import { WinnersTicker } from "@/components/winners-ticker";
import { useMatches } from "@/lib/use-matches";

export default function Home() {
  const { live, today, tomorrow, week, loading } = useMatches();
  const featured = live[0] ?? today[0] ?? week[0];

  return (
    <AppShell>
      {/* Portal masthead: promo banner, then the tap targets that fan players
          out to the rest of the app, then the odds themselves. */}
      <HeroCarousel />
      <QuickActions />
      <WinnersTicker />
      <LiveNowRail matches={live} />
      <PopularLeagues />

      {featured && <FeaturedMatch m={featured} />}

      {loading ? (
        <p className="text-[13px] text-[var(--color-ink-faint)] py-8 text-center">Loading matches…</p>
      ) : (
        <>
          <SectionHead title="Highlights" more={`${today.length} today`} />
          <FixtureList matches={today} empty="No more matches today." />

          <SectionHead title="Tomorrow" more={`${tomorrow.length} matches`} />
          <FixtureList matches={tomorrow} empty="No fixtures listed for tomorrow yet." />

          <SectionHead title="This Week" more="All" />
          <FixtureList matches={week} empty="No upcoming fixtures this week yet." />
        </>
      )}
    </AppShell>
  );
}
