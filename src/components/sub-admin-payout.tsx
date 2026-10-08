'use client'

/**
 * An agent's commission payout: what they can request, the form to ask, and
 * what they have asked for already.
 *
 * The API behind this (/api/sub-admin/withdrawals) was complete — balances,
 * validation, admin notification — but nothing on the dashboard called it, so
 * agents could watch commission accumulate with no way to ask for it.
 *
 * `requestable` is the server's figure, not balance minus something worked out
 * here: it already has pending requests subtracted, which is what stops an
 * agent queueing several requests that each look affordable on their own.
 */

import { useCallback, useEffect, useState } from 'react'
import { Wallet, Loader2, Check, Clock, X } from 'lucide-react'
import { formatMoney } from '@/lib/format-money'

type Status = 'pending' | 'completed' | 'rejected'

interface Withdrawal {
  id: string
  amount: number
  currency: string
  status: Status
  payoutMethod?: string
  payoutDestination?: string
  paymentNote?: string
  createdAt: string
  processedAt?: string
}

interface Available {
  balance: number
  pending: number
  requestable: number
}

const METHODS = ['MTN MoMo', 'Telecel Cash', 'AT Money', 'Bank transfer']

export function SubAdminPayout({ approved }: { approved: boolean }) {
  const [available, setAvailable] = useState<Record<string, Available>>({})
  const [withdrawals, setWithdrawals] = useState<Withdrawal[]>([])
  const [loading, setLoading] = useState(true)

  const [currency, setCurrency] = useState<string>('')
  const [amount, setAmount] = useState('')
  const [method, setMethod] = useState(METHODS[0])
  const [destination, setDestination] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [done, setDone] = useState(false)

  const load = useCallback(async () => {
    try {
      const res = await fetch('/api/sub-admin/withdrawals', { cache: 'no-store' })
      if (!res.ok) return
      const data = await res.json()
      const avail: Record<string, Available> = data.available ?? {}
      setAvailable(avail)
      setWithdrawals(Array.isArray(data.withdrawals) ? data.withdrawals : [])
      // Default to whichever wallet actually has something in it, so the
      // common case needs no choosing.
      setCurrency((c) => {
        if (c) return c
        const best = Object.entries(avail).sort(
          (a, b) => b[1].requestable - a[1].requestable,
        )[0]
        return best?.[0] ?? ''
      })
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    load()
  }, [load])

  const current = currency ? available[currency] : undefined
  const requestable = current?.requestable ?? 0

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    setError(null)
    const n = Number(amount)
    if (!Number.isFinite(n) || n <= 0) {
      setError('Enter an amount greater than zero.')
      return
    }
    if (!destination.trim()) {
      setError('Enter the number or account to pay.')
      return
    }
    setBusy(true)
    try {
      const res = await fetch('/api/sub-admin/withdrawals', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          amount: n,
          currency,
          payoutMethod: method,
          payoutDestination: destination.trim(),
        }),
      })
      const data = await res.json().catch(() => ({}))
      if (!res.ok) {
        // The server's message names the real ceiling when pending requests
        // are what is in the way, so it is shown rather than replaced.
        setError(data.error ?? 'Could not send the request.')
        return
      }
      setDone(true)
      setAmount('')
      setDestination('')
      await load()
      setTimeout(() => setDone(false), 4000)
    } catch {
      setError('Network error — try again.')
    } finally {
      setBusy(false)
    }
  }

  if (loading) return null

  const currencies = Object.keys(available)
  const nothingYet = currencies.length === 0 || currencies.every((c) => available[c].balance <= 0)

  return (
    <section className="rounded-2xl border border-border bg-card p-4 sm:p-5">
      <div className="flex items-center gap-2 mb-1">
        <Wallet className="w-4 h-4 text-success" />
        <h2 className="font-semibold text-[15px]">Request payout</h2>
      </div>
      <p className="text-[12.5px] text-muted-foreground mb-4">
        Ask for your commission to be paid out. Requests are reviewed and paid by an
        operator.
      </p>

      {!approved ? (
        <p className="rounded-xl bg-muted/50 border border-border p-3 text-[12.5px] text-muted-foreground">
          Your agent account is awaiting approval. You can request a payout once it is
          approved.
        </p>
      ) : nothingYet ? (
        <p className="rounded-xl bg-muted/50 border border-border p-3 text-[12.5px] text-muted-foreground">
          No commission to pay out yet. Earnings appear here as your referred players
          deposit.
        </p>
      ) : (
        <>
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-2.5 mb-4">
            {currencies.map((c) => (
              <button
                key={c}
                type="button"
                onClick={() => setCurrency(c)}
                className={`rounded-xl border p-3 text-left transition ${
                  c === currency
                    ? 'border-primary bg-primary/5'
                    : 'border-border hover:border-primary/40'
                }`}
              >
                <div className="text-[11px] uppercase tracking-wide text-muted-foreground">
                  {c} available
                </div>
                <div className="font-bold text-[15px]">
                  {formatMoney(available[c].requestable, c)}
                </div>
                {available[c].pending > 0 && (
                  <div className="text-[11px] text-amber-600 mt-0.5">
                    {formatMoney(available[c].pending, c)} awaiting approval
                  </div>
                )}
              </button>
            ))}
          </div>

          <form onSubmit={submit} className="space-y-3">
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label className="text-[11px] uppercase tracking-wide text-muted-foreground">
                  Amount ({currency})
                </label>
                <div className="flex gap-2 mt-1">
                  <input
                    inputMode="decimal"
                    value={amount}
                    onChange={(e) => setAmount(e.target.value)}
                    placeholder="0.00"
                    className="flex-1 min-w-0 rounded-xl border border-border bg-background px-3 py-2.5 text-[14px]"
                  />
                  <button
                    type="button"
                    onClick={() => setAmount(String(requestable))}
                    className="shrink-0 rounded-xl border border-border px-3 text-[12px] font-semibold hover:border-primary/40"
                  >
                    All
                  </button>
                </div>
                <p className="text-[11px] text-muted-foreground mt-1">
                  Up to {formatMoney(requestable, currency)}
                </p>
              </div>

              <div>
                <label className="text-[11px] uppercase tracking-wide text-muted-foreground">
                  Pay me by
                </label>
                <select
                  value={method}
                  onChange={(e) => setMethod(e.target.value)}
                  className="w-full mt-1 rounded-xl border border-border bg-background px-3 py-2.5 text-[14px]"
                >
                  {METHODS.map((m) => (
                    <option key={m} value={m}>
                      {m}
                    </option>
                  ))}
                </select>
              </div>
            </div>

            <div>
              <label className="text-[11px] uppercase tracking-wide text-muted-foreground">
                {method === 'Bank transfer' ? 'Account number' : 'Mobile money number'}
              </label>
              <input
                value={destination}
                onChange={(e) => setDestination(e.target.value)}
                placeholder={method === 'Bank transfer' ? 'Account number' : '024 000 0000'}
                className="w-full mt-1 rounded-xl border border-border bg-background px-3 py-2.5 text-[14px]"
              />
            </div>

            {error && (
              <p className="text-[12.5px] text-destructive rounded-xl bg-destructive/10 px-3 py-2">
                {error}
              </p>
            )}
            {done && (
              <p className="text-[12.5px] text-success rounded-xl bg-success/10 px-3 py-2 flex items-center gap-1.5">
                <Check className="w-3.5 h-3.5" />
                Request sent. An operator will review it.
              </p>
            )}

            <button
              type="submit"
              disabled={busy || requestable <= 0}
              className="w-full rounded-xl bg-primary text-primary-foreground py-3 font-semibold text-[14px] disabled:opacity-50 flex items-center justify-center gap-2"
            >
              {busy && <Loader2 className="w-4 h-4 animate-spin" />}
              {busy ? 'Sending…' : 'Request payout'}
            </button>
          </form>
        </>
      )}

      {withdrawals.length > 0 && (
        <div className="mt-5 pt-4 border-t border-border">
          <h3 className="text-[12px] uppercase tracking-wide text-muted-foreground mb-2">
            Your requests
          </h3>
          <ul className="space-y-2">
            {withdrawals.slice(0, 8).map((w) => (
              <li
                key={w.id}
                className="flex items-center justify-between gap-3 rounded-xl border border-border px-3 py-2.5"
              >
                <div className="min-w-0">
                  <div className="font-semibold text-[13.5px]">
                    {formatMoney(w.amount, w.currency)}
                  </div>
                  <div className="text-[11.5px] text-muted-foreground truncate">
                    {new Date(w.createdAt).toLocaleDateString()}
                    {w.payoutMethod ? ` · ${w.payoutMethod}` : ''}
                    {/* The operator's note is the only explanation a rejected
                        agent gets, so it is shown rather than just the badge. */}
                    {w.status === 'rejected' && w.paymentNote ? ` · ${w.paymentNote}` : ''}
                  </div>
                </div>
                <StatusBadge status={w.status} />
              </li>
            ))}
          </ul>
        </div>
      )}
    </section>
  )
}

function StatusBadge({ status }: { status: Status }) {
  const map = {
    pending: { icon: Clock, text: 'Pending', cls: 'bg-amber-500/10 text-amber-600' },
    completed: { icon: Check, text: 'Paid', cls: 'bg-success/10 text-success' },
    rejected: { icon: X, text: 'Rejected', cls: 'bg-destructive/10 text-destructive' },
  }[status]
  const Icon = map.icon
  return (
    <span
      className={`shrink-0 inline-flex items-center gap-1 rounded-lg px-2 py-1 text-[11px] font-semibold ${map.cls}`}
    >
      <Icon className="w-3 h-3" />
      {map.text}
    </span>
  )
}
