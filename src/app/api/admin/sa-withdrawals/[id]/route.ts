import { NextResponse } from 'next/server'
import { cookies } from 'next/headers'
import { ADMIN_COOKIE, isValidSessionCookie } from '@/lib/admin-auth'
import { approve, reject } from '@/lib/sub-admin-withdrawals-store'
import { notifyPayoutApproved, notifyPayoutRejected } from '@/lib/sub-admin-notify'

export const dynamic = 'force-dynamic'

interface Params {
  params: Promise<{ id: string }>
}

/**
 * Settle one agent payout request.
 *
 * PATCH { action: 'approve' | 'reject', ... }
 *
 * Approving deducts the agent's commission balance and records how it was
 * paid; rejecting refunds only if the balance had already been taken. Both
 * refuse anything that is no longer pending, so a double submit cannot pay
 * twice.
 */
export async function PATCH(request: Request, { params }: Params) {
  const token = (await cookies()).get(ADMIN_COOKIE)?.value
  if (!(await isValidSessionCookie(token))) {
    return NextResponse.json({ error: 'unauthorized' }, { status: 401 })
  }

  const { id } = await params

  let body: {
    action?: string
    paymentMethod?: string
    paymentReference?: string
    paymentNote?: string
  }
  try {
    body = await request.json()
  } catch {
    return NextResponse.json({ error: 'invalid json' }, { status: 400 })
  }

  if (body.action === 'approve') {
    const result = await approve(id, {
      paymentMethod: (body.paymentMethod ?? '').trim() || undefined,
      paymentReference: (body.paymentReference ?? '').trim() || undefined,
      paymentNote: (body.paymentNote ?? '').trim() || undefined,
    })
    if (!result.ok) {
      const code = result.reason === 'not-found' ? 404 : result.reason === 'insufficient' ? 400 : 409
      return NextResponse.json({ error: result.message }, { status: code })
    }
    await notifyPayoutApproved({
      amount: result.withdrawal.amount,
      currency: result.withdrawal.currency,
      payoutMethod: result.withdrawal.payoutMethod,
      payoutDestination: result.withdrawal.payoutDestination,
      paymentReference: result.withdrawal.paymentReference,
    })
    return NextResponse.json({ withdrawal: result.withdrawal })
  }

  if (body.action === 'reject') {
    const result = await reject(id, (body.paymentNote ?? '').trim() || undefined)
    if (!result.ok) {
      const code = result.reason === 'not-found' ? 404 : 409
      return NextResponse.json({ error: result.message }, { status: code })
    }
    await notifyPayoutRejected({
      amount: result.withdrawal.amount,
      currency: result.withdrawal.currency,
      payoutMethod: result.withdrawal.payoutMethod,
      payoutDestination: result.withdrawal.payoutDestination,
      reason: result.withdrawal.paymentNote,
    })
    return NextResponse.json({ withdrawal: result.withdrawal })
  }

  return NextResponse.json({ error: "action must be 'approve' or 'reject'" }, { status: 400 })
}
