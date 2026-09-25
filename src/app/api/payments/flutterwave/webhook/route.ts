import { NextResponse } from 'next/server'
import { verifyWebhookHash } from '@/lib/flutterwave'
import { verifyAndCreditFlutterwaveAny } from '@/lib/flutterwave-verify'

export const dynamic = 'force-dynamic'

// Flutterwave webhook. Authenticity is the static secret hash you set in the
// dashboard, sent in the `verif-hash` header. Body: { event, data: { tx_ref, … } }.
// V4 names the same field `reference`, so read either — otherwise a V4 deposit
// whose customer closed the tab would never be credited from here.
// We re-verify the transaction against the API rather than trusting the body,
// and always ack 200 on a valid hash so Flutterwave doesn't retry-storm us.
export async function POST(request: Request) {
  const secret = process.env.FLUTTERWAVE_SECRET_HASH?.trim()
  if (!secret) {
    return NextResponse.json({ ok: true, reason: 'webhook-disabled' })
  }

  if (!verifyWebhookHash(request.headers.get('verif-hash'))) {
    console.warn('[flutterwave/webhook] verif-hash mismatch — rejecting')
    return NextResponse.json({ error: 'invalid signature' }, { status: 401 })
  }

  let body: { event?: string; data?: { tx_ref?: string; reference?: string } }
  try {
    body = (await request.json()) as typeof body
  } catch {
    return NextResponse.json({ error: 'invalid json' }, { status: 400 })
  }

  const txRef = body.data?.tx_ref ?? body.data?.reference
  if (!txRef) {
    return NextResponse.json({ ok: true, reason: 'no-reference' })
  }
  // Only charge events lead to a credit; ignore transfers/refunds quietly.
  if (body.event && !body.event.startsWith('charge')) {
    return NextResponse.json({ ok: true, reason: `ignored:${body.event}` })
  }

  const result = await verifyAndCreditFlutterwaveAny(txRef)
  return NextResponse.json({ ok: result.ok, reason: result.status })
}
