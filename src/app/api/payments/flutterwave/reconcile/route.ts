import { NextResponse } from 'next/server'
import { listPaymentsForUser } from '@/lib/payments-store'
import { verifyAndCreditFlutterwaveAny } from '@/lib/flutterwave-verify'

export const dynamic = 'force-dynamic'

/**
 * Safety net: re-check the user's recent pending Flutterwave deposits and credit
 * any that settled while they were away (redirect never fired / webhook missed).
 * Called on account-page load. Both V3 and V4 rows carry provider
 * 'flutterwave', so each reference is dispatched to the API that issued it.
 * Idempotent — the verifiers guard against double-credit.
 */
export async function POST(request: Request) {
  let body: { userId?: string }
  try {
    body = (await request.json()) as { userId?: string }
  } catch {
    return NextResponse.json({ error: 'invalid json' }, { status: 400 })
  }
  const userId = (body.userId ?? '').trim()
  if (!userId) return NextResponse.json({ error: 'userId required' }, { status: 400 })

  let payments
  try {
    payments = await listPaymentsForUser(userId)
  } catch (e) {
    console.error('[flutterwave/reconcile] list failed:', e)
    return NextResponse.json({ credited: 0, checked: 0 })
  }

  const cutoff = Date.now() - 2 * 60 * 60 * 1000
  const pending = payments.filter(
    (p) =>
      p.type === 'deposit' &&
      p.provider === 'flutterwave' &&
      p.status === 'pending' &&
      new Date(p.createdAt).getTime() >= cutoff,
  )

  let credited = 0
  for (const p of pending) {
    try {
      const r = await verifyAndCreditFlutterwaveAny(p.reference)
      if (r.status === 'success' || r.status === 'already-credited') credited++
    } catch {
      /* skip; will retry on next load */
    }
  }

  return NextResponse.json({ credited, checked: pending.length })
}
