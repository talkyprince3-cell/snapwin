"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { Ticket, ArrowRight, Lock, X, Loader2, RotateCcw } from "lucide-react";
import { Brand } from "@/components/brand";
import { useSlip } from "@/lib/store";
import type { Selection } from "@/lib/types";
import { cn } from "@/lib/utils";

type LegState = "upcoming" | "live" | "finished";
type LegResult = "won" | "lost" | "pending";

interface EnrichedLeg extends Selection {
  state: LegState;
  homeScore: number | null;
  awayScore: number | null;
  minute: string | null;
  kickoff: string | null;
  result: LegResult;
}

interface LoadedBooking {
  booking: { code: string; totalOdds: number; selections: Selection[] };
  legs: EnrichedLeg[];
  playable: boolean;
}

export default function BookingPage() {
  const router = useRouter();
  const { clear, add, setMobileOpen } = useSlip();
  const [code, setCode] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [data, setData] = useState<LoadedBooking | null>(null);

  async function load() {
    const clean = code.trim();
    if (clean.length < 4) {
      setError("Enter a valid booking code.");
      return;
    }
    setError("");
    setData(null);
    setLoading(true);
    try {
      const res = await fetch(`/api/bookings?code=${encodeURIComponent(clean)}`);
      if (res.status === 404) {
        setError("No booking found with that code. Check and try again.");
        return;
      }
      if (!res.ok) {
        setError("Couldn't load that code — please try again.");
        return;
      }
      const json = (await res.json()) as LoadedBooking;
      if (!json.booking?.selections?.length) {
        setError("That booking has no selections.");
        return;
      }
      setData(json);
    } catch {
      setError("Network error — please try again.");
    } finally {
      setLoading(false);
    }
  }

  // Drop the booked selections into the slip and take the punter to the games.
  function loadIntoSlip() {
    if (!data) return;
    clear();
    data.booking.selections.forEach((s) => add(s));
    setMobileOpen(true);
    router.push("/");
  }

  function reset() {
    setData(null);
    setCode("");
    setError("");
  }

  // Overall booking result, once every leg has a verdict.
  const overall = (() => {
    if (!data) return null;
    if (data.legs.some((l) => l.result === "lost")) return "lost";
    if (data.legs.length && data.legs.every((l) => l.result === "won")) return "won";
    return null;
  })();

  return (
    <div className="relative min-h-dvh overflow-hidden grid place-items-center px-4 py-10">
      <div className="absolute inset-0 pointer-events-none">
        <div className="absolute top-[-10%] left-[10%] w-[420px] h-[420px] rounded-full bg-[var(--color-brand)]/20 blur-[100px]" />
        <div className="absolute bottom-[-10%] right-[5%] w-[380px] h-[380px] rounded-full bg-[var(--color-cyan)]/15 blur-[100px]" />
      </div>

      <Link href="/" className="absolute top-5 left-5 z-10">
        <Brand size={30} />
      </Link>

      <div className="relative w-full max-w-[460px]">
        <div className="grad-border animate-rise">
          <div className="relative p-7 sm:p-9">
            {!data ? (
              <div className="text-center">
                <div className="mx-auto w-16 h-16 mb-5 grid place-items-center rounded-2xl bg-[var(--color-brand)]/12 text-[var(--color-brand)]">
                  <Ticket size={30} strokeWidth={1.6} />
                </div>

                <h1 className="font-display font-extrabold text-[22px]">Load a Booking Code</h1>
                <p className="text-[13px] text-[var(--color-ink-dim)] mt-2 leading-relaxed">
                  Enter a booking code to load the selections into your slip, or to
                  follow the live scores and results once the games start.
                </p>

                <div
                  className={cn(
                    "relative mt-6 flex items-center rounded-2xl border bg-[var(--color-surface)] transition",
                    error
                      ? "border-[var(--color-rose)]/60"
                      : "border-[var(--color-line)] focus-within:border-[var(--color-brand)]/60",
                  )}
                >
                  <Lock size={16} className="ml-4 text-[var(--color-ink-faint)]" />
                  <input
                    value={code}
                    onChange={(e) => {
                      setCode(e.target.value.toUpperCase());
                      setError("");
                    }}
                    onKeyDown={(e) => e.key === "Enter" && load()}
                    placeholder="Enter booking code"
                    className="flex-1 bg-transparent num text-[14px] tracking-wider px-3 py-4 outline-none placeholder:text-[var(--color-ink-faint)] placeholder:tracking-normal placeholder:font-sans"
                  />
                  <button
                    onClick={load}
                    disabled={loading}
                    className="m-1.5 flex items-center gap-1.5 rounded-xl grad-brand text-white px-4 py-2.5 font-display font-bold text-[13px] active:scale-95 transition disabled:opacity-60"
                  >
                    {loading ? (
                      <Loader2 size={16} className="animate-spin" />
                    ) : (
                      <>
                        Load <ArrowRight size={15} />
                      </>
                    )}
                  </button>
                </div>

                {error && (
                  <p className="text-[12px] text-[var(--color-rose)] mt-2.5 flex items-center justify-center gap-1.5">
                    <X size={13} /> {error}
                  </p>
                )}

                <p className="text-[12px] text-[var(--color-ink-dim)] mt-6">
                  Want to check a placed ticket instead?{" "}
                  <Link href="/verify" className="font-semibold text-[var(--color-cyan)] hover:underline">
                    Verify a ticket
                  </Link>
                </p>
              </div>
            ) : (
              <div>
                <div className="flex items-center justify-between mb-4">
                  <div>
                    <div className="text-[11px] uppercase tracking-wide text-[var(--color-ink-faint)]">
                      Booking code
                    </div>
                    <div className="num font-display font-extrabold text-[20px] tracking-widest">
                      {data.booking.code}
                    </div>
                  </div>
                  {overall && (
                    <span
                      className={cn(
                        "text-[11px] font-bold uppercase px-2.5 py-1 rounded-full border",
                        overall === "won"
                          ? "text-[var(--color-emerald)] border-[var(--color-emerald)]/40 bg-[var(--color-emerald)]/10"
                          : "text-[var(--color-rose)] border-[var(--color-rose)]/40 bg-[var(--color-rose)]/10",
                      )}
                    >
                      {overall === "won" ? "Booking won" : "Booking lost"}
                    </span>
                  )}
                </div>

                <ul className="space-y-2">
                  {data.legs.map((l) => (
                    <li
                      key={l.id}
                      className="rounded-xl border border-[var(--color-line)] bg-[var(--color-surface)] px-3.5 py-3"
                    >
                      <div className="flex items-start justify-between gap-2">
                        <div className="min-w-0">
                          <p className="text-[13px] font-semibold truncate">{l.match}</p>
                          <p className="text-[11.5px] text-[var(--color-ink-dim)] truncate">
                            {l.market} · <span className="text-white">{l.pick}</span> · {l.odds.toFixed(2)}
                          </p>
                        </div>
                        <LegBadge leg={l} />
                      </div>
                    </li>
                  ))}
                </ul>

                <div className="flex items-center justify-between mt-4 text-[12px]">
                  <span className="text-[var(--color-ink-dim)]">Total odds</span>
                  <span className="num font-bold">{data.booking.totalOdds.toFixed(2)}</span>
                </div>

                <div className="mt-5 flex gap-2">
                  <button
                    onClick={reset}
                    className="flex items-center justify-center gap-1.5 rounded-xl border border-[var(--color-line)] px-4 py-3 text-[13px] font-semibold text-[var(--color-ink-dim)] hover:text-white transition"
                  >
                    <RotateCcw size={14} /> Another code
                  </button>
                  {data.playable ? (
                    <button
                      onClick={loadIntoSlip}
                      className="flex-1 flex items-center justify-center gap-1.5 rounded-xl grad-brand text-white px-4 py-3 font-display font-bold text-[13px] active:scale-95 transition"
                    >
                      Load into slip <ArrowRight size={15} />
                    </button>
                  ) : (
                    <div className="flex-1 grid place-items-center rounded-xl border border-[var(--color-line)] bg-[var(--color-surface-2)] px-4 py-3 text-[12px] text-[var(--color-ink-dim)] text-center">
                      Games have started — view only
                    </div>
                  )}
                </div>
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}

function LegBadge({ leg }: { leg: EnrichedLeg }) {
  const score =
    leg.homeScore != null && leg.awayScore != null
      ? `${leg.homeScore}-${leg.awayScore}`
      : null;

  if (leg.state === "finished") {
    const tone =
      leg.result === "won"
        ? "text-[var(--color-emerald)] border-[var(--color-emerald)]/40 bg-[var(--color-emerald)]/10"
        : leg.result === "lost"
          ? "text-[var(--color-rose)] border-[var(--color-rose)]/40 bg-[var(--color-rose)]/10"
          : "text-[var(--color-ink-dim)] border-[var(--color-line)]";
    const label = leg.result === "won" ? "Won" : leg.result === "lost" ? "Lost" : "FT";
    return (
      <span className={cn("shrink-0 text-[11px] font-bold uppercase px-2 py-1 rounded-lg border tabular-nums", tone)}>
        {score ? `${score} · ` : ""}{label}
      </span>
    );
  }

  if (leg.state === "live") {
    return (
      <span className="shrink-0 text-[11px] font-bold uppercase px-2 py-1 rounded-lg border border-[var(--color-live,#f43f5e)]/40 bg-[var(--color-live,#f43f5e)]/10 text-[var(--color-live,#f43f5e)] tabular-nums flex items-center gap-1">
        <span className="w-1.5 h-1.5 rounded-full bg-current animate-pulse" />
        {score ?? "LIVE"}{leg.minute ? ` · ${leg.minute}` : ""}
      </span>
    );
  }

  return (
    <span className="shrink-0 text-[11px] font-semibold uppercase px-2 py-1 rounded-lg border border-[var(--color-line)] text-[var(--color-ink-dim)]">
      Upcoming
    </span>
  );
}
