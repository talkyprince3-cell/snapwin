"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { X, Trash2, Ticket, Zap, ShieldCheck, ChevronUp, ChevronRight, Loader2, LogIn, BookmarkPlus, Copy, Check, Share2 } from "lucide-react";
import { useSlip, totalOdds } from "@/lib/store";
import type { Selection } from "@/lib/types";
import { getUserId } from "@/lib/user-session";
import { getCountryForCurrency, isCurrencyCode } from "@/lib/countries";
import { formatMoneyWithCurrency } from "@/lib/format-money";
import { cn } from "@/lib/utils";

const QUICK = [20, 50, 100, 500];

function SlipBody({ onPlaced }: { onPlaced?: () => void }) {
  const { selections, stake, remove, clear, setStake, add, setMobileOpen } = useSlip();
  const router = useRouter();
  const [placed, setPlaced] = useState(false);
  const [code, setCode] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [needsLogin, setNeedsLogin] = useState(false);
  // Booking: save the slip and hand back a shareable code (no stake, no login).
  const [booking, setBooking] = useState(false);
  const [bookedCode, setBookedCode] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);
  const [sharing, setSharing] = useState(false);
  // Loading a booking code back into the slip.
  const [loadCode, setLoadCode] = useState("");
  const [loadingCode, setLoadingCode] = useState(false);
  const [loadError, setLoadError] = useState<string | null>(null);
  // Wallet currency, so the slip shows the user's currency (₦ for NG, GHS for GH…).
  const [currency, setCurrency] = useState("GHS");
  useEffect(() => {
    const id = getUserId();
    if (!id) return;
    fetch(`/api/users/${id}`)
      .then((r) => r.json())
      .then((d) => { if (d?.currency) setCurrency(d.currency); })
      .catch(() => {});
  }, []);
  const money = (n: number) => formatMoneyWithCurrency(n, currency);
  const symbol = getCountryForCurrency(isCurrencyCode(currency) ? currency : "GHS").currencySymbol;
  const odds = totalOdds(selections);
  const potential = stake * odds;
  const bonusPct = selections.length >= 5 ? 0.15 : selections.length >= 3 ? 0.08 : 0;
  const bonus = potential * bonusPct;

  function goLogin() {
    onPlaced?.();
    router.push("/login");
  }

  async function placeBet() {
    if (stake <= 0 || busy) return;
    setError(null);

    // Require an account before a bet can be staked.
    const userId = getUserId();
    if (!userId) {
      setNeedsLogin(true);
      return;
    }

    setBusy(true);
    try {
      // Map the UI slip selections into the BetSelection shape the API persists.
      const payload = selections.map((s) => {
        const [home, away] = s.match.split(" v ");
        return {
          id: s.id,
          matchId: s.matchId,
          match: {
            id: s.matchId,
            league: s.league ?? "",
            country: s.country ?? "",
            homeTeam: (home ?? s.match).trim(),
            awayTeam: (away ?? "").trim(),
            isLive: false,
            odds: { home: 0, draw: 0, away: 0 },
          },
          marketKey: s.market,
          marketLabel: s.market,
          outcomeKey: s.pick,
          outcomeLabel: s.pick,
          odds: s.odds,
        };
      });

      const res = await fetch("/api/bets", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ selections: payload, stake, userId }),
      });
      const data = await res.json().catch(() => ({}));

      if (res.status === 401) {
        setNeedsLogin(true);
        return;
      }
      if (!res.ok) {
        setError(data.error ?? "Could not place this bet. Please try again.");
        return;
      }
      setCode(data.bet?.code ?? null);
      setPlaced(true);
    } catch {
      setError("Network error — please try again.");
    } finally {
      setBusy(false);
    }
  }

  // Book the slip → get a shareable code. No money, no account needed.
  async function book() {
    if (selections.length === 0 || booking) return;
    setError(null);
    setBooking(true);
    try {
      const res = await fetch("/api/bookings", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ selections }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        setError(data.error ?? "Could not book this slip. Please try again.");
        return;
      }
      setBookedCode(data.booking?.code ?? null);
    } catch {
      setError("Network error — please try again.");
    } finally {
      setBooking(false);
    }
  }

  // Copy / share for the PLACED ticket code. Kept separate from the booking
  // code helpers below: a booking is a saved slip, this is a real ticket.
  function copyTicket(value: string) {
    navigator.clipboard?.writeText(value).then(() => {
      setCopied(true);
      setTimeout(() => setCopied(false), 1800);
    }).catch(() => {});
  }

  function shareTicket(value: string) {
    void navigator.share?.({
      title: "SnapWin ticket",
      text: `My SnapWin ticket: ${value}`,
    }).catch(() => {});
  }

  function copyCode() {
    if (!bookedCode) return;
    navigator.clipboard?.writeText(bookedCode).then(() => {
      setCopied(true);
      setTimeout(() => setCopied(false), 1800);
    }).catch(() => {});
  }

  // Share the booking as an image (WhatsApp / status). Falls back to
  // downloading the PNG when the device can't share files.
  async function shareImage() {
    if (!bookedCode || sharing) return;
    setSharing(true);
    try {
      const res = await fetch(`/api/bookings/${bookedCode}/image`);
      if (!res.ok) throw new Error("image");
      const blob = await res.blob();
      const file = new File([blob], `snapwin-${bookedCode}.png`, { type: "image/png" });
      const text = `SnapWin booking code: ${bookedCode} — load it on SnapWin to play.`;
      const nav = navigator as Navigator & { canShare?: (d: unknown) => boolean };
      if (nav.share && nav.canShare?.({ files: [file] })) {
        await nav.share({ files: [file], title: "SnapWin booking", text });
      } else {
        const url = URL.createObjectURL(blob);
        const a = document.createElement("a");
        a.href = url;
        a.download = `snapwin-${bookedCode}.png`;
        a.click();
        URL.revokeObjectURL(url);
      }
    } catch {
      // user cancelled the share sheet, or the fetch failed — nothing to do
    } finally {
      setSharing(false);
    }
  }

  // Load a booking code → drop its selections back into the slip.
  async function loadBookingCode() {
    const clean = loadCode.trim();
    if (clean.length < 4 || loadingCode) {
      setLoadError("Enter a valid booking code.");
      return;
    }
    setLoadError(null);
    setLoadingCode(true);
    try {
      const res = await fetch(`/api/bookings?code=${encodeURIComponent(clean)}`);
      if (res.status === 404) {
        setLoadError("No booking found with that code.");
        return;
      }
      if (!res.ok) {
        setLoadError("Couldn't load that code — please try again.");
        return;
      }
      const data = await res.json();
      const sels: Selection[] = data.booking?.selections ?? [];
      if (sels.length === 0) {
        setLoadError("That booking has no selections.");
        return;
      }
      if (data.playable === false) {
        setLoadError("This booking's games have already started. Open it on the Booking page to see live scores and results.");
        return;
      }
      clear();
      sels.forEach((s) => add(s));
      setLoadCode("");
    } catch {
      setLoadError("Network error — please try again.");
    } finally {
      setLoadingCode(false);
    }
  }

  if (needsLogin) {
    return (
      <div className="flex flex-col items-center text-center px-5 py-10 animate-rise">
        <div className="grid place-items-center w-16 h-16 rounded-full bg-[var(--color-surface-2)] border border-[var(--color-line)] mb-4">
          <LogIn className="text-[var(--color-brand)]" size={28} />
        </div>
        <h3 className="font-display font-extrabold text-lg">Sign in to place your bet</h3>
        <p className="text-[13px] text-[var(--color-ink-dim)] mt-1.5">
          Your selections are saved. Log in to your account and your slip will still be here.
        </p>
        <button
          onClick={goLogin}
          className="mt-5 w-full rounded-xl py-3 font-display font-bold grad-brand text-[var(--color-on-brand)] text-sm"
        >
          Log in / Sign up
        </button>
        <button
          onClick={() => setNeedsLogin(false)}
          className="mt-2 w-full rounded-xl py-2.5 font-display font-semibold text-[var(--color-ink-dim)] hover:text-white text-[13px]"
        >
          Back to slip
        </button>
      </div>
    );
  }

  if (bookedCode) {
    return (
      <div className="flex flex-col items-center text-center px-5 py-8 overflow-y-auto no-scrollbar animate-rise">
        <div className="grid place-items-center w-14 h-14 rounded-full grad-brand mb-3 shadow-[0_10px_40px_-8px_rgba(249,115,22,.65)]">
          <BookmarkPlus className="text-white" size={26} />
        </div>
        <h3 className="font-display font-extrabold text-lg">Slip Booked!</h3>
        <p className="text-[13px] text-[var(--color-ink-dim)] mt-1">
          Share the image or the code — load it later to place the bet.
        </p>

        {/* shareable image preview */}
        <div className="mt-4 w-full rounded-2xl overflow-hidden border border-[var(--color-line)] bg-[var(--color-surface)]">
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img
            src={`/api/bookings/${bookedCode}/image`}
            alt={`Booking ${bookedCode}`}
            className="w-full h-auto block"
          />
        </div>

        <button
          onClick={shareImage}
          disabled={sharing}
          className="mt-4 w-full flex items-center justify-center gap-2 rounded-xl py-3.5 font-display font-extrabold text-[14px] grad-brand text-[var(--color-on-brand)] shadow-[0_10px_30px_-8px_rgba(249,115,22,.55)] disabled:opacity-60 active:scale-[.99] transition"
        >
          {sharing ? <Loader2 size={16} className="animate-spin" /> : <Share2 size={16} />}
          {sharing ? "Preparing…" : "Share image"}
        </button>

        <button
          onClick={copyCode}
          className="mt-2.5 w-full flex items-center justify-between gap-3 rounded-xl border border-[var(--color-line)] bg-[var(--color-surface)] px-4 py-3 hover:border-[var(--color-brand)]/60 transition group"
        >
          <span className="num text-[20px] font-extrabold tracking-[0.2em] grad-text">{bookedCode}</span>
          <span className="flex items-center gap-1.5 text-[12px] font-semibold text-[var(--color-ink-dim)] group-hover:text-white">
            {copied ? <><Check size={15} className="text-[var(--color-emerald)]" /> Copied</> : <><Copy size={15} /> Copy code</>}
          </span>
        </button>

        <button
          onClick={() => { setBookedCode(null); clear(); onPlaced?.(); }}
          className="mt-4 w-full rounded-xl py-2.5 font-display font-semibold text-[var(--color-ink-dim)] hover:text-white text-[13px]"
        >
          Done
        </button>
      </div>
    );
  }

  if (placed) {
    // The slip is deliberately not cleared on success, only on dismiss: `stake`
    // and `potential` are derived from it, so clearing early would collapse the
    // receipt's figures to zero while the user is still reading them.
    const done = () => { setPlaced(false); setCode(null); clear(); onPlaced?.(); };
    return (
      <div className="px-4 py-6 animate-rise">
        <div className="flex items-center justify-center gap-2.5">
          <span className="grid place-items-center w-9 h-9 rounded-full grad-emerald shrink-0">
            <ShieldCheck className="text-white" size={19} />
          </span>
          <h3 className="font-display font-extrabold text-[20px]">Bet Successful</h3>
        </div>

        <dl className="mt-5 space-y-0.5">
          <ReceiptRow label="Total Stake" value={money(stake)} />
          <ReceiptRow label="Potential Win" value={money(potential)} strong />
          {bonus > 0 && <ReceiptRow label="Max. Bonus" value={money(bonus)} />}
        </dl>

        {code && (
          <div className="mt-4 flex items-center justify-between gap-3 border-y border-[var(--color-line)] py-3">
            <span className="num text-[16px] font-extrabold tracking-wider truncate">{code}</span>
            <div className="flex items-center gap-3 shrink-0">
              <button onClick={() => shareTicket(code)} aria-label="Share ticket code" className="text-[var(--color-ink-faint)] hover:text-white transition-colors">
                <Share2 size={17} />
              </button>
              <button onClick={() => copyTicket(code)} aria-label="Copy ticket code" className="text-[var(--color-ink-faint)] hover:text-white transition-colors">
                {copied ? <Check size={17} className="text-[var(--color-emerald)]" /> : <Copy size={17} />}
              </button>
            </div>
          </div>
        )}

        <div className="mt-1">
          <ReceiptLink label="View Ticket" href={code ? `/my-bets/${encodeURIComponent(code)}` : "/my-bets"} onGo={done} />
          <ReceiptLink label="Open Bets" href="/my-bets" onGo={done} />
        </div>

        <button
          onClick={done}
          className="mt-5 w-full rounded-[var(--radius-ctl)] py-3 font-display font-bold grad-brand text-[var(--color-on-brand)] text-sm"
        >
          Place Another
        </button>
      </div>
    );
  }

  if (selections.length === 0) {
    return (
      <div className="flex flex-col items-center text-center px-6 py-14">
        <div className="grid place-items-center w-14 h-14 rounded-2xl bg-[var(--color-surface-2)] border border-[var(--color-line)] mb-4">
          <Ticket className="text-[var(--color-ink-faint)]" size={24} />
        </div>
        <h3 className="font-display font-bold text-[15px]">Your bet slip is empty</h3>
        <p className="text-[12.5px] text-[var(--color-ink-faint)] mt-1.5 leading-relaxed">
          Tap any odds to add a selection. Combine picks to build an accumulator.
        </p>

        {/* Load a booking code */}
        <div className="w-full mt-6 pt-5 border-t border-[var(--color-line)]">
          <p className="text-[11px] uppercase tracking-wide text-[var(--color-ink-faint)] mb-2">Have a booking code?</p>
          <div className="flex gap-2">
            <input
              value={loadCode}
              onChange={(e) => { setLoadCode(e.target.value.toUpperCase()); setLoadError(null); }}
              onKeyDown={(e) => e.key === "Enter" && loadBookingCode()}
              placeholder="Enter code"
              className="flex-1 num text-[13px] tracking-widest bg-[var(--color-surface)] border border-[var(--color-line)] rounded-xl px-3 py-2.5 outline-none focus:border-[var(--color-brand)]/60 transition placeholder:tracking-normal placeholder:font-sans placeholder:text-[var(--color-ink-faint)]"
            />
            <button
              onClick={loadBookingCode}
              disabled={loadingCode}
              className="rounded-xl px-4 grad-brand text-[var(--color-on-brand)] font-display font-bold text-[13px] disabled:opacity-50 flex items-center gap-1.5"
            >
              {loadingCode ? <Loader2 size={15} className="animate-spin" /> : "Load"}
            </button>
          </div>
          {loadError && <p className="text-[11.5px] text-[var(--color-rose)] mt-2">{loadError}</p>}
        </div>
      </div>
    );
  }

  return (
    <div className="flex flex-col h-full">
      {/* selections */}
      <div className="flex-1 overflow-y-auto no-scrollbar px-3 py-3 space-y-2">
        {selections.map((s) => (
          <div key={s.id} className="card p-3 animate-rise">
            <div className="flex items-start justify-between gap-2">
              {/* Tapping the selection opens the fixture it came from, so a
                  punter can check the game before staking. The remove button
                  sits outside the link so it stays its own hit target. */}
              <Link
                href={s.matchId ? `/match/${s.matchId}` : "#"}
                onClick={() => setMobileOpen(false)}
                className="min-w-0 group/sel"
              >
                <div className="font-display font-bold text-[13.5px] truncate group-hover/sel:text-[var(--color-brand-hi)] transition-colors">
                  {s.pick}
                </div>
                <div className="text-[11.5px] text-[var(--color-ink-dim)] truncate mt-0.5">{s.match}</div>
                <div className="flex items-center gap-1.5 mt-1">
                  <span className="text-[10px] font-semibold text-[var(--color-ink-faint)] uppercase tracking-wide">
                    {s.market}
                  </span>
                  <ChevronRight size={11} className="text-[var(--color-ink-faint)] group-hover/sel:text-[var(--color-brand-hi)] transition-colors" />
                </div>
              </Link>
              <div className="flex flex-col items-end gap-1.5 shrink-0">
                <span className="num text-[13px] font-bold text-[var(--color-brand)]">{s.odds.toFixed(2)}</span>
                <button
                  onClick={() => remove(s.id)}
                  aria-label={`Remove ${s.pick}`}
                  className="text-[var(--color-ink-faint)] hover:text-[var(--color-rose)] transition-colors"
                >
                  <X size={15} />
                </button>
              </div>
            </div>
          </div>
        ))}
        <button onClick={clear} className="flex items-center gap-1.5 text-[11px] text-[var(--color-ink-faint)] hover:text-[var(--color-rose)] transition-colors mt-1">
          <Trash2 size={12} /> Clear all
        </button>
      </div>

      {/* footer */}
      <div className="border-t border-[var(--color-line)] p-3.5 bg-[var(--color-bg-2)] space-y-3">
        <div className="flex gap-2">
          {QUICK.map((q) => (
            <button
              key={q}
              onClick={() => setStake(q)}
              className={cn(
                "flex-1 num text-[11px] font-bold rounded-lg py-1.5 border transition-colors",
                stake === q
                  ? "grad-brand text-[var(--color-on-brand)] border-transparent"
                  : "bg-[var(--color-surface-2)] border-[var(--color-line)] text-[var(--color-ink-dim)] hover:text-white",
              )}
            >
              {q}
            </button>
          ))}
        </div>

        <div className="relative">
          <span className="absolute left-3 top-1/2 -translate-y-1/2 text-[11px] text-[var(--color-ink-faint)] num">{symbol}</span>
          <input
            type="number"
            value={stake || ""}
            onChange={(e) => setStake(parseFloat(e.target.value) || 0)}
            placeholder="Enter stake"
            className="w-full num text-[15px] font-bold bg-[var(--color-surface)] border border-[var(--color-line)] rounded-xl pl-12 pr-3 py-3 outline-none focus:border-[var(--color-brand)]/60 focus:glow-brand transition"
          />
        </div>

        <div className="space-y-1.5 text-[12px]">
          <Row label={`${selections.length} selection${selections.length > 1 ? "s" : ""}`} value={`@ ${odds.toFixed(2)}`} />
          {bonus > 0 && (
            <Row
              label={<span className="flex items-center gap-1 text-[var(--color-amber)]"><Zap size={11} /> Acca boost +{bonusPct * 100}%</span>}
              value={`+${money(bonus)}`}
              valueClass="text-[var(--color-amber)]"
            />
          )}
          <div className="flex items-center justify-between pt-1.5 border-t border-[var(--color-line)]">
            <span className="text-[12px] text-[var(--color-ink-dim)]">Potential payout</span>
            <span className="num text-[16px] font-extrabold grad-text">{money(potential + bonus)}</span>
          </div>
        </div>

        {error && (
          <p className="text-[12px] font-semibold text-[var(--color-rose,#fb7185)] text-center">{error}</p>
        )}

        <button
          onClick={placeBet}
          disabled={stake <= 0 || busy || booking}
          className="w-full flex items-center justify-center gap-2 rounded-xl py-3.5 font-display font-extrabold text-[14px] grad-brand text-[var(--color-on-brand)] shadow-[0_10px_30px_-8px_rgba(249,115,22,.55)] disabled:opacity-50 disabled:shadow-none active:scale-[.99] transition"
        >
          {busy && <Loader2 size={16} className="animate-spin" />}
          {busy ? "Placing…" : `Place Bet · ${money(stake)}`}
        </button>

        <button
          onClick={book}
          disabled={booking || busy}
          className="w-full flex items-center justify-center gap-2 rounded-xl py-3 font-display font-bold text-[13px] border border-[var(--color-line)] bg-[var(--color-surface)] text-[var(--color-ink-dim)] hover:text-white hover:border-[var(--color-brand)]/60 disabled:opacity-50 active:scale-[.99] transition"
        >
          {booking ? <Loader2 size={15} className="animate-spin" /> : <BookmarkPlus size={15} />}
          {booking ? "Booking…" : "Book a Bet — get a code"}
        </button>
      </div>
    </div>
  );
}

function ReceiptRow({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
  return (
    <div className="flex items-center justify-between gap-3 py-1.5">
      <dt className="text-[13px] text-[var(--color-ink-dim)]">{label}</dt>
      <dd className={cn("num text-[15px]", strong ? "font-extrabold text-[var(--color-brand)]" : "font-bold")}>{value}</dd>
    </div>
  );
}

function ReceiptLink({ label, href, onGo }: { label: string; href: string; onGo: () => void }) {
  return (
    <Link
      href={href}
      onClick={onGo}
      className="flex items-center justify-between gap-3 py-2.5 border-b border-[var(--color-line)] last:border-b-0"
    >
      <span className="text-[13px] text-[var(--color-ink-dim)]">{label}</span>
      <span className="text-[13px] font-bold text-[var(--color-emerald)]">View</span>
    </Link>
  );
}

function Row({ label, value, valueClass }: { label: React.ReactNode; value: React.ReactNode; valueClass?: string }) {
  return (
    <div className="flex items-center justify-between">
      <span className="text-[var(--color-ink-dim)]">{label}</span>
      <span className={cn("num font-semibold", valueClass)}>{value}</span>
    </div>
  );
}

/* Desktop right rail */
export function DesktopBetSlip() {
  const count = useSlip((s) => s.selections.length);
  return (
    <aside className="hidden xl:flex flex-col w-[340px] shrink-0 sticky top-[120px] h-[calc(100dvh-140px)]">
      <div className="card flex flex-col h-full overflow-hidden">
        <div className="flex items-center justify-between px-4 py-3.5 border-b border-[var(--color-line)] bg-[var(--color-bg-2)]">
          <div className="flex items-center gap-2">
            <Ticket size={16} className="text-[var(--color-brand)]" />
            <span className="font-display font-extrabold text-[14px]">Bet Slip</span>
          </div>
          <span className="num text-[11px] font-bold grad-brand text-[var(--color-on-brand)] rounded-full min-w-[22px] h-[22px] grid place-items-center px-1.5">
            {count}
          </span>
        </div>
        <SlipBody />
      </div>
    </aside>
  );
}

/* Mobile bottom drawer */
export function MobileBetSlip() {
  const { selections, mobileOpen, setMobileOpen } = useSlip();
  const odds = totalOdds(selections);
  const count = selections.length;

  return (
    <>
      {/* collapsed bar */}
      {count > 0 && !mobileOpen && (
        <button
          onClick={() => setMobileOpen(true)}
          className="xl:hidden fixed bottom-[68px] left-3 right-3 z-40 flex items-center justify-between rounded-2xl px-4 py-3 grad-brand text-[var(--color-on-brand)] shadow-[0_12px_40px_-10px_rgba(249,115,22,.7)] animate-rise"
        >
          <span className="flex items-center gap-2 font-display font-bold text-[13px]">
            <span className="num bg-white/25 rounded-full min-w-[20px] h-5 grid place-items-center px-1.5 text-[11px]">{count}</span>
            Bet Slip
          </span>
          <span className="flex items-center gap-2 num text-[12px] font-bold">
            @ {odds.toFixed(2)} <ChevronUp size={16} />
          </span>
        </button>
      )}

      {/* drawer */}
      {mobileOpen && (
        <div className="xl:hidden fixed inset-0 z-50 flex flex-col justify-end">
          <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={() => setMobileOpen(false)} />
          <div className="relative card rounded-b-none max-h-[82dvh] flex flex-col animate-rise">
            <div className="flex items-center justify-between px-4 py-3.5 border-b border-[var(--color-line)]">
              <span className="font-display font-extrabold text-[15px]">Bet Slip</span>
              <button onClick={() => setMobileOpen(false)} className="text-[var(--color-ink-dim)] hover:text-white">
                <X size={20} />
              </button>
            </div>
            <SlipBody onPlaced={() => setMobileOpen(false)} />
          </div>
        </div>
      )}
    </>
  );
}
