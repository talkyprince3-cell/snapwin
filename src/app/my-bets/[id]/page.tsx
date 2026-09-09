"use client";

import { use, useState } from "react";
import Link from "next/link";
import { ChevronLeft, Check, X, Clock, Radio, Trophy, Copy } from "lucide-react";
import { AppShell } from "@/components/app-shell";
import { WinCongrats } from "@/components/win-congrats";
import { useBets } from "@/lib/use-bets";
import { useMatches } from "@/lib/use-matches";
import { formatMoneyWithCurrency } from "@/lib/format-money";
import { cn } from "@/lib/utils";
import type { BetLeg } from "@/lib/types";

const STATUS = {
  won: {
    label: "Won",
    cls: "bg-[var(--color-emerald)]/12 text-[var(--color-emerald)] border-[var(--color-emerald)]/30",
    icon: Check,
  },
  lost: {
    label: "Lost",
    cls: "bg-[var(--color-rose)]/12 text-[var(--color-rose)] border-[var(--color-rose)]/30",
    icon: X,
  },
  playing: {
    label: "Playing",
    cls: "bg-[var(--color-cyan)]/12 text-[var(--color-cyan)] border-[var(--color-cyan)]/30",
    icon: Radio,
  },
  pending: {
    label: "Pending",
    cls: "bg-[var(--color-amber)]/12 text-[var(--color-amber)] border-[var(--color-amber)]/30",
    icon: Clock,
  },
  cashout: {
    label: "Cash Out",
    cls: "bg-[var(--color-cyan)]/12 text-[var(--color-cyan)] border-[var(--color-cyan)]/30",
    icon: Check,
  },
} as const;

export default function TicketPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const { bets, loading, loggedIn } = useBets();
  // The feed only carries current and upcoming fixtures, so a leg's score is
  // available while its game is on and gone once it ages out. Legs are laid
  // out to read correctly either way rather than showing a fake 0-0.
  const { all } = useMatches();
  const [celebrate, setCelebrate] = useState(false);
  const [copied, setCopied] = useState(false);

  const bet = bets.find((b) => b.id === decodeURIComponent(id));

  if (!loggedIn) return <Shell><Notice title="Sign in to view this ticket" cta /></Shell>;
  if (loading) return <Shell><p className="text-[13px] text-[var(--color-ink-faint)] py-12 text-center">Loading ticket…</p></Shell>;
  if (!bet) return <Shell><Notice title="Ticket not found" body="It may belong to another account." /></Shell>;

  const liveById = new Map(all.map((m) => [m.id, m]));
  const anyLive = bet.status === "pending" && bet.legs.some((l) => !!l.matchId && liveById.get(l.matchId)?.live);
  const display = anyLive ? "playing" : bet.status;
  const s = STATUS[display];
  const Icon = s.icon;
  const isWon = bet.status === "won";
  const settled = bet.status === "won" || bet.status === "lost";

  const copyCode = async () => {
    if (!bet.verifyCode) return;
    try {
      await navigator.clipboard.writeText(bet.verifyCode);
      setCopied(true);
      setTimeout(() => setCopied(false), 1600);
    } catch {
      /* clipboard blocked — the code is on screen to read anyway */
    }
  };

  return (
    <Shell>
      {isWon && (
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

      {/* ── Summary ───────────────────────────────────────────────── */}
      <div className="card p-4">
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0">
            <div className="num text-[11px] text-[var(--color-ink-faint)]">Ticket ID</div>
            <div className="num text-[14px] font-bold truncate">{bet.id}</div>
            <div className="num text-[11px] text-[var(--color-ink-faint)] mt-0.5">{bet.date}</div>
          </div>
          <span className={cn("flex items-center gap-1.5 shrink-0 px-2.5 py-1 rounded-md border text-[11px] font-bold uppercase tracking-wide", s.cls)}>
            <Icon size={13} /> {s.label}
          </span>
        </div>

        <div className="flex items-center justify-between mt-4 pt-4 border-t border-[var(--color-line)]">
          <div>
            <div className="text-[11px] text-[var(--color-ink-dim)]">
              {settled ? "Total Return" : "Potential Return"}
            </div>
            <div
              className={cn(
                "num text-[26px] font-extrabold leading-tight",
                bet.status === "won"
                  ? "text-[var(--color-emerald)]"
                  : bet.status === "lost"
                    ? "text-[var(--color-ink-faint)] line-through"
                    : "grad-text",
              )}
            >
              {formatMoneyWithCurrency(bet.potential, bet.currency)}
            </div>
          </div>
          <div className="font-display font-bold text-[13px] text-[var(--color-ink-dim)]">
            {bet.type === "multi" ? `${bet.legs.length}-Fold Multiple` : "Single"}
          </div>
        </div>

        <dl className="grid grid-cols-3 gap-2 mt-4">
          <Stat label="Total Stake" value={formatMoneyWithCurrency(bet.stake, bet.currency)} />
          <Stat label="Total Odds" value={bet.totalOdds.toFixed(2)} />
          <Stat label="Selections" value={String(bet.legs.length)} />
        </dl>

        {bet.verifyCode && (
          <button
            onClick={copyCode}
            className="mt-3 w-full flex items-center justify-between gap-2 rounded-[var(--radius-ctl)] border border-[var(--color-line)] bg-[var(--color-surface-2)] px-3 py-2.5 hover:border-[var(--color-brand)]/50 transition-colors"
          >
            <span className="text-[11px] text-[var(--color-ink-dim)]">Verify Code</span>
            <span className="flex items-center gap-2 min-w-0">
              <span className="num text-[12px] font-bold tracking-wider truncate">{bet.verifyCode}</span>
              <Copy size={13} className="shrink-0 text-[var(--color-ink-faint)]" />
            </span>
          </button>
        )}
        {copied && <p className="text-[11px] text-[var(--color-emerald)] mt-1.5 text-right">Copied</p>}

        {isWon && (
          <button
            onClick={() => setCelebrate(true)}
            className="mt-3 w-full flex items-center justify-center gap-2 rounded-[var(--radius-ctl)] py-3 font-display font-extrabold text-[13px] grad-brand text-[var(--color-on-brand)]"
          >
            <Trophy size={15} /> Show Off
          </button>
        )}
      </div>

      {/* ── Selections ────────────────────────────────────────────── */}
      <div className="flex items-center gap-2.5 mt-5 mb-2">
        <span className="title-bar" />
        <h2 className="font-display font-extrabold text-[13px] tracking-tight">
          Selections ({bet.legs.length})
        </h2>
      </div>

      <div className="space-y-2">
        {bet.legs.map((leg, i) => (
          <LegRow key={i} leg={leg} live={leg.matchId ? liveById.get(leg.matchId) : undefined} />
        ))}
      </div>

      <Link
        href="/my-bets"
        className="mt-5 mb-2 w-full flex items-center justify-center gap-2 rounded-[var(--radius-ctl)] border border-[var(--color-line)] bg-[var(--color-surface)] py-3 font-display font-bold text-[13px] text-[var(--color-ink-dim)] hover:text-white hover:border-[var(--color-line-2)] transition-colors"
      >
        Back to My Bets
      </Link>
    </Shell>
  );
}

/** One selection, laid out the way a ticket reads: fixture, then the pick. */
function LegRow({
  leg,
  live,
}: {
  leg: BetLeg;
  live?: ReturnType<typeof useMatches>["all"][number];
}) {
  const result = leg.result === "pending" && live?.live ? "playing" : leg.result;
  const dot =
    result === "won"
      ? "bg-[var(--color-emerald)]"
      : result === "lost"
        ? "bg-[var(--color-rose)]"
        : result === "playing"
          ? "bg-[var(--color-cyan)] animate-pulse"
          : "bg-[var(--color-amber)]";
  const tone =
    result === "won"
      ? "text-[var(--color-emerald)]"
      : result === "lost"
        ? "text-[var(--color-rose)]"
        : result === "playing"
          ? "text-[var(--color-cyan)]"
          : "text-[var(--color-amber)]";

  // Scores exist only while the fixture is still in the feed; a settled leg
  // whose game has aged out simply shows no score line rather than a fake one.
  const hasScore = live && typeof live.scoreHome === "number" && typeof live.scoreAway === "number";

  return (
    <div className="card p-3">
      <div className="flex items-start gap-2.5">
        <span className={cn("mt-1.5 w-2 h-2 rounded-full shrink-0", dot)} />
        <div className="min-w-0 flex-1">
          {(leg.league || leg.country) && (
            <div className="text-[10.5px] text-[var(--color-ink-faint)] truncate">
              {[leg.country, leg.league].filter(Boolean).join(" · ")}
            </div>
          )}

          <div className="text-[13px] font-semibold mt-0.5">
            {leg.home && leg.away ? (
              <span className="flex items-center gap-1.5 flex-wrap">
                <span className="truncate">{leg.home}</span>
                <span className="text-[var(--color-ink-faint)] font-normal">v</span>
                <span className="truncate">{leg.away}</span>
              </span>
            ) : (
              <span className="truncate">{leg.match}</span>
            )}
          </div>

          {hasScore && (
            <div className="num text-[11px] mt-1">
              <span className="text-[var(--color-ink-faint)]">{live!.live ? "Score" : "FT"} </span>
              <span className="font-bold">
                {live!.scoreHome}:{live!.scoreAway}
              </span>
            </div>
          )}

          <dl className="mt-2 rounded-[var(--radius-ctl)] border border-[var(--color-line)] bg-[var(--color-surface-2)] px-2.5 py-2 space-y-1">
            <Row label="Pick">
              <span className="font-semibold">{leg.pick}</span>
              <span className="text-[var(--color-ink-faint)]"> @ </span>
              <span className="num font-bold">{leg.odds.toFixed(2)}</span>
            </Row>
            {leg.market && <Row label="Market">{leg.market}</Row>}
            <Row label="Result">
              <span className={cn("font-bold uppercase text-[10.5px]", tone)}>{result}</span>
            </Row>
          </dl>
        </div>
      </div>
    </div>
  );
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-3 text-[11.5px]">
      <dt className="text-[var(--color-ink-faint)] shrink-0">{label}</dt>
      <dd className="text-right truncate">{children}</dd>
    </div>
  );
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-[var(--radius-ctl)] border border-[var(--color-line)] bg-[var(--color-surface-2)] px-2.5 py-2">
      <dt className="text-[10px] text-[var(--color-ink-faint)]">{label}</dt>
      <dd className="num text-[13px] font-bold mt-0.5">{value}</dd>
    </div>
  );
}

function Shell({ children }: { children: React.ReactNode }) {
  return (
    <AppShell tabs={false} betSlip={false}>
      <div className="flex items-center gap-2 mb-4">
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
