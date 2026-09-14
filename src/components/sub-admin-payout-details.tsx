'use client'

/**
 * Payout details — where a partner's commission gets sent.
 *
 * Ports save_payout_details from the reference. Saved once and reused, so a
 * payout request is pre-filled rather than retyped: on a real transfer a
 * mistyped digit is money gone to a stranger.
 *
 * Emits `snapwin:payout-details-saved` so the payout form next to it can pick
 * the details up immediately, rather than only after a reload.
 */

import { useCallback, useEffect, useState } from 'react'
import { Loader2, Landmark, Check } from 'lucide-react'

export const PAYOUT_DETAILS_EVENT = 'snapwin:payout-details-saved'

export interface PayoutDetails {
  name: string
  network: string
  number: string
}

/** Same list the payout form offers; mobile money first. */
export const PAYOUT_NETWORKS = [
  'MTN MoMo',
  'Telecel Cash',
  'AirtelTigo Money',
  'Bank transfer',
] as const

export function SubAdminPayoutDetails() {
  const [detail, setDetail] = useState<PayoutDetails>({ name: '', network: 'MTN MoMo', number: '' })
  const [saved, setSaved] = useState<PayoutDetails | null>(null)
  const [busy, setBusy] = useState(false)
  const [loaded, setLoaded] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const load = useCallback(async () => {
    try {
      const res = await fetch('/api/sub-admin/payout-details', { cache: 'no-store' })
      if (!res.ok) {
        const d = await res.json().catch(() => ({}))
        setError(d.error ?? `Could not load payout details (HTTP ${res.status}).`)
        return
      }
      const d = (await res.json()) as { payout: PayoutDetails }
      if (d.payout.number) setSaved(d.payout)
      setDetail({
        name: d.payout.name || '',
        network: d.payout.network || 'MTN MoMo',
        number: d.payout.number || '',
      })
      setError(null)
    } catch {
      setError('Could not reach the server.')
    } finally {
      setLoaded(true)
    }
  }, [])

  useEffect(() => {
    void load()
  }, [load])

  const save = async () => {
    setBusy(true)
    setError(null)
    setNotice(null)
    try {
      const res = await fetch('/api/sub-admin/payout-details', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(detail),
      })
      const d = await res.json().catch(() => ({}))
      if (!res.ok) {
        setError(d.error ?? 'Could not save.')
        return
      }
      setSaved(d.payout as PayoutDetails)
      setNotice(d.message ?? 'Saved.')
      setTimeout(() => setNotice(null), 2200)
      window.dispatchEvent(new CustomEvent(PAYOUT_DETAILS_EVENT, { detail: d.payout }))
    } catch {
      setError('Network error — please try again.')
    } finally {
      setBusy(false)
    }
  }

  const isBank = /bank/i.test(detail.network)

  return (
    <section className="bg-card border border-border rounded-xl overflow-hidden">
      <header className="px-4 py-3 border-b border-border flex items-center gap-2">
        <Landmark className="w-4 h-4 text-primary" />
        <h2 className="font-semibold">Payout details</h2>
        {saved && (
          <span className="ml-auto flex items-center gap-1 text-[11px] font-semibold text-emerald-400">
            <Check className="w-3 h-3" /> Saved
          </span>
        )}
      </header>

      <div className="p-4 space-y-3">
        {!loaded ? (
          <p className="text-sm text-muted-foreground">Loading…</p>
        ) : (
          <>
            <p className="text-xs text-muted-foreground">
              Where your commission is sent. Saved once and used to pre-fill every payout request.
            </p>

            <label className="block text-xs text-muted-foreground">
              Account name
              <input
                value={detail.name}
                onChange={(e) => setDetail({ ...detail, name: e.target.value })}
                placeholder="Name registered on the account"
                className="mt-1 w-full bg-secondary border border-border rounded-lg px-3 py-2 text-sm text-foreground"
              />
            </label>

            <div className="grid grid-cols-2 gap-2">
              <label className="text-xs text-muted-foreground">
                Payment provider
                <select
                  value={detail.network}
                  onChange={(e) => setDetail({ ...detail, network: e.target.value })}
                  className="mt-1 w-full bg-secondary border border-border rounded-lg px-3 py-2 text-sm text-foreground"
                >
                  {PAYOUT_NETWORKS.map((n) => (
                    <option key={n} value={n}>{n}</option>
                  ))}
                </select>
              </label>
              <label className="text-xs text-muted-foreground">
                {isBank ? 'Account number' : 'Mobile number'}
                <input
                  value={detail.number}
                  onChange={(e) => setDetail({ ...detail, number: e.target.value })}
                  inputMode="numeric"
                  placeholder={isBank ? '0123456789' : '024…'}
                  className="mt-1 w-full bg-secondary border border-border rounded-lg px-3 py-2 text-sm text-foreground"
                />
              </label>
            </div>

            {error && <p className="text-xs text-destructive">{error}</p>}
            {notice && <p className="text-xs text-emerald-400">{notice}</p>}

            <button
              onClick={save}
              disabled={busy}
              className="w-full flex items-center justify-center gap-2 rounded-lg bg-primary text-primary-foreground font-semibold text-sm py-2.5 disabled:opacity-50"
            >
              {busy && <Loader2 className="w-4 h-4 animate-spin" />} Save payout details
            </button>

            <p className="text-xs text-muted-foreground">
              Check the number carefully. Payouts are sent by hand to exactly what is saved here.
            </p>
          </>
        )}
      </div>
    </section>
  )
}
