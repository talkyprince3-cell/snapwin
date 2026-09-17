"use client";

/**
 * Where the player stands on unlocking withdrawals.
 *
 * The gate already existed server-side, but nothing showed it, so the first a
 * player heard of it was a refused withdrawal. This states the requirement up
 * front and tracks progress against it.
 *
 * Two separate conditions, shown separately because they are cleared in
 * different ways:
 *   1. a cumulative deposit total (country-specific, e.g. GHS 848 for Ghana)
 *   2. an operator releasing the account for payouts
 */

import { ShieldCheck, Lock, Clock } from "lucide-react";
import { formatMoneyWithCurrency } from "@/lib/format-money";

export function WithdrawalVerification({
  currency,
  totalDeposited,
  qualifyTotal,
  withdrawalApproved,
}: {
  currency: string;
  totalDeposited: number;
  qualifyTotal: number;
  withdrawalApproved: boolean;
}) {
  // Nothing to nag about once both conditions are met.
  if (qualifyTotal <= 0) return null;
  const deposited = Math.max(0, totalDeposited);
  const met = deposited >= qualifyTotal;
  if (met && withdrawalApproved) return null;

  const remaining = +(qualifyTotal - deposited).toFixed(2);
  const pct = Math.min(100, Math.round((deposited / qualifyTotal) * 100));

  return (
    <div className="card p-4">
      <div className="flex items-center gap-2.5">
        <span
          className={
            met
              ? "grid place-items-center w-9 h-9 rounded-xl bg-[var(--color-amber)]/12 text-[var(--color-amber)] shrink-0"
              : "grid place-items-center w-9 h-9 rounded-xl bg-[var(--color-brand)]/12 text-[var(--color-brand)] shrink-0"
          }
        >
          {met ? <Clock size={17} /> : <Lock size={17} />}
        </span>
        <div className="min-w-0">
          <h3 className="font-display font-bold text-[14px]">
            {met ? "Awaiting withdrawal release" : "Unlock withdrawals"}
          </h3>
          <p className="text-[12px] text-[var(--color-ink-dim)] mt-0.5">
            {met
              ? "You've met the deposit requirement. An operator reviews and releases your account for payouts."
              : `Deposit a total of ${formatMoneyWithCurrency(qualifyTotal, currency)} to unlock withdrawals.`}
          </p>
        </div>
      </div>

      {!met && (
        <>
          <div className="mt-3 h-2 rounded-full bg-[var(--color-surface-2)] overflow-hidden">
            <div
              className="h-full grad-brand rounded-full transition-[width] duration-500"
              style={{ width: `${pct}%` }}
            />
          </div>
          <div className="flex items-center justify-between mt-2 text-[11.5px]">
            <span className="num text-[var(--color-ink-dim)]">
              {formatMoneyWithCurrency(deposited, currency)} deposited
            </span>
            <span className="num font-semibold text-[var(--color-brand)]">
              {formatMoneyWithCurrency(remaining, currency)} to go
            </span>
          </div>
        </>
      )}

      {met && (
        <p className="mt-3 flex items-center gap-1.5 text-[11.5px] text-[var(--color-emerald)]">
          <ShieldCheck size={13} />
          {formatMoneyWithCurrency(deposited, currency)} deposited — requirement met.
        </p>
      )}
    </div>
  );
}
