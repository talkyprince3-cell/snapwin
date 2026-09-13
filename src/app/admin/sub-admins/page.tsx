'use client'

import { useEffect, useState } from 'react'
import {
  Loader2,
  ToggleLeft,
  ToggleRight,
  Trash2,
  Search,
  Check,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { formatMoney } from '@/lib/format-money'
import { COMMISSION_RATE } from '@/lib/domain-types'
import { AdminCommissionConfig } from '@/components/admin-commission-config'

interface SubAdminRow {
  id: string
  name: string
  email: string
  referralCode: string
  approved: boolean
  createdAt: string
  commissionBalance: number
  totalCommissionEarned: number
  commissionBalances: Record<string, number>
  totalCommissionEarnedBy: Record<string, number>
  referrals: number
  withDeposit: number
  commissionsCount: number
}

interface PlatformTotals {
  referredDepositsByCurrency: Record<string, number>
  subAdminShareByCurrency: Record<string, number>
  adminShareByCurrency: Record<string, number>
}

export default function AdminSubAdminsPage() {
  const [rows, setRows] = useState<SubAdminRow[]>([])
  const [platform, setPlatform] = useState<PlatformTotals>({
    referredDepositsByCurrency: {},
    subAdminShareByCurrency: {},
    adminShareByCurrency: {},
  })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [search, setSearch] = useState('')
  const [busy, setBusy] = useState<Set<string>>(new Set())

  const load = async () => {
    setError(null)
    try {
      const res = await fetch('/api/admin/sub-admins', { cache: 'no-store' })
      if (!res.ok) throw new Error(`HTTP ${res.status}`)
      const data = (await res.json()) as {
        subAdmins: SubAdminRow[]
        platform?: PlatformTotals
      }
      setRows(data.subAdmins)
      if (data.platform) setPlatform(data.platform)
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e))
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void load()
  }, [])

  const filtered = rows.filter((r) => {
    const q = search.trim().toLowerCase()
    if (!q) return true
    return (
      r.name.toLowerCase().includes(q) ||
      r.email.toLowerCase().includes(q) ||
      r.referralCode.toLowerCase().includes(q)
    )
  })

  const setBusyFor = (id: string, on: boolean) =>
    setBusy((prev) => {
      const next = new Set(prev)
      if (on) next.add(id)
      else next.delete(id)
      return next
    })

  const handleToggleApprove = async (row: SubAdminRow) => {
    setBusyFor(row.id, true)
    try {
      const res = await fetch(`/api/admin/sub-admins/${row.id}`, {
        method: 'PATCH',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ approved: !row.approved }),
      })
      if (!res.ok) throw new Error(`HTTP ${res.status}`)
      setRows((prev) =>
        prev.map((r) => (r.id === row.id ? { ...r, approved: !row.approved } : r)),
      )
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e))
    } finally {
      setBusyFor(row.id, false)
    }
  }

  const handleMarkPaid = async (row: SubAdminRow, currency: string, amount: number) => {
    if (amount <= 0) return
    if (
      !confirm(
        `Mark ${currency} ${formatMoney(amount, currency)} as paid to "${row.name}"? This clears their ${currency} balance to 0. Lifetime earnings stay on record.`,
      )
    ) {
      return
    }
    setBusyFor(row.id, true)
    try {
      const res = await fetch(`/api/admin/sub-admins/${row.id}`, {
        method: 'PATCH',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ clearCommissionBalance: true, currency }),
      })
      if (!res.ok) throw new Error(`HTTP ${res.status}`)
      setRows((prev) =>
        prev.map((r) => {
          if (r.id !== row.id) return r
          const balances = { ...(r.commissionBalances ?? {}) }
          balances[currency] = 0
          return {
            ...r,
            commissionBalances: balances,
            commissionBalance: currency === 'GHS' ? 0 : r.commissionBalance,
          }
        }),
      )
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e))
    } finally {
      setBusyFor(row.id, false)
    }
  }

  const handleDelete = async (row: SubAdminRow) => {
    if (
      !confirm(
        `Delete sub-admin "${row.name}"? Their referral code will no longer work but past referrals stay in the user table.`,
      )
    ) {
      return
    }
    setBusyFor(row.id, true)
    try {
      const res = await fetch(`/api/admin/sub-admins/${row.id}`, { method: 'DELETE' })
      if (!res.ok) throw new Error(`HTTP ${res.status}`)
      setRows((prev) => prev.filter((r) => r.id !== row.id))
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e))
    } finally {
      setBusyFor(row.id, false)
    }
  }

  const totals = rows.reduce(
    (acc, r) => ({
      referrals: acc.referrals + r.referrals,
      deposits: acc.deposits + r.withDeposit,
    }),
    { referrals: 0, deposits: 0 },
  )

  // Outstanding payouts grouped by currency, summed across all sub-admins.
  const outstandingByCurrency: Record<string, number> = {}
  for (const r of rows) {
    for (const [cur, amt] of Object.entries(r.commissionBalances ?? {})) {
      if (!amt) continue
      outstandingByCurrency[cur] = +((outstandingByCurrency[cur] ?? 0) + amt).toFixed(2)
    }
  }

  const currenciesInPlay = Array.from(
    new Set([
      ...Object.keys(platform.referredDepositsByCurrency),
      ...Object.keys(platform.subAdminShareByCurrency),
      ...Object.keys(platform.adminShareByCurrency),
      ...Object.keys(outstandingByCurrency),
    ]),
  ).sort()

  return (
    <div className="p-4 sm:p-6 space-y-5 max-w-6xl">
      <div>
        <h1 className="text-title font-bold tracking-tight">Sub-admins (Partners)</h1>
        <p className="text-sm text-muted-foreground">
          Partners self-register at{' '}
          <code className="font-mono text-xs px-1.5 py-0.5 rounded bg-secondary">/sub-admin/register</code>. They earn {Math.round(COMMISSION_RATE * 100)}% on
          every deposit from each referred user.
        </p>
      </div>

      <AdminCommissionConfig />

      <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
        <Tile label="Partners" value={rows.length.toString()} />
        <Tile label="Approved" value={rows.filter((r) => r.approved).length.toString()} />
        <Tile label="Referred users" value={totals.referrals.toString()} />
      </div>

      {/* Money split — every referred-user deposit splits sub-admin / admin */}
      {currenciesInPlay.length === 0 ? (
        <div className="bg-card border border-dashed border-border rounded-xl p-6 text-center">
          <p className="text-sm text-muted-foreground">No referred deposits yet.</p>
        </div>
      ) : (
        <div className="bg-card border border-border rounded-xl p-4 overflow-x-auto shadow-card">
          <p className="text-eyebrow text-muted-foreground mb-2.5">
            Money split by currency
          </p>
          <table className="w-full text-sm">
            <thead>
              <tr className="text-[10px] uppercase tracking-wide text-muted-foreground">
                <th className="text-left font-semibold py-1.5">Currency</th>
                <th className="text-right font-semibold py-1.5">Referred deposits</th>
                <th className="text-right font-semibold py-1.5">Sub-admin share</th>
                <th className="text-right font-semibold py-1.5">Admin share</th>
                <th className="text-right font-semibold py-1.5">Outstanding</th>
              </tr>
            </thead>
            <tbody>
              {currenciesInPlay.map((cur) => (
                <tr key={cur} className="border-t border-border hover:bg-secondary/30 transition-colors">
                  <td className="py-2 font-semibold">{cur}</td>
                  <td className="py-2 text-right tabular-nums">
                    {formatMoney(platform.referredDepositsByCurrency[cur] ?? 0, cur)}
                  </td>
                  <td className="py-2 text-right tabular-nums">
                    {formatMoney(platform.subAdminShareByCurrency[cur] ?? 0, cur)}
                  </td>
                  <td className="py-2 text-right tabular-nums text-success font-semibold">
                    {formatMoney(platform.adminShareByCurrency[cur] ?? 0, cur)}
                  </td>
                  <td className="py-2 text-right tabular-nums font-semibold">
                    {formatMoney(outstandingByCurrency[cur] ?? 0, cur)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <div className="flex gap-3">
        <div className="relative flex-1">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search by name, email, or code…"
            className="pl-10 bg-card border-border"
          />
        </div>
      </div>

      {error && (
        <div className="p-3 rounded-xl bg-destructive/10 border border-destructive/30 text-destructive text-sm shadow-card">
          {error}
        </div>
      )}

      {loading ? (
        <div className="space-y-2">
          {Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={i} className="h-16 rounded-xl" />
          ))}
        </div>
      ) : filtered.length === 0 ? (
        <div className="bg-card border border-dashed border-border rounded-xl p-8 text-center">
          <p className="text-sm text-muted-foreground">
            {rows.length === 0
              ? 'No partners yet. Share /sub-admin/register to start signing them up.'
              : 'No partners match the current search.'}
          </p>
        </div>
      ) : (
        <div className="bg-card border border-border rounded-xl overflow-hidden shadow-card">
          <div className="hidden md:grid grid-cols-[1fr_100px_80px_80px_100px_100px] gap-3 px-4 py-2 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground border-b border-border bg-secondary/40">
            <span>Partner</span>
            <span>Code</span>
            <span className="text-right">Refs</span>
            <span className="text-right">Deps</span>
            <span className="text-right">Balance</span>
            <span>Actions</span>
          </div>
          <ul className="divide-y divide-border">
            {filtered.map((r) => (
              <li key={r.id} className="px-4 py-3">
                <div className="md:grid md:grid-cols-[1fr_100px_80px_80px_100px_100px] md:gap-3 md:items-center flex flex-col gap-2">
                  <div className="min-w-0">
                    <div className="flex items-center gap-2">
                      <p className="font-medium text-sm truncate">{r.name}</p>
                      <span
                        className={`text-[10px] font-bold uppercase px-1.5 py-0.5 rounded border ${
                          r.approved
                            ? 'bg-success/15 text-success border-success/30'
                            : 'bg-amber-500/15 text-amber-500 border-amber-500/30'
                        }`}
                      >
                        {r.approved ? 'Active' : 'Disabled'}
                      </span>
                    </div>
                    <p className="text-xs text-muted-foreground truncate">{r.email}</p>
                  </div>
                  <p className="font-mono text-xs tracking-wider text-primary">
                    {r.referralCode}
                  </p>
                  <p className="md:text-right text-sm tabular-nums">
                    <span className="md:hidden text-muted-foreground text-xs mr-1">Refs:</span>
                    {r.referrals}
                  </p>
                  <p className="md:text-right text-sm tabular-nums">
                    <span className="md:hidden text-muted-foreground text-xs mr-1">Deps:</span>
                    {r.withDeposit}
                  </p>
                  <div className="md:text-right text-sm font-semibold tabular-nums text-success space-y-0.5">
                    <span className="md:hidden text-muted-foreground text-xs mr-1">Bal:</span>
                    {Object.entries(r.commissionBalances ?? {}).filter(([, v]) => v > 0).length === 0 ? (
                      <span className="text-muted-foreground font-normal">—</span>
                    ) : (
                      Object.entries(r.commissionBalances ?? {})
                        .filter(([, v]) => v > 0)
                        .map(([cur, amt]) => (
                          <div key={cur}>
                            {cur} {formatMoney(amt, cur)}
                          </div>
                        ))
                    )}
                  </div>
                  <div className="flex items-center gap-1.5 flex-wrap">
                    {Object.entries(r.commissionBalances ?? {})
                      .filter(([, amt]) => amt > 0)
                      .map(([cur, amt]) => (
                        <Button
                          key={cur}
                          size="sm"
                          variant="outline"
                          onClick={() => handleMarkPaid(r, cur, amt)}
                          disabled={busy.has(r.id)}
                          className="h-7 px-2 text-xs gap-1 text-success border-success/40 hover:bg-success/10"
                          title={`Mark ${cur} ${formatMoney(amt, cur)} as paid`}
                        >
                          <Check className="w-3.5 h-3.5" />
                          <span className="hidden sm:inline">Paid {cur}</span>
                        </Button>
                      ))}
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => handleToggleApprove(r)}
                      disabled={busy.has(r.id)}
                      className="h-7 px-2 text-xs gap-1"
                      title={r.approved ? 'Disable' : 'Enable'}
                    >
                      {r.approved ? (
                        <ToggleRight className="w-3.5 h-3.5 text-success" />
                      ) : (
                        <ToggleLeft className="w-3.5 h-3.5 text-muted-foreground" />
                      )}
                    </Button>
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => handleDelete(r)}
                      disabled={busy.has(r.id)}
                      className="h-7 px-2 text-destructive hover:bg-destructive/10"
                      title="Delete"
                    >
                      {busy.has(r.id) ? (
                        <Loader2 className="w-3.5 h-3.5 animate-spin" />
                      ) : (
                        <Trash2 className="w-3.5 h-3.5" />
                      )}
                    </Button>
                  </div>
                </div>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  )
}

function Tile({
  label,
  value,
  highlight = false,
}: {
  label: string
  value: string
  highlight?: boolean
}) {
  return (
    <div
      className={`rounded-xl p-4 border shadow-card ${
        highlight ? 'bg-success/10 border-success/30' : 'bg-card border-border'
      }`}
    >
      <p className={`text-eyebrow ${highlight ? 'text-success' : 'text-muted-foreground'}`}>
        {label}
      </p>
      <p
        className={`text-2xl font-extrabold tabular-nums tracking-tight mt-1.5 ${
          highlight ? 'text-success' : 'text-foreground'
        }`}
      >
        {value}
      </p>
    </div>
  )
}
