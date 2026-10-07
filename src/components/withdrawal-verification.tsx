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
 *   1. a number of qualifying deposits (e.g. 3 of GHS 200+ for Ghana)
 *   2. an operator releasing the account for payouts
 *
 * The first is COUNTED, not summed, and the counts come from the server so
 * this panel and the gate that refuses a withdrawal cannot disagree. One
 * large deposit does not stand in for several.
 */

import { ShieldCheck, Lock, Clock } from "lucide-react";
import { formatMoneyWithCurrency } from "@/lib/format-money";

export function WithdrawalVerification({
  currency,
  depositsMade,
  depositsNeeded,
  perDeposit,
  withdrawalApproved,
}: {
  currency: string;
  depositsMade: number;
  depositsNeeded: number;
  perDeposit: number;
  withdrawalApproved: boolean;
}) {
  // Nothing to nag about once both conditions are met.
  if (depositsNeeded <= 0) return null;
  const made = Math.max(0, depositsMade);
  const met = made >= depositsNeeded;
  if (met && withdrawalApproved) return null;

  const remaining = Math.max(0, depositsNeeded - made);

  return (
    <div className="card p-4">
      <div className="flex items-center gap-2.5">
        <span
          className={
            met
              ? "grid place-items-center w-9 h-9 rounded-xl bg-[var(--color-amber)]/12 text-[var(--color-amber)] shrink-0"
              : "grid place-items-center w-9 h-9 rounded-xl bg-[var(--color-brand)]/12 text-[var(--color-brand-ink)] shrink-0"
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
              : `Make ${depositsNeeded} deposits of ${formatMoneyWithCurrency(perDeposit, currency)} or more to unlock withdrawals.`}
          </p>
        </div>
      </div>

      {!met && (
        <>
          {/* One pip per required deposit. A bar would imply part-credit
              for a deposit under the minimum, which earns none. */}
          <div className="mt-3 flex gap-1.5">
            {Array.from({ length: depositsNeeded }).map((_, i) => (
              <div
                key={i}
                className={`h-2 flex-1 rounded-full transition-colors ${
                  i < made ? "grad-brand" : "bg-[var(--color-surface-2)]"
                }`}
              />
            ))}
          </div>
          <div className="flex items-center justify-between mt-2 text-[11.5px]">
            <span className="num text-[var(--color-ink-dim)]">
              {made} of {depositsNeeded} deposits
            </span>
            <span className="num font-semibold text-[var(--color-brand-ink)]">
              {remaining} to go
            </span>
          </div>
        </>
      )}

      {met && (
        <p className="mt-3 flex items-center gap-1.5 text-[11.5px] text-[var(--color-emerald)]">
          <ShieldCheck size={13} />
          {made} of {depositsNeeded} deposits made — requirement met.
        </p>
      )}
    </div>
  );
}
