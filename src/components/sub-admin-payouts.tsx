'use client'

/**
 * Agent payout panel for the sub-admin dashboard: request a commission payout
 * and see what happened to earlier requests.
 *
 * "Requestable" is the balance minus anything already queued, so the figure on
 * screen is what can actually be asked for — showing the raw balance would
 * invite a request the server then rejects.
 */

import { useCallback, useEffect, useState } from 'react'
import { Loader2, Banknote, Check, X, Clock } from 'lucide-react'
import {
  PAYOUT_DETAILS_EVENT,
  PAYOUT_NETWORKS,
  type PayoutDetails,
} from './sub-admin-payout-details'
import { WithdrawalNotification, type WithdrawalNotice } from './withdrawal-notification'

interface Withdrawal {
  id: string
  amount: number
  currency: string
  status: 'pending' | 'completed' | 'rejected'
  payoutMethod?: string
  payoutDestination?: string
  paymentReference?: string
  paymentNote?: string
  createdAt: string
  processedAt?: string
}

interface Available {
  [currency: string]: { balance: number; pending: number; requestable: number }
}

const STATUS = {
  pending: { label: 'Pending', cls: 'text-amber-400 border-amber-400/40 bg-amber-400/10', Icon: Clock },
  completed: { label: 'Paid', cls: 'text-emerald-400 border-emerald-400/40 bg-emerald-400/10', Icon: Check },
  rejected: { label: 'Rejected', cls: 'text-rose-400 border-rose-400/40 bg-rose-400/10', Icon: X },
} as const

const money = (n: number, c: string) =>
  `${c} ${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

export function SubAdminPayouts() {
  const [rows, setRows] = useState<Withdrawal[]>([])
  const [available, setAvailable] = useState<Available>({})
  const [currency, setCurrency] = useState('')
  const [amount, setAmount] = useState('')
  const [method, setMethod] = useState('MTN MoMo')
  const [destination, setDestination] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [noticeText, setNoticeText] = useState<string | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [loaded, setLoaded] = useState(false)
  // Drives the ported iOS-style confirmation after a request is accepted.
  const [notice, setNotice] = useState<WithdrawalNotice | null>(null)

  /** Adopt the saved payout details, so the form is filled in already. */
  const applyDetails = useCallback((d: PayoutDetails) => {
    if (d.network) setMethod(d.network)
    if (d.number) setDestination(d.number)
  }, [])

  useEffect(() => {
    void fetch('/api/sub-admin/payout-details', { cache: 'no-store' })
      .then((r) => (r.ok ? r.json() : null))
      .then((d) => { if (d?.payout) applyDetails(d.payout as PayoutDetails) })
      .catch(() => {})
    const onSaved = (e: Event) => applyDetails((e as CustomEvent<PayoutDetails>).detail)
    window.addEventListener(PAYOUT_DETAILS_EVENT, onSaved)
    return () => window.removeEventListener(PAYOUT_DETAILS_EVENT, onSaved)
  }, [applyDetails])

  const load = useCallback(async () => {
    // A failed load must not look like an empty balance: those need different
    // actions from the reader, and collapsing them into one message makes a
    // broken panel indistinguishable from a working, empty one.
    try {
      const res = await fetch('/api/sub-admin/withdrawals', { cache: 'no-store' })
      if (!res.ok) {
        const d = await res.json().catch(() => ({}))
        setLoadError(d.error ?? `Could not load payouts (HTTP ${res.status}).`)
        return
      }
      const d = (await res.json()) as { withdrawals: Withdrawal[]; available: Available }
      setRows(d.withdrawals ?? [])
      setAvailable(d.available ?? {})
      // Default to the first currency the agent actually holds a balance in.
      setCurrency((cur) => cur || Object.keys(d.available ?? {})[0] || 'GHS')
      setLoadError(null)
    } catch {
      setLoadError('Could not reach the server. Retry in a moment.')
    } finally {
      setLoaded(true)
    }
  }, [])

  useEffect(() => {
    void load()
  }, [load])

  const pot = available[currency]
  const isBank = /bank/i.test(method)
  const requestable = pot?.requestable ?? 0

  const submit = async () => {
    const value = Number(amount)
    setError(null)
    setNoticeText(null)
    if (!Number.isFinite(value) || value <= 0) return setError('Enter an amount greater than zero.')
    if (!destination.trim()) return setError('Enter the number or account to pay.')

    setBusy(true)
    try {
      const res = await fetch('/api/sub-admin/withdrawals', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          amount: value,
          currency,
          payoutMethod: method,
          payoutDestination: destination.trim(),
        }),
      })
      const d = await res.json().catch(() => ({}))
      if (!res.ok) {
        setError(d.error ?? 'Could not submit that request.')
        return
      }
      setNoticeText(d.message ?? 'Payout request submitted.')
      // Balance after the request: nothing is deducted until an admin
      // approves, so what remains requestable is the honest figure to show.
      setNotice({
        amount: value,
        currentBalance: Math.max(0, requestable - value),
        currency,
        settled: false,
      })
      setAmount('')
      await load()
    } catch {
      setError('Network error — please try again.')
    } finally {
      setBusy(false)
    }
  }

  const currencies = Object.keys(available)

  return (
    <>
    <WithdrawalNotification notice={notice} onDone={() => setNotice(null)} />
    <section className="bg-card border border-border rounded-xl overflow-hidden">
      <header className="px-4 py-3 border-b border-border flex items-center gap-2">
        <Banknote className="w-4 h-4 text-primary" />
        <h2 className="font-semibold">Request a payout</h2>
      </header>

      <div className="p-4 space-y-3">
        {!loaded ? (
          <p className="text-sm text-muted-foreground">Loading your balance…</p>
        ) : loadError ? (
          <div className="space-y-2">
            <p className="text-sm text-destructive">{loadError}</p>
            <button
              onClick={() => void load()}
              className="rounded-lg bg-secondary px-3 py-1.5 text-xs font-semibold"
            >
              Try again
            </button>
          </div>
        ) : currencies.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            Your commission balance is <span className="font-semibold text-foreground">0.00</span>.
            Payout requests unlock as soon as a referred player deposits.
          </p>
        ) : (
          <>
            <div className="grid grid-cols-2 gap-2">
              <label className="text-xs text-muted-foreground">
                Currency
                <select
                  value={currency}
                  onChange={(e) => setCurrency(e.target.value)}
                  className="mt-1 w-full bg-secondary border border-border rounded-lg px-3 py-2 text-sm text-foreground"
                >
                  {currencies.map((c) => (
                    <option key={c} value={c}>{c}</option>
                  ))}
                </select>
              </label>
              <label className="text-xs text-muted-foreground">
                Amount
                <input
                  type="number"
                  min={0}
                  step="0.01"
                  value={amount}
                  onChange={(e) => setAmount(e.target.value)}
                  placeholder={requestable.toFixed(2)}
                  className="mt-1 w-full bg-secondary border border-border rounded-lg px-3 py-2 text-sm text-foreground"
                />
              </label>
            </div>

            <div className="grid grid-cols-2 gap-2">
              {/* A fixed list rather than free text: the server only texts a
                  payout confirmation when the method is mobile money, so it
                  needs to know which of the two this is. */}
              <label className="text-xs text-muted-foreground">
                Pay via
                <select
                  value={method}
                  onChange={(e) => setMethod(e.target.value)}
                  className="mt-1 w-full bg-secondary border border-border rounded-lg px-3 py-2 text-sm text-foreground"
                >
                  {PAYOUT_NETWORKS.map((m) => (
                    <option key={m} value={m}>{m}</option>
                  ))}
                </select>
              </label>
              <label className="text-xs text-muted-foreground">
                {isBank ? 'Account number' : 'Mobile number'}
                <input
                  value={destination}
                  onChange={(e) => setDestination(e.target.value)}
                  placeholder={isBank ? '0123456789' : '024…'}
                  inputMode="numeric"
                  className="mt-1 w-full bg-secondary border border-border rounded-lg px-3 py-2 text-sm text-foreground"
                />
              </label>
            </div>

            {isBank && (
              <p className="text-xs text-muted-foreground">
                Bank payouts are not texted a confirmation — check this page for the result.
              </p>
            )}

            {pot && (
              <p className="text-xs text-muted-foreground">
                Balance {money(pot.balance, currency)}
                {pot.pending > 0 && <> · {money(pot.pending, currency)} awaiting approval</>}
                {' · '}
                <span className="font-semibold text-foreground">
                  you can request {money(requestable, currency)}
                </span>
              </p>
            )}

            {error && <p className="text-xs text-destructive">{error}</p>}
            {noticeText && <p className="text-xs text-emerald-400">{noticeText}</p>}

            <button
              onClick={submit}
              disabled={busy || requestable <= 0}
              className="w-full flex items-center justify-center gap-2 rounded-lg bg-primary text-primary-foreground font-semibold text-sm py-2.5 disabled:opacity-50"
            >
              {busy && <Loader2 className="w-4 h-4 animate-spin" />} Request payout
            </button>
          </>
        )}
      </div>

      {rows.length > 0 && (
        <div className="border-t border-border">
          <div className="px-4 py-2 text-xs uppercase tracking-wide text-muted-foreground">
            Payout history
          </div>
          <ul className="divide-y divide-border">
            {rows.map((w) => {
              const s = STATUS[w.status]
              return (
                <li key={w.id} className="px-4 py-3 flex items-center gap-3">
                  <span className={`shrink-0 flex items-center gap-1 rounded border px-1.5 py-0.5 text-[10px] font-bold ${s.cls}`}>
                    <s.Icon className="w-3 h-3" /> {s.label}
                  </span>
                  <div className="min-w-0 flex-1">
                    <div className="text-sm font-semibold">{money(w.amount, w.currency)}</div>
                    <div className="text-xs text-muted-foreground truncate">
                      {w.payoutMethod} · {w.payoutDestination}
                      {w.paymentReference && <> · ref {w.paymentReference}</>}
                    </div>
                    {w.status === 'rejected' && w.paymentNote && (
                      <div className="text-xs text-rose-400 mt-0.5">{w.paymentNote}</div>
                    )}
                  </div>
                  <time className="shrink-0 text-xs text-muted-foreground">
                    {new Date(w.processedAt ?? w.createdAt).toLocaleDateString()}
                  </time>
                </li>
              )
            })}
          </ul>
        </div>
      )}
    </section>
    </>
  )
}
