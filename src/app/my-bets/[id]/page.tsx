"use client";

import { use, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { ChevronLeft, ChevronsDown, ChevronsUp, Copy, Check, Loader2, IdCard } from "lucide-react";
import { AppShell } from "@/components/app-shell";
import { WinCongrats } from "@/components/win-congrats";
import { useBets } from "@/lib/use-bets";
import { useMatches } from "@/lib/use-matches";
import { useSlip } from "@/lib/store";
import { formatMoneyWithCurrency } from "@/lib/format-money";
import { cn } from "@/lib/utils";
import type { Bet, BetLeg } from "@/lib/types";

/** Headline word across the top of the ticket, and the tone it carries. */
const HEADLINE = {
  won: { text: "WON", cls: "text-[var(--color-emerald)]" },
  lost: { text: "LOST", cls: "text-[var(--color-rose)]" },
  playing: { text: "IN PLAY", cls: "text-[var(--color-cyan)]" },
  pending: { text: "NOT STARTED", cls: "text-[var(--color-ink-dim)]" },
  cashout: { text: "CASHED OUT", cls: "text-[var(--color-cyan)]" },
} as const;

/** Small badge on each selection, mirroring the leg's own state. */
const LEG_BADGE = {
  won: { text: "WON", cls: "border-[var(--color-emerald)]/50 text-[var(--color-emerald)]" },
  lost: { text: "LOST", cls: "border-[var(--color-rose)]/50 text-[var(--color-rose)]" },
  playing: { text: "LIVE", cls: "border-[var(--color-cyan)]/50 text-[var(--color-cyan)]" },
  pending: { text: "PRE", cls: "border-[var(--color-rose)]/50 text-[var(--color-rose)]" },
} as const;

type LegState = keyof typeof LEG_BADGE;

export default function TicketPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const { bets, loading, loggedIn } = useBets();
  const { all } = useMatches();
  const [showLegs, setShowLegs] = useState(true);
  const [celebrate, setCelebrate] = useState(false);
  const [copied, setCopied] = useState(false);

  const bet = bets.find((b) => b.id === decodeURIComponent(id));

  if (!loggedIn) return <Shell><Notice title="Sign in to view this ticket" cta /></Shell>;
  if (loading) return <Shell><p className="text-[13px] text-[var(--color-ink-faint)] py-12 text-center">Loading ticket…</p></Shell>;
  if (!bet) return <Shell><Notice title="Ticket not found" body="It may belong to another account." /></Shell>;

  const byId = new Map(all.map((m) => [m.id, m]));
  const legState = (l: BetLeg): LegState =>
    l.result === "pending" && (l.matchId ? byId.get(l.matchId)?.live : false) ? "playing" : l.result;

  const anyLive = bet.status === "pending" && bet.legs.some((l) => legState(l) === "playing");
  const head = HEADLINE[anyLive ? "playing" : bet.status];
  const settled = bet.status === "won" || bet.status === "lost";
  const toReturn = bet.toReturn ?? bet.potential;

  const copyId = () => {
    navigator.clipboard?.writeText(bet.id).then(() => {
      setCopied(true);
      setTimeout(() => setCopied(false), 1600);
    }).catch(() => {});
  };

  return (
    <Shell>
      {bet.status === "won" && (
        <WinCongrats
          open={celebrate}
          onClose={() => setCelebrate(false)}
          onDetails={() => setCelebrate(false)}
          amount={bet.potential}
          currency={bet.currency}
          verifyCode={bet.verifyCode}
          ticketId={bet.id}
        />
      )}

      {/* ── Ticket head: placed time, type, id ─────────────────────── */}
      <div className="flex items-center justify-between gap-3 rounded-t-[var(--radius-card)] border border-[var(--color-line)] bg-[var(--color-surface-2)] px-3 py-2">
        <span className="num text-[11.5px] text-[var(--color-ink-dim)] truncate">
          {bet.date} · <span className="font-bold text-[var(--color-ink)]">{bet.type === "multi" ? "Multiple" : "Single"}</span>
        </span>
        <span className="flex items-center gap-2 shrink-0">
          <span className="num text-[11.5px] text-[var(--color-ink-dim)]">
            Ticket ID: <span className="font-bold text-[var(--color-ink)]">{bet.id}</span>
          </span>
          <button
            onClick={copyId}
            aria-label="Copy ticket ID"
            className="flex items-center gap-1 rounded-full border border-[var(--color-line-2)] px-2 py-0.5 text-[10.5px] font-bold text-[var(--color-ink-dim)] hover:text-white transition-colors"
          >
            {copied ? <Check size={11} className="text-[var(--color-emerald)]" /> : <Copy size={11} />}
            {copied ? "Copied" : "Copy"}
          </button>
        </span>
      </div>

      {/* ── Summary ───────────────────────────────────────────────── */}
      <div className="relative overflow-hidden border-x border-b border-[var(--color-line)] rounded-b-[var(--radius-card)] bg-[var(--color-surface)] px-4 pt-4 pb-5">
        <div className="absolute inset-0 opacity-[0.07] pointer-events-none bg-[radial-gradient(circle_at_50%_0%,var(--color-brand),transparent_65%)]" />
        <div className="relative">
          <p className={cn("text-center font-display font-extrabold text-[30px] tracking-tight leading-none", head.cls)}>
            {head.text}
          </p>

          <dl className="mt-4 space-y-1.5">
            <SumRow label="Total Stake" value={formatMoneyWithCurrency(bet.stake, bet.currency)} />
            <SumRow label="Total Odds" value={bet.totalOdds.toFixed(2)} />
            <SumRow label="To Return" value={formatMoneyWithCurrency(toReturn, bet.currency)} />
            <SumRow
              label="Total Return"
              // A dash until it settles: an open ticket has not returned anything,
              // and showing the potential here would read as money already won.
              value={settled ? formatMoneyWithCurrency(bet.payout ?? bet.potential, bet.currency) : "- -"}
              strong={settled}
              tone={bet.status === "won" ? "win" : bet.status === "lost" ? "loss" : undefined}
            />
          </dl>

          <div className="relative mt-4 flex justify-center">
            <div className="absolute inset-x-0 top-1/2 border-t border-dashed border-[var(--color-brand)]/35" />
            <button
              onClick={() => setShowLegs((v) => !v)}
              aria-expanded={showLegs}
              className="relative flex items-center gap-1.5 rounded-full grad-brand text-[var(--color-on-brand)] px-5 py-2 font-display font-extrabold text-[13px]"
            >
              {showLegs ? "Hide Details" : "Check Details"}
              {showLegs ? <ChevronsUp size={15} /> : <ChevronsDown size={15} />}
            </button>
          </div>
        </div>
      </div>

      {/* ── Selections ────────────────────────────────────────────── */}
      {showLegs && (
        <div className="space-y-2.5 mt-2.5">
          {bet.legs.map((leg, i) => (
            <LegCard key={i} leg={leg} state={legState(leg)} live={leg.matchId ? byId.get(leg.matchId) : undefined} />
          ))}
        </div>
      )}

      <TicketActions bet={bet} onCelebrate={() => setCelebrate(true)} />
    </Shell>
  );
}

/** One selection, in the boxed ticket style: fixture, then market and pick. */
function LegCard({
  leg,
  state,
  live,
}: {
  leg: BetLeg;
  state: LegState;
  live?: ReturnType<typeof useMatches>["all"][number];
}) {
  const badge = LEG_BADGE[state];
  const rail =
    state === "won"
      ? "bg-[var(--color-emerald)]"
      : state === "lost"
        ? "bg-[var(--color-rose)]"
        : state === "playing"
          ? "bg-[var(--color-cyan)]"
          : "bg-[var(--color-ink-faint)]/50";
  const hasScore = live && typeof live.scoreHome === "number" && typeof live.scoreAway === "number";

  return (
    <div className="card overflow-hidden flex">
      {/* Status rail — the sideways label down the left edge of the card. */}
      <div className={cn("w-[3px] shrink-0", rail)} />

      <div className="min-w-0 flex-1 p-3">
        <div className="flex items-center justify-between gap-2">
          <span className="text-[11px] text-[var(--color-ink-faint)] truncate">
            {[leg.country, leg.league].filter(Boolean).join(" · ") || "Football"}
          </span>
          <span className={cn("shrink-0 rounded border px-1.5 py-0.5 text-[9.5px] font-bold tracking-wide", badge.cls)}>
            {badge.text}
          </span>
        </div>

        {/* Teams, each with its score column — dashes when we have no score. */}
        <div className="mt-2 rounded-[var(--radius-ctl)] bg-[var(--color-surface-2)] px-3 py-2">
          <TeamLine name={leg.home ?? leg.match} score={hasScore ? live!.scoreHome : undefined} />
          {leg.away && <TeamLine name={leg.away} score={hasScore ? live!.scoreAway : undefined} />}
        </div>

        <dl className="mt-2 space-y-1">
          <LegRow label="Market" value={leg.market ?? "1X2"} />
          <LegRow label="Pick" value={`${leg.pick} @ ${leg.odds.toFixed(2)}`} strong />
        </dl>

        {leg.matchId && (
          <Link
            href={`/match/${leg.matchId}`}
            className="mt-2.5 w-full flex items-center justify-center gap-1.5 rounded-full border border-[var(--color-line-2)] py-2 text-[12px] font-semibold text-[var(--color-ink-dim)] hover:text-white hover:border-[var(--color-brand)]/50 transition-colors"
          >
            <IdCard size={13} /> Match Details
          </Link>
        )}
      </div>
    </div>
  );
}

function TeamLine({ name, score }: { name: string; score?: number }) {
  return (
    <div className="flex items-center justify-between gap-3 py-0.5">
      <span className="text-[13px] font-semibold truncate">{name}</span>
      <span className="num text-[13px] font-bold shrink-0 text-[var(--color-ink-dim)]">
        {typeof score === "number" ? score : "-"}
      </span>
    </div>
  );
}

/**
 * Bottom actions. Booking Code turns the ticket back into a shareable slip and
 * Rebet loads it into the slip to stake again — both work off the selections we
 * already hold. Cash Out is rendered disabled rather than omitted, because the
 * platform has no cash-out rail yet and a dead-but-present control is clearer
 * than a missing one.
 */
function TicketActions({ bet, onCelebrate }: { bet: Bet; onCelebrate: () => void }) {
  const router = useRouter();
  const { clear, add } = useSlip();
  const [busy, setBusy] = useState(false);
  const [code, setCode] = useState<string | null>(null);
  const [err, setErr] = useState<string | null>(null);

  const selections = bet.legs
    .filter((l) => !!l.matchId)
    .map((l) => ({
      id: `${l.matchId}-${l.market ?? "1X2"}-${l.pick}`,
      matchId: l.matchId!,
      match: l.match,
      league: l.league,
      country: l.country,
      market: l.market ?? "Match Result",
      pick: l.pick,
      odds: l.odds,
    }));

  const rebet = () => {
    if (selections.length === 0) return;
    clear();
    selections.forEach(add);
    router.push("/");
  };

  const makeBookingCode = async () => {
    if (selections.length === 0 || busy) return;
    setBusy(true);
    setErr(null);
    try {
      const res = await fetch("/api/bookings", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ selections }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        setErr(data.error ?? "Could not create a booking code.");
        return;
      }
      setCode(data.booking?.code ?? null);
    } catch {
      setErr("Network error — please try again.");
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="mt-4 mb-2">
      {code && (
        <p className="mb-2 text-center text-[12.5px]">
          <span className="text-[var(--color-ink-dim)]">Booking code: </span>
          <span className="num font-bold tracking-wider text-[var(--color-brand)]">{code}</span>
        </p>
      )}
      {err && <p className="mb-2 text-center text-[12px] text-[var(--color-rose)]">{err}</p>}

      <div className="grid grid-cols-3 gap-2">
        <button
          onClick={makeBookingCode}
          disabled={busy || selections.length === 0}
          className="flex items-center justify-center gap-1.5 rounded-full border border-[var(--color-brand)]/60 py-2.5 text-[12.5px] font-display font-bold text-[var(--color-brand)] hover:bg-[var(--color-brand)]/10 disabled:opacity-45 transition-colors"
        >
          {busy && <Loader2 size={13} className="animate-spin" />} Booking Code
        </button>
        <button
          onClick={rebet}
          disabled={selections.length === 0}
          className="rounded-full border border-[var(--color-brand)]/60 py-2.5 text-[12.5px] font-display font-bold text-[var(--color-brand)] hover:bg-[var(--color-brand)]/10 disabled:opacity-45 transition-colors"
        >
          Rebet
        </button>
        {bet.status === "won" ? (
          <button
            onClick={onCelebrate}
            className="rounded-full grad-brand text-[var(--color-on-brand)] py-2.5 text-[12.5px] font-display font-extrabold"
          >
            Show Off
          </button>
        ) : (
          <button
            disabled
            title="Cash out is not available yet"
            className="rounded-full bg-[var(--color-surface-2)] border border-[var(--color-line)] py-2.5 text-[12.5px] font-display font-bold text-[var(--color-ink-faint)] cursor-not-allowed"
          >
            Cash Out
          </button>
        )}
      </div>
    </div>
  );
}

function SumRow({
  label,
  value,
  strong,
  tone,
}: {
  label: string;
  value: string;
  strong?: boolean;
  tone?: "win" | "loss";
}) {
  return (
    <div className="flex items-center justify-between gap-3">
      <dt className="text-[13px] text-[var(--color-ink-dim)]">{label}</dt>
      <dd
        className={cn(
          "num text-[14px] font-bold",
          strong && "text-[15px] font-extrabold",
          tone === "win" && "text-[var(--color-emerald)]",
          tone === "loss" && "text-[var(--color-ink-faint)]",
        )}
      >
        {value}
      </dd>
    </div>
  );
}

function LegRow({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
  return (
    <div className="flex items-center justify-between gap-3 text-[12px]">
      <dt className="text-[var(--color-ink-faint)] shrink-0">{label}</dt>
      <dd className={cn("text-right truncate", strong ? "font-bold text-[var(--color-ink)]" : "text-[var(--color-ink-dim)]")}>
        {value}
      </dd>
    </div>
  );
}

function Shell({ children }: { children: React.ReactNode }) {
  return (
    <AppShell tabs={false} betSlip={false}>
      <div className="flex items-center gap-2 mb-3">
        <Link
          href="/my-bets"
          aria-label="Back to My Bets"
          className="grid place-items-center w-9 h-9 rounded-[var(--radius-ctl)] border border-[var(--color-line)] bg-[var(--color-surface)] text-[var(--color-ink-dim)] hover:text-white hover:border-[var(--color-line-2)] transition-colors"
        >
          <ChevronLeft size={18} />
        </Link>
        <h1 className="font-display font-extrabold text-[17px]">Ticket Details</h1>
      </div>
      {children}
    </AppShell>
  );
}

function Notice({ title, body, cta }: { title: string; body?: string; cta?: boolean }) {
  return (
    <div className="card p-8 text-center">
      <div className="font-display font-bold text-[15px]">{title}</div>
      {body && <p className="text-[12.5px] text-[var(--color-ink-dim)] mt-1.5">{body}</p>}
      {cta && (
        <Link
          href="/login"
          className="inline-block mt-4 rounded-[var(--radius-ctl)] px-5 py-2.5 font-display font-bold grad-brand text-[var(--color-on-brand)] text-[13px]"
        >
          Log in
        </Link>
      )}
    </div>
  );
}
