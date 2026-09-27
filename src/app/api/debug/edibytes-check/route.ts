import { NextResponse } from 'next/server'

export const dynamic = 'force-dynamic'

/**
 * Read-only Edibytes health check for the DEPLOYED environment.
 *
 *   GET /api/debug/edibytes-check
 *
 * Reports which EDIBYTES_* vars are set (never their values) and authenticates
 * against the verify endpoint with a reference that cannot exist. Nothing is
 * charged and no prompt is sent — this deliberately stops short of
 * /initialize, which opens a real payment.
 *
 * Reading it: a 404 means the key was accepted and the lookup simply found
 * nothing, which is the healthy answer. A 401 or 403 means the key itself is
 * being refused. Anything else is Edibytes having a bad day.
 */
const BASE = (process.env.EDIBYTES_BASE_URL?.trim() || 'https://api.edibytes.online').replace(/\/+$/, '')

export async function GET() {
  const key = process.env.EDIBYTES_SECRET_KEY?.trim() || ''

  const env = {
    hasSecretKey: !!key,
    // Live or test, and nothing more of the credential than that.
    keyMode: key ? (key.startsWith('sk_live_') ? 'live' : key.startsWith('sk_test_') ? 'test' : 'unknown') : null,
    hasPublicKey: !!process.env.EDIBYTES_PUBLIC_KEY?.trim(),
    // Unset means each request uses its own host, which is usually what you
    // want — but it does mean every host you serve from needs whitelisting.
    domain: process.env.EDIBYTES_DOMAIN?.trim() || '(request host)',
    baseUrl: BASE,
  }

  if (!key) {
    return NextResponse.json({
      env,
      auth: { ok: false, error: 'EDIBYTES_SECRET_KEY not set in this environment' },
      hint: 'Set it in the Vercel project and redeploy — deposits cannot start without it.',
    })
  }

  let auth: { ok: boolean; status?: number; note?: string; error?: string }
  try {
    const res = await fetch(`${BASE}/api/payments/verify/SNAPWIN-HEALTHCHECK-NOT-A-PAYMENT/`, {
      headers: { Authorization: `Bearer ${key}` },
      cache: 'no-store',
    })
    if (res.status === 404) {
      auth = { ok: true, status: 404, note: 'key accepted; the probe reference does not exist, as expected' }
    } else if (res.status === 401 || res.status === 403) {
      auth = { ok: false, status: res.status, error: 'Edibytes refused this secret key' }
    } else {
      auth = { ok: false, status: res.status, error: `unexpected response (HTTP ${res.status})` }
    }
  } catch (e) {
    auth = { ok: false, error: e instanceof Error ? e.message : String(e) }
  }

  return NextResponse.json({
    env,
    auth,
    hint: auth.ok
      ? `The key works. If a deposit still fails at the prompt, the likely cause is the domain not being whitelisted on the Edibytes dashboard — it must list ${env.domain}.`
      : 'The deployment cannot authenticate with Edibytes. Check EDIBYTES_SECRET_KEY in Vercel, then redeploy.',
  })
}
