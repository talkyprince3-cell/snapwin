"use client";

import { useState } from "react";
import { AppShell } from "@/components/app-shell";
import { FixtureList, SectionHead } from "@/components/match-card";
import { PromoStrip, StatRibbon, FeaturedMatch } from "@/components/home-sections";
import { WinnersTicker } from "@/components/winners-ticker";
import { competitions } from "@/lib/data";
import { useMatches } from "@/lib/use-matches";

const FILTERS = ["All", "Football", "Top Leagues", "Boosted", "Ghana 🇬🇭"];

export default function Home() {
  const [filter, setFilter] = useState("All");
  const { live, today, tomorrow, week, loading } = useMatches();
  const featured = live[0] ?? today[0] ?? week[0];

  return (
    <AppShell>
      <PromoStrip />
      <StatRibbon />
      <WinnersTicker />
      {featured && <FeaturedMatch m={featured} />}

      {/* league filter chips */}
      <div className="flex gap-2 overflow-x-auto no-scrollbar mt-6">
        {FILTERS.map((f) => (
          <button key={f} data-active={filter === f} onClick={() => setFilter(f)} className="chip shrink-0 px-3.5 py-1.5">
            {f}
          </button>
        ))}
        <div className="w-px bg-[var(--color-line)] mx-1 shrink-0" />
        {competitions.slice(0, 4).map((c) => (
          <button key={c.id} className="chip shrink-0 px-3 py-1.5">
            {c.flag} {c.name}
          </button>
        ))}
      </div>

      {loading ? (
        <p className="text-[13px] text-[var(--color-ink-faint)] py-8 text-center">Loading matches…</p>
      ) : (
        <>
          <SectionHead title="Live In-Play" more="View All" href="/live" accent="linear-gradient(180deg,#f43f5e,#dc2626)" />
          <FixtureList matches={live} empty="No live matches right now." />

          <SectionHead title="Today" more={`${today.length} matches`} />
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
