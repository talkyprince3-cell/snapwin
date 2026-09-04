'use client'

import { useEffect, useState } from 'react'
import { useRouter } from 'next/navigation'
import {
  Loader2,
  RefreshCcw,
  CheckCircle2,
  XCircle,
  LogOut,
  ExternalLink,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'

interface StatusResponse {
  env: { odds_api_key: boolean; admin_password: boolean; node_env: string }
  oddsApi: {
    ok: boolean
    remaining: number | null
    used: number | null
    status: number
    plan?: string
    message?: string
  }
}

export default function AdminSettingsPage() {
  const router = useRouter()
  const [status, setStatus] = useState<StatusResponse | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const load = async () => {
    setLoading(true)
    setError(null)
    try {
      const res = await fetch('/api/admin/status', { cache: 'no-store' })
      if (!res.ok) throw new Error(`HTTP ${res.status}`)
      setStatus((await res.json()) as StatusResponse)
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e))
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void load()
  }, [])

  const handleLogout = async () => {
    await fetch('/api/admin/logout', { method: 'POST' })
    router.push('/admin/login')
    router.refresh()
  }

  return (
    <div className="p-4 sm:p-6 space-y-6 max-w-3xl">
      <div className="flex items-start justify-between gap-4 flex-wrap">
        <div>
          <h1 className="text-title font-bold tracking-tight">Settings</h1>
          <p className="text-sm text-muted-foreground">
            Environment + integration status. Read-only — change values by editing{' '}
            <code className="font-mono text-xs px-1.5 py-0.5 rounded bg-secondary">.env.local</code> and restarting the dev server.
          </p>
        </div>
        <Button
          variant="outline"
          onClick={() => void load()}
          disabled={loading}
          className="gap-2"
        >
          {loading ? (
            <Loader2 className="w-4 h-4 animate-spin" />
          ) : (
            <RefreshCcw className="w-4 h-4" />
          )}
          Refresh
        </Button>
      </div>

      {error && (
        <div className="p-3 rounded-xl bg-destructive/10 border border-destructive/30 text-destructive text-sm shadow-card">
          {error}
        </div>
      )}

      <DepositAccountCard />

      {loading && !status && (
        <div className="space-y-3">
          <Skeleton className="h-32 rounded-xl" />
          <Skeleton className="h-48 rounded-xl" />
        </div>
      )}

      {status && (
        <>
          {/* Env vars */}
          <section className="bg-card border border-border rounded-xl overflow-hidden shadow-card">
            <header className="px-4 py-3 border-b border-border">
              <h2 className="font-semibold text-title">Environment</h2>
              <p className="text-xs text-muted-foreground">
                NODE_ENV is <code className="font-mono">{status.env.node_env}</code>
              </p>
            </header>
            <ul className="divide-y divide-border">
              <EnvRow
                label="API_FOOTBALL_KEY"
                ok={status.env.odds_api_key}
                helpText="Used by /api/matches to fetch fixtures and odds from api-football.com"
              />
              <EnvRow
                label="ADMIN_PASSWORD"
                ok={status.env.admin_password}
                helpText="Gates /admin/*. Removing this disables the admin area."
              />
            </ul>
          </section>

          {/* API-Football */}
          <section className="bg-card border border-border rounded-xl overflow-hidden shadow-card">
            <header className="px-4 py-3 border-b border-border flex items-center justify-between gap-2">
              <h2 className="font-semibold text-title">
                API-Football
                {status.oddsApi.plan && (
                  <span className="ml-2 text-xs font-normal text-muted-foreground">
                    {status.oddsApi.plan}
                  </span>
                )}
              </h2>
              <a
                href="https://dashboard.api-football.com/profile"
                target="_blank"
                rel="noreferrer"
                className="text-xs text-primary hover:underline flex items-center gap-1"
              >
                Dashboard <ExternalLink className="w-3 h-3" />
              </a>
            </header>
            <div className="p-4 space-y-3">
              {!status.env.odds_api_key ? (
                <p className="text-sm text-muted-foreground">
                  No API_FOOTBALL_KEY set. /api/matches will only return admin-created custom matches.
                </p>
              ) : status.oddsApi.ok ? (
                <>
                  <div className="flex items-center gap-2 text-sm">
                    <CheckCircle2 className="w-4 h-4 text-success" />
                    <span className="font-semibold text-success">Reachable</span>
                    <span className="text-muted-foreground">HTTP {status.oddsApi.status}</span>
                  </div>
                  <div className="grid grid-cols-2 gap-3">
                    <Stat
                      label="Requests today"
                      value={status.oddsApi.used?.toString() ?? '—'}
                    />
                    <Stat
                      label="Remaining today"
                      value={status.oddsApi.remaining?.toString() ?? '—'}
                      tone={
                        status.oddsApi.remaining != null && status.oddsApi.remaining < 50
                          ? 'bad'
                          : 'good'
                      }
                    />
                  </div>
                  {status.oddsApi.remaining != null && status.oddsApi.used != null && (
                    <QuotaBar
                      remaining={status.oddsApi.remaining}
                      used={status.oddsApi.used}
                    />
                  )}
                </>
              ) : (
                <div className="flex items-start gap-2 text-sm">
                  <XCircle className="w-4 h-4 text-destructive mt-0.5" />
                  <div>
                    <p className="font-semibold text-destructive">
                      Probe failed (HTTP {status.oddsApi.status || 0})
                    </p>
                    {status.oddsApi.message && (
                      <pre className="text-xs text-muted-foreground bg-secondary/50 rounded p-2 mt-2 whitespace-pre-wrap break-all max-h-32 overflow-auto">
                        {status.oddsApi.message}
                      </pre>
                    )}
                  </div>
                </div>
              )}
            </div>
          </section>
        </>
      )}

      {/* Session */}
      <section className="bg-card border border-border rounded-xl p-4 flex items-center justify-between gap-3 shadow-card">
        <div>
          <h2 className="font-semibold text-title">Session</h2>
          <p className="text-xs text-muted-foreground">
            Session ends in ~12 hours from sign-in.
          </p>
        </div>
        <Button onClick={handleLogout} variant="outline" className="gap-2">
          <LogOut className="w-4 h-4" />
          Logout
        </Button>
      </section>
    </div>
  )
}

function DepositAccountCard() {
  const [number, setNumber] = useState('')
  const [name, setName] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [msg, setMsg] = useState<string | null>(null)
  const [err, setErr] = useState<string | null>(null)

  useEffect(() => {
    fetch('/api/admin/settings/deposit', { cache: 'no-store' })
      .then((r) => r.json())
      .then((d) => { setNumber(d.number ?? ''); setName(d.name ?? '') })
      .catch(() => {})
      .finally(() => setLoading(false))
  }, [])

  const save = async () => {
    setSaving(true); setMsg(null); setErr(null)
    try {
      const res = await fetch('/api/admin/settings/deposit', {
        method: 'POST',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ number, name }),
      })
      const data = await res.json().catch(() => ({}))
      if (!res.ok) throw new Error(data.error ?? `HTTP ${res.status}`)
      setNumber(data.number ?? number)
      setMsg('Saved — the deposit screen now shows this number.')
    } catch (e) {
      setErr(e instanceof Error ? e.message : String(e))
    } finally {
      setSaving(false)
    }
  }

  return (
    <section className="bg-card border border-border rounded-xl overflow-hidden shadow-card">
      <header className="px-4 py-3 border-b border-border">
        <h2 className="font-semibold text-title">Deposit account</h2>
        <p className="text-xs text-muted-foreground">
          The MoMo number customers send manual deposits to. Changes apply immediately — no redeploy.
        </p>
      </header>
      <div className="p-4 space-y-3">
        {loading ? (
          <Skeleton className="h-10 rounded-lg" />
        ) : (
          <>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label className="block text-xs font-semibold uppercase tracking-wide text-muted-foreground mb-1">MoMo number</label>
                <input
                  value={number}
                  onChange={(e) => setNumber(e.target.value)}
                  placeholder="0501084331"
                  inputMode="numeric"
                  className="w-full h-10 px-3 rounded-md bg-secondary border border-border text-sm font-mono tabular-nums"
                />
              </div>
              <div>
                <label className="block text-xs font-semibold uppercase tracking-wide text-muted-foreground mb-1">Account name</label>
                <input
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  placeholder="SnapWin"
                  className="w-full h-10 px-3 rounded-md bg-secondary border border-border text-sm"
                />
              </div>
            </div>
            {msg && <p className="text-xs text-success flex items-center gap-1.5"><CheckCircle2 className="w-3.5 h-3.5" /> {msg}</p>}
            {err && <p className="text-xs text-destructive flex items-center gap-1.5"><XCircle className="w-3.5 h-3.5" /> {err}</p>}
            <div className="flex justify-end">
              <Button onClick={() => void save()} disabled={saving} className="gap-2 bg-primary text-primary-foreground hover:bg-primary/90">
                {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : null}
                Save
              </Button>
            </div>
          </>
        )}
      </div>
    </section>
  )
}

function EnvRow({
  label,
  ok,
  helpText,
}: {
  label: string
  ok: boolean
  helpText: string
}) {
  return (
    <li className="px-4 py-3 flex items-center justify-between gap-3">
      <div className="min-w-0">
        <p className="font-mono text-sm font-semibold">{label}</p>
        <p className="text-xs text-muted-foreground">{helpText}</p>
      </div>
      <span
        className={`text-[10px] font-bold uppercase px-2 py-0.5 rounded-full border shrink-0 ${
          ok
            ? 'bg-success/15 text-success border-success/30'
            : 'bg-destructive/15 text-destructive border-destructive/30'
        }`}
      >
        {ok ? 'Set' : 'Missing'}
      </span>
    </li>
  )
}

function Stat({
  label,
  value,
  tone = 'neutral',
}: {
  label: string
  value: string
  tone?: 'good' | 'bad' | 'neutral'
}) {
  const color =
    tone === 'good'
      ? 'text-success'
      : tone === 'bad'
        ? 'text-destructive'
        : 'text-foreground'
  return (
    <div>
      <p className="text-[10px] uppercase tracking-wide text-muted-foreground font-semibold">
        {label}
      </p>
      <p className={`text-xl font-bold tabular-nums ${color}`}>{value}</p>
    </div>
  )
}

function QuotaBar({ remaining, used }: { remaining: number; used: number }) {
  const total = remaining + used
  const pct = total > 0 ? Math.round((used / total) * 100) : 0
  const tone = pct > 90 ? 'bg-destructive' : pct > 75 ? 'bg-warning' : 'bg-primary'
  return (
    <div>
      <div className="flex justify-between text-[10px] text-muted-foreground mb-1">
        <span>Quota usage</span>
        <span>{pct}%</span>
      </div>
      <div className="h-2 bg-secondary rounded-full overflow-hidden">
        <div
          className={`h-full ${tone} transition-all`}
          style={{ width: `${pct}%` }}
        />
      </div>
    </div>
  )
}
