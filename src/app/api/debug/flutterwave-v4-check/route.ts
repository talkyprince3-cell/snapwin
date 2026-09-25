import { NextResponse } from 'next/server'

export const dynamic = 'force-dynamic'

/**
 * Read-only Flutterwave V4 health check for the DEPLOYED environment.
 *
 *   GET /api/debug/flutterwave-v4-check
 *
 * Reports which FLUTTERWAVE_V4_* vars are set (never their values) and does the
 * OAuth client-credentials exchange. Nothing is charged and no prompt is sent —
 * this deliberately stops short of /charges, which moves money.
 *
 * It exists because a failed deposit could not be told apart from a missing
 * key: both surfaced as one generic message. If `token.ok` is false here, the
 * deployment's credentials are the problem; if it is true, the failure is
 * further down, at the charge, and `hint` says what to read next.
 */
const IDP_TOKEN_URL =
  process.env.FLUTTERWAVE_V4_TOKEN_URL?.trim() ||
  'https://idp.flutterwave.com/realms/flutterwave/protocol/openid-connect/token'

export async function GET() {
  const id = process.env.FLUTTERWAVE_V4_CLIENT_ID?.trim() || ''
  const secret = process.env.FLUTTERWAVE_V4_CLIENT_SECRET?.trim() || ''

  const env = {
    hasClientId: !!id,
    // First segment of the UUID only — enough to tell two apps apart in a
    // support thread without putting the credential in a response body.
    clientIdPrefix: id ? id.split('-')[0] : null,
    hasClientSecret: !!secret,
    hasEncryptionKey: !!process.env.FLUTTERWAVE_V4_ENCRYPTION_KEY?.trim(),
    baseUrl: process.env.FLUTTERWAVE_V4_BASE_URL?.trim() || 'https://f4bexperience.flutterwave.com',
  }

  if (!id || !secret) {
    return NextResponse.json({
      env,
      token: { ok: false, error: 'FLUTTERWAVE_V4_CLIENT_ID / _SECRET not set in this environment' },
      hint: 'Set both in the Vercel project and redeploy — deposits cannot start without them.',
    })
  }

  let token: { ok: boolean; expiresIn?: number; status?: number; error?: string }
  try {
    const res = await fetch(IDP_TOKEN_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        client_id: id,
        client_secret: secret,
        grant_type: 'client_credentials',
      }),
      cache: 'no-store',
    })
    const body = (await res.json().catch(() => ({}))) as {
      access_token?: string
      expires_in?: number
      error?: string
      error_description?: string
    }
    token = body.access_token
      ? { ok: true, expiresIn: body.expires_in, status: res.status }
      : {
          ok: false,
          status: res.status,
          error: body.error_description ?? body.error ?? `HTTP ${res.status}`,
        }
  } catch (e) {
    token = { ok: false, error: e instanceof Error ? e.message : String(e) }
  }

  return NextResponse.json({
    env,
    token,
    hint: token.ok
      ? 'Credentials are good. If deposits still fail, the charge itself is being rejected — the reason now shows under the deposit button and in the function logs.'
      : 'The deployment cannot authenticate with Flutterwave V4. Check the two vars in Vercel, then redeploy.',
  })
}
