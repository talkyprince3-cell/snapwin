import { NextResponse } from 'next/server'
import { initialisePayment, chargeMobileMoneyGhana, type GhanaMomoNetwork } from '@/lib/flutterwave'

export const dynamic = 'force-dynamic'

// Read-only Flutterwave health check for the DEPLOYED environment.
//   GET /api/debug/flutterwave-check
//     → reports which FLUTTERWAVE_* vars are set (never the values) and does a
//       SAFE hosted-checkout init probe (no money moves) to validate the key.
//   GET /api/debug/flutterwave-check?momo=1&phone=024...&network=mtn
//     → additionally fires a REAL Ghana MoMo charge (sends a prompt to that
//       phone) and returns Flutterwave's exact reply — use your own number.
const NETWORK_MAP: Record<string, GhanaMomoNetwork> = {
  mtn: 'MTN',
  vod: 'VODAFONE',
  atl: 'AIRTELTIGO',
}

export async function GET(request: Request) {
  const url = new URL(request.url)
  const sk = process.env.FLUTTERWAVE_SECRET_KEY?.trim() || ''
  const pk = process.env.FLUTTERWAVE_PUBLIC_KEY?.trim() || ''

  const env = {
    hasSecretKey: !!sk,
    secretKeyMode: sk ? (sk.includes('TEST') ? 'test' : 'live') : null,
    secretKeyPrefix: sk ? sk.slice(0, 10) + '…' : null,
    hasPublicKey: !!pk,
    publicKeyMode: pk ? (pk.includes('TEST') ? 'test' : 'live') : null,
    hasEncryptionKey: !!process.env.FLUTTERWAVE_ENCRYPTION_KEY?.trim(),
    hasSecretHash: !!process.env.FLUTTERWAVE_SECRET_HASH?.trim(),
  }

  // Safe probe: creating a hosted-checkout link does NOT move money, but it
  // exercises the secret key + account state, so a bad key/account errors here.
  let hostedInit: { ok: boolean; gotLink?: boolean; error?: string }
  try {
    const r = await initialisePayment({
      email: 'debug@snapwin.app',
      name: 'Debug Check',
      amount: 10,
      currency: 'GHS',
      txRef: `DEBUG-${Date.now()}`,
      redirectUrl: 'https://example.com/return',
      title: 'Debug',
    })
    hostedInit = { ok: true, gotLink: !!r.link }
  } catch (e) {
    hostedInit = { ok: false, error: e instanceof Error ? e.message : String(e) }
  }

  // Optional: real MoMo charge probe (only when explicitly asked, with a phone).
  let momo: unknown = null
  if (url.searchParams.get('momo') === '1') {
    const phone = (url.searchParams.get('phone') ?? '').trim()
    const network = NETWORK_MAP[(url.searchParams.get('network') ?? 'mtn').toLowerCase()]
    if (!phone || !network) {
      momo = { ok: false, error: 'pass ?momo=1&phone=024XXXXXXX&network=mtn|vod|atl' }
    } else {
      try {
        const charge = await chargeMobileMoneyGhana({
          txRef: `DEBUG-MOMO-${Date.now()}`,
          amount: 1,
          email: 'debug@snapwin.app',
          phone,
          network,
          fullname: 'Debug Check',
        })
        momo = { ok: true, ...charge }
      } catch (e) {
        momo = { ok: false, error: e instanceof Error ? e.message : String(e) }
      }
    }
  }

  return NextResponse.json({ env, hostedInit, momo })
}
