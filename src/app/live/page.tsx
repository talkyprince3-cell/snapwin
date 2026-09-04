"use client";

import { useState } from "react";
import { AppShell } from "@/components/app-shell";
import { FixtureList, SectionHead } from "@/components/match-card";
import { useMatches } from "@/lib/use-matches";
import { cn } from "@/lib/utils";

const TABS = ["All", "Football", "Basketball", "Tennis"];

export default function LivePage() {
  const [tab, setTab] = useState("All");
  const { live, today, loading } = useMatches();

  return (
    <AppShell>
      <div className="flex items-center justify-between mb-4">
        <div className="flex items-center gap-2.5">
          <span className="title-bar" style={{ background: "linear-gradient(180deg,#f43f5e,#dc2626)" }} />
          <h1 className="font-display font-extrabold text-[18px]">Live In-Play</h1>
          <span className="flex items-center gap-1.5 num text-[10px] font-bold grad-brand text-white px-2 py-1 rounded-md tracking-wide">
            <span className="w-1.5 h-1.5 rounded-full bg-white animate-pulse" /> {live.length} LIVE
          </span>
        </div>
      </div>

      <div className="flex gap-2 overflow-x-auto no-scrollbar mb-5">
        {TABS.map((t) => (
          <button key={t} data-active={tab === t} onClick={() => setTab(t)} className="chip shrink-0 px-4 py-1.5">
            {t}
          </button>
        ))}
      </div>

      {loading ? (
        <p className="text-[13px] text-[var(--color-ink-faint)] py-8 text-center">Loading live matches…</p>
      ) : live.length === 0 ? (
        <p className="text-[13px] text-[var(--color-ink-faint)] py-8 text-center">
          No matches are in play right now. Check back closer to kickoff.
        </p>
      ) : (
        <FixtureList matches={live} empty="No live matches right now." />
      )}

      <SectionHead title="Starting Soon" more="Pre-match" href="/" />
      {today.length > 0 ? (
        <FixtureList matches={today.slice(0, 10)} empty="No pre-match fixtures left today." />
      ) : (
        <p className={cn("text-[13px] text-[var(--color-ink-faint)]")}>
          No pre-match fixtures left today. Set a favourite to get notified.
        </p>
      )}
    </AppShell>
  );
}
