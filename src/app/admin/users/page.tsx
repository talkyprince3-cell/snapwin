'use client'

import { useEffect, useMemo, useState } from 'react'
import {
  Search,
  Loader2,
  ShieldCheck,
  ShieldOff,
  CircleAlert,
  Wallet,
  X,
  Check,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { formatMoney } from '@/lib/format-money'

interface AdminUserRow {
  id: string
  name: string
  email: string
  phone: string | null
  country?: string
  currency?: string
  verificationStep: 0 | 1 | 2 | 3 | 4
  withdrawalApproved: boolean
  balance: number
  totalDeposited: number
  totalWithdrawn: number
  firstDepositAt: string | null
  createdAt: string
}

type Filter = 'all' | 'depositors' | 'awaiting' | 'approved' | 'unverified'

export default function AdminPlayersPage() {
  const [users, setUsers] = useState<AdminUserRow[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [filter, setFilter] = useState<Filter>('all')
  const [search, setSearch] = useState('')
  const [busy, setBusy] = useState<Set<string>>(new Set())
  const [creditingId, setCreditingId] = useState<string | null>(null)
  const [creditAmount, setCreditAmount] = useState('')
  const [creditNote, setCreditNote] = useState('')
  const [creditSubmitting, setCreditSubmitting] = useState(false)

  const load = async () => {
    setError(null)
    try {
      const res = await fetch('/api/admin/users', { cache: 'no-store' })
      if (!res.ok) throw new Error(`HTTP ${res.status}`)
      const data = (await res.json()) as { users: AdminUserRow[] }
      setUsers(data.users)
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e))
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void load()
  }, [])

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase()
    return users.filter((u) => {
      if (filter === 'depositors') {
        if (!u.firstDepositAt) return false
      } else if (filter === 'awaiting') {
        if (!(u.verificationStep === 4 && !u.withdrawalApproved)) return false
      } else if (filter === 'approved') {
        if (!u.withdrawalApproved) return false
      } else if (filter === 'unverified') {
        if (u.verificationStep === 4) return false
      }
      if (q) {
        if (
          !u.name.toLowerCase().includes(q) &&
          !u.email.toLowerCase().includes(q) &&
          !(u.phone?.toLowerCase().includes(q) ?? false) &&
          !u.id.toLowerCase().includes(q)
        )
          return false
      }
      return true
    })
  }, [users, filter, search])

  const counts = useMemo(
    () => ({
      all: users.length,
      depositors: users.filter((u) => !!u.firstDepositAt).length,
      awaiting: users.filter(
        (u) => u.verificationStep === 4 && !u.withdrawalApproved,
      ).length,
      approved: users.filter((u) => u.withdrawalApproved).length,
      unverified: users.filter((u) => u.verificationStep < 4).length,
    }),
    [users],
  )

  const openCredit = (userId: string) => {
    setCreditingId(userId)
    setCreditAmount('')
    setCreditNote('')
    setError(null)
  }

  const cancelCredit = () => {
    setCreditingId(null)
    setCreditAmount('')
    setCreditNote('')
  }

  const submitCredit = async (userId: string) => {
    const amount = Number(creditAmount)
    if (!Number.isFinite(amount) || amount <= 0) {
      setError('Enter a positive amount in the user’s currency.')
      return
    }
    setCreditSubmitting(true)
    setError(null)
    try {
      const res = await fetch(`/api/admin/users/${userId}/credit`, {
        method: 'POST',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ amount, note: creditNote }),
      })
      const data = await res.json().catch(() => ({}))
      if (!res.ok) throw new Error(data.error ?? `HTTP ${res.status}`)
      const newBalance = Number(data.user?.balance ?? 0)
      setUsers((prev) =>
        prev.map((u) => (u.id === userId ? { ...u, balance: newBalance } : u)),
      )
      cancelCredit()
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e))
    } finally {
      setCreditSubmitting(false)
    }
  }

  const toggleApproval = async (userId: string, next: boolean) => {
    setBusy((p) => new Set(p).add(userId))
    try {
      const res = await fetch(`/api/admin/users/${userId}`, {
        method: 'PATCH',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ withdrawalApproved: next }),
      })
      const data = await res.json().catch(() => ({}))
      if (!res.ok) throw new Error(data.error ?? `HTTP ${res.status}`)
      setUsers((prev) =>
        prev.map((u) =>
          u.id === userId ? { ...u, withdrawalApproved: next } : u,
        ),
      )
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e))
    } finally {
      setBusy((p) => {
        const n = new Set(p)
        n.delete(userId)
        return n
      })
    }
  }

  return (
    <div className="p-4 sm:p-6 space-y-5 max-w-5xl">
      <div>
        <h1 className="text-title font-bold tracking-tight">Players</h1>
        <p className="text-sm text-muted-foreground">
          Every registered user. Use <strong>Credit</strong> to top up a
          balance manually (e.g. when Moolre failed but the user paid).
          Withdrawal approval requires verification (4 qualifying deposits)
          and a manual <strong>Approve</strong>.
        </p>
      </div>

      {error && (
        <div className="p-3 rounded-xl bg-destructive/10 border border-destructive/30 text-xs text-destructive flex items-center gap-2 shadow-card">
          <CircleAlert className="w-4 h-4" /> {error}
        </div>
      )}

      <div className="flex flex-wrap gap-2 items-center">
        <FilterPill
          active={filter === 'all'}
          onClick={() => setFilter('all')}
          label={`All (${counts.all})`}
        />
        <FilterPill
          active={filter === 'depositors'}
          onClick={() => setFilter('depositors')}
          label={`Depositors (${counts.depositors})`}
        />
        <FilterPill
          active={filter === 'awaiting'}
          onClick={() => setFilter('awaiting')}
          label={`Awaiting approval (${counts.awaiting})`}
        />
        <FilterPill
          active={filter === 'approved'}
          onClick={() => setFilter('approved')}
          label={`Approved (${counts.approved})`}
        />
        <FilterPill
          active={filter === 'unverified'}
          onClick={() => setFilter('unverified')}
          label={`Unverified (${counts.unverified})`}
        />

        <div className="ml-auto relative w-full sm:w-72">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
          <Input
            type="text"
            placeholder="Search name, email, phone, ID"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="pl-9 h-9 text-sm"
          />
        </div>
      </div>

      <section className="bg-card border border-border rounded-xl overflow-hidden shadow-card">
        {loading ? (
          <div className="p-3 space-y-2">
            {Array.from({ length: 6 }).map((_, i) => (
              <Skeleton key={i} className="h-20 rounded-lg" />
            ))}
          </div>
        ) : filtered.length === 0 ? (
          <div className="m-3 bg-card border border-dashed border-border rounded-lg p-8 text-center text-sm text-muted-foreground">
            No players match this filter.
          </div>
        ) : (
          <ul className="divide-y divide-border">
            {filtered.map((u) => (
              <li key={u.id} className="px-4 py-3 hover:bg-secondary/30 transition-colors">
                <div className="flex items-center justify-between gap-3">
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2 mb-0.5">
                      <span className="font-semibold text-sm truncate">
                        {u.name}
                      </span>
                      <StepBadge step={u.verificationStep} />
                      {u.withdrawalApproved && (
                        <span className="text-[10px] font-bold uppercase px-1.5 py-0.5 rounded border border-success/30 text-success bg-success/10">
                          Approved
                        </span>
                      )}
                    </div>
                    <p className="text-xs text-muted-foreground truncate">
                      {u.email}
                      {u.phone && (
                        <span className="ml-2 text-muted-foreground">· {u.phone}</span>
                      )}
                    </p>
                    <p className="text-[11px] text-muted-foreground flex flex-wrap items-center gap-x-3 gap-y-0.5 mt-1 tabular-nums">
                      <span>Balance: {u.currency ?? 'GHS'} {formatMoney(u.balance, u.currency)}</span>
                      <span className="text-border">·</span>
                      <span>Deposited: {u.currency ?? 'GHS'} {formatMoney(u.totalDeposited, u.currency)}</span>
                      <span className="text-border">·</span>
                      <span>Withdrawn: {u.currency ?? 'GHS'} {formatMoney(u.totalWithdrawn, u.currency)}</span>
                      <span className="text-border">·</span>
                      <span>Joined {formatJoined(u.createdAt)}</span>
                      {u.firstDepositAt ? (
                        <>
                          <span className="text-border">·</span>
                          <span>1st deposit {formatJoined(u.firstDepositAt)}</span>
                        </>
                      ) : (
                        <>
                          <span className="text-border">·</span>
                          <span className="text-amber-600">Never deposited</span>
                        </>
                      )}
                    </p>
                  </div>
                  <div className="shrink-0 flex items-center gap-2">
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => openCredit(u.id)}
                      disabled={creditingId === u.id}
                      className="h-8 text-xs border-primary/30 text-primary hover:bg-primary/10"
                      title="Credit this user's account"
                    >
                      <Wallet className="w-3 h-3 mr-1" />
                      Credit
                    </Button>
                    {u.verificationStep < 4 ? (
                      <span
                        className="text-[11px] text-muted-foreground"
                        title="Player hasn't completed all 4 qualifying verification deposits yet."
                      >
                        Awaiting verification
                      </span>
                    ) : u.withdrawalApproved ? (
                      <Button
                        size="sm"
                        variant="outline"
                        onClick={() => toggleApproval(u.id, false)}
                        disabled={busy.has(u.id)}
                        className="h-8 text-xs border-destructive/40 text-destructive hover:bg-destructive/10"
                      >
                        {busy.has(u.id) ? (
                          <Loader2 className="w-3 h-3 animate-spin" />
                        ) : (
                          <>
                            <ShieldOff className="w-3 h-3 mr-1" />
                            Revoke
                          </>
                        )}
                      </Button>
                    ) : (
                      <Button
                        size="sm"
                        onClick={() => toggleApproval(u.id, true)}
                        disabled={busy.has(u.id)}
                        className="h-8 text-xs bg-primary text-primary-foreground hover:bg-primary/90"
                      >
                        {busy.has(u.id) ? (
                          <Loader2 className="w-3 h-3 animate-spin" />
                        ) : (
                          <>
                            <ShieldCheck className="w-3 h-3 mr-1" />
                            Approve
                          </>
                        )}
                      </Button>
                    )}
                  </div>
                </div>

                {creditingId === u.id && (
                  <div className="mt-3 p-3 rounded-lg border border-primary/30 bg-primary/5 flex flex-col sm:flex-row sm:items-end gap-2">
                    <div className="flex-1 min-w-0">
                      <label className="text-[10px] uppercase tracking-wider text-muted-foreground font-semibold block mb-1">
                        Amount ({u.currency ?? 'GHS'})
                      </label>
                      <Input
                        type="number"
                        inputMode="decimal"
                        min={1}
                        step="0.01"
                        autoFocus
                        value={creditAmount}
                        onChange={(e) => setCreditAmount(e.target.value)}
                        placeholder="e.g. 50"
                        className="h-9 text-sm"
                        disabled={creditSubmitting}
                        onKeyDown={(e) => {
                          if (e.key === 'Enter') void submitCredit(u.id)
                          if (e.key === 'Escape') cancelCredit()
                        }}
                      />
                    </div>
                    <div className="flex-[2] min-w-0">
                      <label className="text-[10px] uppercase tracking-wider text-muted-foreground font-semibold block mb-1">
                        Note (optional)
                      </label>
                      <Input
                        type="text"
                        maxLength={200}
                        value={creditNote}
                        onChange={(e) => setCreditNote(e.target.value)}
                        placeholder="e.g. Bonus for promo X"
                        className="h-9 text-sm"
                        disabled={creditSubmitting}
                        onKeyDown={(e) => {
                          if (e.key === 'Enter') void submitCredit(u.id)
                          if (e.key === 'Escape') cancelCredit()
                        }}
                      />
                    </div>
                    <div className="flex items-center gap-2">
                      <Button
                        size="sm"
                        onClick={() => submitCredit(u.id)}
                        disabled={creditSubmitting || !creditAmount}
                        className="h-9 text-xs bg-primary text-primary-foreground hover:bg-primary/90"
                      >
                        {creditSubmitting ? (
                          <Loader2 className="w-3 h-3 animate-spin" />
                        ) : (
                          <>
                            <Check className="w-3 h-3 mr-1" />
                            Credit
                          </>
                        )}
                      </Button>
                      <Button
                        size="sm"
                        variant="outline"
                        onClick={cancelCredit}
                        disabled={creditSubmitting}
                        className="h-9 text-xs"
                      >
                        <X className="w-3 h-3" />
                      </Button>
                    </div>
                  </div>
                )}
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  )
}

function FilterPill({
  active,
  onClick,
  label,
}: {
  active: boolean
  onClick: () => void
  label: string
}) {
  return (
    <button
      onClick={onClick}
      className={`px-3 py-1.5 rounded-full text-xs font-medium transition-colors ${
        active
          ? 'bg-primary text-primary-foreground'
          : 'bg-secondary text-foreground hover:bg-secondary/80'
      }`}
    >
      {label}
    </button>
  )
}

function formatJoined(iso: string): string {
  try {
    return new Date(iso).toLocaleDateString(undefined, {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    })
  } catch {
    return iso
  }
}

function StepBadge({ step }: { step: 0 | 1 | 2 | 3 | 4 }) {
  const label = `${step}/4`
  const cls =
    step === 4
      ? 'border-success/30 text-success bg-success/10'
      : step >= 1
        ? 'border-amber-500/40 text-amber-500 bg-amber-500/10'
        : 'border-border text-muted-foreground'
  return (
    <span
      className={`text-[10px] font-bold uppercase px-1.5 py-0.5 rounded border ${cls}`}
      title="Verification deposits paid"
    >
      {label}
    </span>
  )
}
