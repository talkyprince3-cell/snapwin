import { NextResponse } from 'next/server'
import { listPaymentsForUser } from '@/lib/payments-store'
import { verifyAndCreditEdibytes } from '@/lib/edibytes-credit'

export const dynamic = 'force-dynamic'

/**
 * Safety net: re-check the user's recent pending Edibytes deposits and credit
 * any that settled while they were away. Called on account-page load.
 *
 * The deposit modal polls for about three minutes and then gives up, which is
 * fine while the player is watching but not otherwise: a prompt approved after
 * the modal closed, or on a phone whose browser was backgrounded, leaves a
 * payment Edibytes calls successful against a row we still call pending. Money
 * left our player and never reached their balance. This is what closes that
 * window.
 *
 * Idempotent — verifyAndCreditEdibytes guards against double-credit.
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
    console.error('[edibytes/reconcile] list failed:', e)
    return NextResponse.json({ credited: 0, checked: 0 })
  }

  // A day, rather than the two hours the Flutterwave sweep uses: a mobile
  // money prompt can sit unapproved on a handset for a long time, and a row
  // that settles late is exactly the one this exists to catch.
  const cutoff = Date.now() - 24 * 60 * 60 * 1000
  const pending = payments.filter(
    (p) =>
      p.type === 'deposit' &&
      p.provider === 'edibytes' &&
      p.status === 'pending' &&
      new Date(p.createdAt).getTime() >= cutoff,
  )

  let credited = 0
  for (const p of pending) {
    try {
      const r = await verifyAndCreditEdibytes(p.reference)
      if (r.status === 'success' || r.status === 'already-credited') credited++
    } catch {
      /* skip; will retry on next load */
    }
  }

  return NextResponse.json({ credited, checked: pending.length })
}
