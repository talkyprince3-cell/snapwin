'use client'

/**
 * Global controls for the partner commission programme.
 *
 * The rate used to be a compiled-in constant, so changing one partner's cut
 * meant a deploy and moved everyone's. This sets the default every partner
 * inherits, and the pause switch that stops commission accruing at all.
 *
 * Pausing affects only deposits received from that moment on. Commission
 * already recorded is untouched, which is deliberate: it has been earned, and
 * in several cases already paid.
 */

import { useCallback, useEffect, useState } from 'react'
import { Loader2, Percent, PauseCircle, PlayCircle } from 'lucide-react'

interface Config {
  defaultPct: number
  paused: boolean
}

export function AdminCommissionConfig() {
  const [cfg, setCfg] = useState<Config | null>(null)
  const [pct, setPct] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const load = useCallback(async () => {
    try {
      const res = await fetch('/api/admin/commission-config', { cache: 'no-store' })
      if (!res.ok) {
        const d = await res.json().catch(() => ({}))
        setError(d.error ?? `Could not load commission settings (HTTP ${res.status}).`)
        return
      }
      const d = (await res.json()) as Config
      setCfg(d)
      setPct(String(d.defaultPct))
      setError(null)
    } catch {
      setError('Could not reach the server.')
    }
  }, [])

  useEffect(() => {
    void load()
  }, [load])

  const save = async (patch: Partial<Config>) => {
    setBusy(true)
    setError(null)
    setNotice(null)
    try {
      const res = await fetch('/api/admin/commission-config', {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(patch),
      })
      const d = await res.json().catch(() => ({}))
      if (!res.ok) {
        setError(d.error ?? 'Could not save.')
        return
      }
      setCfg(d as Config)
      setPct(String((d as Config).defaultPct))
      setNotice('Saved.')
      setTimeout(() => setNotice(null), 2000)
    } catch {
      setError('Network error — please try again.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="bg-card border border-border rounded-xl p-4 space-y-3">
      <div className="flex items-center gap-2">
        <Percent className="w-4 h-4 text-primary" />
        <h2 className="font-semibold">Commission programme</h2>
        {cfg?.paused && (
          <span className="rounded border border-amber-400/40 bg-amber-400/10 px-1.5 py-0.5 text-[10px] font-bold text-amber-400">
            PAUSED
          </span>
        )}
      </div>

      {error && <p className="text-sm text-destructive">{error}</p>}
      {notice && <p className="text-sm text-emerald-400">{notice}</p>}

      {!cfg ? (
        <p className="text-sm text-muted-foreground">Loading…</p>
      ) : (
        <>
          <div className="flex items-end gap-2">
            <label className="text-xs text-muted-foreground flex-1">
              Default rate every partner inherits (%)
              <input
                type="number"
                min={0}
                max={100}
                step="0.01"
                value={pct}
                onChange={(e) => setPct(e.target.value)}
                className="mt-1 w-full bg-secondary border border-border rounded-lg px-3 py-2 text-sm text-foreground"
              />
            </label>
            <button
              onClick={() => void save({ defaultPct: Number(pct) })}
              disabled={busy}
              className="rounded-lg bg-primary text-primary-foreground font-semibold text-sm px-4 py-2 disabled:opacity-50"
            >
              {busy ? <Loader2 className="w-4 h-4 animate-spin" /> : 'Save'}
            </button>
          </div>

          <button
            onClick={() => void save({ paused: !cfg.paused })}
            disabled={busy}
            className="w-full flex items-center justify-center gap-2 rounded-lg border border-border font-semibold text-sm py-2.5 disabled:opacity-50"
          >
            {cfg.paused ? (
              <>
                <PlayCircle className="w-4 h-4" /> Resume commission
              </>
            ) : (
              <>
                <PauseCircle className="w-4 h-4" /> Pause commission
              </>
            )}
          </button>

          <p className="text-xs text-muted-foreground">
            Pausing stops commission on deposits received from that moment on. Anything already
            recorded is left alone. Partners marked exempt keep earning through a pause.
          </p>
        </>
      )}
    </section>
  )
}
