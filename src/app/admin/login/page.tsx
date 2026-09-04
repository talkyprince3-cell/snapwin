'use client'

import { useState, useEffect, Suspense } from 'react'
import { useRouter, useSearchParams } from 'next/navigation'
import Link from 'next/link'
import { Loader2, ShieldAlert, ArrowLeft } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'

function LoginForm() {
  const router = useRouter()
  const params = useSearchParams()
  const next = params.get('next') ?? '/admin'
  const disabled = params.get('disabled') === '1'

  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)

  useEffect(() => {
    if (disabled) {
      setError(
        'Admin is disabled — ADMIN_PASSWORD is not set for this environment. ' +
          'Locally: add it to .env.local and restart. On Vercel: add it to the ' +
          "project's Environment Variables and redeploy.",
      )
    }
  }, [disabled])

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setError(null)
    setLoading(true)
    try {
      const res = await fetch('/api/admin/login', {
        method: 'POST',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ password }),
      })
      const data = await res.json().catch(() => ({}))
      if (!res.ok) {
        throw new Error(data.error ?? `HTTP ${res.status}`)
      }
      router.push(next)
      router.refresh()
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e))
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="min-h-screen bg-background flex flex-col">
      <header className="bg-card border-b border-border">
        <div className="max-w-md mx-auto px-4 h-14 flex items-center justify-between">
          <Link
            href="/"
            className="flex items-center gap-2 text-muted-foreground hover:text-foreground transition-colors"
          >
            <ArrowLeft className="w-5 h-5" />
            <span>Back to site</span>
          </Link>
          <Link href="/" className="flex items-center" aria-label="SnapWin home">
            <span className="font-display font-extrabold tracking-tight text-lg leading-none">
              Snap<span className="text-primary">Win</span>
            </span>
          </Link>
        </div>
      </header>

      <main className="flex-1 flex items-center justify-center p-4 py-8">
        <div className="relative w-full max-w-md">
          <div aria-hidden className="absolute -top-16 -left-12 w-56 h-56 rounded-full bg-primary/20 blur-3xl pointer-events-none" />
          <div aria-hidden className="absolute -bottom-16 -right-12 w-56 h-56 rounded-full bg-primary/10 blur-3xl pointer-events-none" />

          <div className="relative bg-card rounded-2xl border border-border p-6 sm:p-8 shadow-card">
            <div className="text-center mb-6">
              <div className="relative w-14 h-14 mx-auto mb-3">
                <div aria-hidden className="absolute inset-0 rounded-2xl bg-primary/25 blur-xl" />
                <div className="relative w-14 h-14 rounded-2xl bg-primary/10 border border-primary/30 flex items-center justify-center shadow-card">
                  <ShieldAlert className="w-7 h-7 text-primary" />
                </div>
              </div>
              <h1 className="text-title font-bold text-foreground tracking-tight">Admin sign-in</h1>
              <p className="text-sm text-muted-foreground mt-1">
                Enter the password set in your .env.local
              </p>
            </div>

            <form onSubmit={handleSubmit} className="space-y-4">
              <div>
                <label
                  htmlFor="admin-password"
                  className="text-eyebrow text-muted-foreground block mb-2"
                >
                  Password
                </label>
                <Input
                  id="admin-password"
                  type="password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  placeholder="••••••••"
                  required
                  autoFocus
                  className="h-12 bg-secondary border-border"
                />
              </div>

              {error && (
                <div className="p-3 rounded-lg bg-destructive/10 border border-destructive/30 text-xs text-destructive font-medium">
                  {error}
                </div>
              )}

              <Button
                type="submit"
                disabled={loading || disabled}
                className="w-full h-12 bg-primary text-primary-foreground hover:bg-primary/90 font-bold text-sm shadow-card hover:shadow-card-hover hover:-translate-y-0.5 active:translate-y-0 transition-all"
              >
                {loading ? (
                  <>
                    <Loader2 className="w-4 h-4 mr-2 animate-spin" /> Signing in…
                  </>
                ) : (
                  'Sign In'
                )}
              </Button>
            </form>
          </div>

          <p className="relative text-center text-xs text-muted-foreground mt-4">
            This area is gated by a single shared password. Rotate it by changing
            ADMIN_PASSWORD — in .env.local for local work, and in the hosting
            project&apos;s environment variables for a deployment.
          </p>
        </div>
      </main>
    </div>
  )
}

export default function AdminLoginPage() {
  return (
    <Suspense fallback={<div className="min-h-screen bg-background" />}>
      <LoginForm />
    </Suspense>
  )
}
