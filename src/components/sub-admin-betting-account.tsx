'use client'

/**
 * The partner's own betting wallet, shown on the partner dashboard.
 *
 * Partners asked to bet on the main site with the same account. They can: the
 * wallet carries the same email and password hash as this dashboard login, so
 * they sign in at /login with the credentials they already have. This panel
 * exists to tell them that and show the balance — there is nothing to set up.
 */

import { useCallback, useEffect, useState } from 'react'
import Link from 'next/link'
import { Wallet, ExternalLink, Loader2 } from 'lucide-react'

interface Account {
  id: string
  name: string
  email: string
  currency: string
  balance: number
  totalDeposited: number
}

const money = (n: number, c: string) =>
  `${c} ${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

export function SubAdminBettingAccount() {
  const [account, setAccount] = useState<Account | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [loaded, setLoaded] = useState(false)

  const load = useCallback(async () => {
    try {
      const res = await fetch('/api/sub-admin/betting-account', { cache: 'no-store' })
      const d = await res.json().catch(() => ({}))
      if (!res.ok) {
        setError(d.error ?? `Could not load your betting account (HTTP ${res.status}).`)
        return
      }
      setAccount(d.account as Account)
      setError(null)
    } catch {
      setError('Could not reach the server. Retry in a moment.')
    } finally {
      setLoaded(true)
    }
  }, [])

  useEffect(() => {
    void load()
  }, [load])

  return (
    <section className="bg-card border border-border rounded-xl overflow-hidden">
      <header className="px-4 py-3 border-b border-border flex items-center gap-2">
        <Wallet className="w-4 h-4 text-primary" />
        <h2 className="font-semibold">Your betting account</h2>
      </header>

      <div className="p-4 space-y-3">
        {!loaded ? (
          <p className="text-sm text-muted-foreground">Opening your wallet…</p>
        ) : error ? (
          <div className="space-y-2">
            <p className="text-sm text-destructive">{error}</p>
            <button
              onClick={() => void load()}
              className="rounded-lg bg-secondary px-3 py-1.5 text-xs font-semibold"
            >
              Try again
            </button>
          </div>
        ) : account ? (
          <>
            <div className="flex items-end justify-between gap-3">
              <div>
                <div className="text-xs text-muted-foreground">Betting balance</div>
                <div className="text-2xl font-bold">{money(account.balance, account.currency)}</div>
              </div>
              <div className="text-right text-xs text-muted-foreground">
                Deposited
                <div className="font-semibold text-foreground">
                  {money(account.totalDeposited, account.currency)}
                </div>
              </div>
            </div>

            <p className="text-xs text-muted-foreground leading-relaxed">
              Bet on the main site with this same account — sign in at{' '}
              <span className="font-semibold text-foreground">{account.email}</span> using your
              partner password. Deposits you make there credit this balance.
            </p>

            <div className="flex gap-2">
              <Link
                href="/login"
                className="flex-1 flex items-center justify-center gap-1.5 rounded-lg bg-primary text-primary-foreground font-semibold text-sm py-2.5"
              >
                Go to the site <ExternalLink className="w-3.5 h-3.5" />
              </Link>
              <Link
                href="/account"
                className="flex-1 flex items-center justify-center rounded-lg border border-border font-semibold text-sm py-2.5"
              >
                Deposit
              </Link>
            </div>

            <p className="text-xs text-muted-foreground">
              This wallet is separate from your commission balance. Commission is paid out through
              a payout request; this is money you deposit and bet with.
            </p>
          </>
        ) : (
          <p className="text-sm text-muted-foreground flex items-center gap-2">
            <Loader2 className="w-4 h-4 animate-spin" /> Preparing…
          </p>
        )}
      </div>
    </section>
  )
}
