'use client'

/**
 * Admin queue for agent (sub-admin) commission payouts.
 *
 * Approving deducts the agent's commission balance and records how it was
 * actually paid; rejecting refunds only when the balance had already been
 * taken. Both are refused server-side once a request is no longer pending, so
 * a stale tab cannot pay the same request twice.
 */

import { useCallback, useEffect, useState } from 'react'
import { Loader2, Check, X, Clock, Banknote } from 'lucide-react'

interface Withdrawal {
  id: string
  subAdminId: string
  subAdminName?: string
  subAdminEmail?: string
  amount: number
  currency: string
  status: 'pending' | 'completed' | 'rejected'
  payoutMethod?: string
  payoutDestination?: string
  paymentMethod?: string
  paymentReference?: string
  paymentNote?: string
  createdAt: string
  processedAt?: string
}

const STATUS = {
  pending: { label: 'Pending', cls: 'text-amber-400 border-amber-400/40 bg-amber-400/10', Icon: Clock },
  completed: { label: 'Paid', cls: 'text-emerald-400 border-emerald-400/40 bg-emerald-400/10', Icon: Check },
  rejected: { label: 'Rejected', cls: 'text-rose-400 border-rose-400/40 bg-rose-400/10', Icon: X },
} as const

const TABS = ['pending', 'completed', 'rejected', 'all'] as const

const money = (n: number, c: string) =>
  `${c} ${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

export default function SaWithdrawalsPage() {
  const [tab, setTab] = useState<(typeof TABS)[number]>('pending')
  const [rows, setRows] = useState<Withdrawal[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [openId, setOpenId] = useState<string | null>(null)
  const [busyId, setBusyId] = useState<string | null>(null)
  const [ref, setRef] = useState('')
  const [payMethod, setPayMethod] = useState('')
  const [note, setNote] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const qs = tab === 'all' ? '' : `?status=${tab}`
      const res = await fetch(`/api/admin/sa-withdrawals${qs}`, { cache: 'no-store' })
      if (!res.ok) throw new Error(`HTTP ${res.status}`)
      const d = (await res.json()) as { withdrawals: Withdrawal[] }
      setRows(d.withdrawals ?? [])
      setError(null)
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e))
    } finally {
      setLoading(false)
    }
  }, [tab])

  useEffect(() => {
    void load()
  }, [load])

  const settle = async (id: string, action: 'approve' | 'reject') => {
    setBusyId(id)
    setError(null)
    try {
      const res = await fetch(`/api/admin/sa-withdrawals/${id}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action,
          paymentMethod: payMethod.trim() || undefined,
          paymentReference: ref.trim() || undefined,
          paymentNote: note.trim() || undefined,
        }),
      })
      const d = await res.json().catch(() => ({}))
      if (!res.ok) {
        setError(d.error ?? `Could not ${action} that request.`)
        return
      }
      setOpenId(null)
      setRef('')
      setPayMethod('')
      setNote('')
      await load()
    } catch {
      setError('Network error — please try again.')
    } finally {
      setBusyId(null)
    }
  }

  return (
    <div className="space-y-4">
      <header className="flex items-center gap-2">
        <Banknote className="w-5 h-5 text-primary" />
        <h1 className="text-xl font-bold">Agent payouts</h1>
      </header>

      <div className="flex gap-2">
        {TABS.map((t) => (
          <button
            key={t}
            onClick={() => setTab(t)}
            className={`rounded-lg px-3 py-1.5 text-sm font-semibold capitalize ${
              tab === t ? 'bg-primary text-primary-foreground' : 'bg-secondary text-muted-foreground'
            }`}
          >
            {t}
          </button>
        ))}
      </div>

      {error && <p className="text-sm text-destructive">{error}</p>}

      {loading ? (
        <p className="text-sm text-muted-foreground py-8 text-center">Loading…</p>
      ) : rows.length === 0 ? (
        <p className="text-sm text-muted-foreground py-8 text-center">
          No {tab === 'all' ? '' : tab} payout requests.
        </p>
      ) : (
        <ul className="space-y-2">
          {rows.map((w) => {
            const s = STATUS[w.status]
            const open = openId === w.id
            return (
              <li key={w.id} className="bg-card border border-border rounded-xl overflow-hidden">
                <div className="p-4 flex items-start gap-3">
                  <span className={`shrink-0 flex items-center gap-1 rounded border px-1.5 py-0.5 text-[10px] font-bold ${s.cls}`}>
                    <s.Icon className="w-3 h-3" /> {s.label}
                  </span>
                  <div className="min-w-0 flex-1">
                    <div className="font-semibold">
                      {money(w.amount, w.currency)}
                      <span className="ml-2 text-sm font-normal text-muted-foreground">
                        {w.subAdminName ?? w.subAdminId}
                      </span>
                    </div>
                    <div className="text-xs text-muted-foreground truncate">
                      {w.payoutMethod} · {w.payoutDestination}
                      {w.subAdminEmail && <> · {w.subAdminEmail}</>}
                    </div>
                    <div className="text-xs text-muted-foreground mt-0.5">
                      Requested {new Date(w.createdAt).toLocaleString()}
                      {w.processedAt && <> · settled {new Date(w.processedAt).toLocaleString()}</>}
                    </div>
                    {w.paymentReference && (
                      <div className="text-xs text-muted-foreground mt-0.5">
                        Paid via {w.paymentMethod ?? '—'} · ref {w.paymentReference}
                      </div>
                    )}
                    {w.status === 'rejected' && w.paymentNote && (
                      <div className="text-xs text-rose-400 mt-0.5">{w.paymentNote}</div>
                    )}
                  </div>

                  {w.status === 'pending' && (
                    <button
                      onClick={() => setOpenId(open ? null : w.id)}
                      className="shrink-0 rounded-lg bg-secondary px-3 py-1.5 text-xs font-semibold"
                    >
                      {open ? 'Cancel' : 'Settle'}
                    </button>
                  )}
                </div>

                {open && (
                  <div className="border-t border-border p-4 space-y-2 bg-secondary/40">
                    <div className="grid grid-cols-2 gap-2">
                      <input
                        value={payMethod}
                        onChange={(e) => setPayMethod(e.target.value)}
                        placeholder="Paid via (e.g. MTN MoMo)"
                        className="bg-background border border-border rounded-lg px-3 py-2 text-sm"
                      />
                      <input
                        value={ref}
                        onChange={(e) => setRef(e.target.value)}
                        placeholder="Payment reference"
                        className="bg-background border border-border rounded-lg px-3 py-2 text-sm"
                      />
                    </div>
                    <input
                      value={note}
                      onChange={(e) => setNote(e.target.value)}
                      placeholder="Note (shown to the agent if rejected)"
                      className="w-full bg-background border border-border rounded-lg px-3 py-2 text-sm"
                    />
                    <div className="flex gap-2">
                      <button
                        onClick={() => settle(w.id, 'approve')}
                        disabled={busyId === w.id}
                        className="flex-1 flex items-center justify-center gap-2 rounded-lg bg-primary text-primary-foreground font-semibold text-sm py-2.5 disabled:opacity-50"
                      >
                        {busyId === w.id && <Loader2 className="w-4 h-4 animate-spin" />}
                        Approve &amp; deduct
                      </button>
                      <button
                        onClick={() => settle(w.id, 'reject')}
                        disabled={busyId === w.id}
                        className="flex-1 rounded-lg border border-destructive text-destructive font-semibold text-sm py-2.5 disabled:opacity-50"
                      >
                        Reject
                      </button>
                    </div>
                    <p className="text-xs text-muted-foreground">
                      Approving deducts {money(w.amount, w.currency)} from this agent&apos;s
                      commission balance. Lifetime earnings are not changed.
                    </p>
                  </div>
                )}
              </li>
            )
          })}
        </ul>
      )}
    </div>
  )
}
