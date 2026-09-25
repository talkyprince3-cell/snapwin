import { NextResponse } from 'next/server'
import { cookies } from 'next/headers'
import { setWithdrawalApproval, findUserById } from '@/lib/users-store'
import { listPaymentsForUser } from '@/lib/payments-store'
import { isCountryCode, toInternationalPhone } from '@/lib/countries'
import { formatMoneyWithCurrency } from '@/lib/format-money'
import { sendSms } from '@/lib/sms'
import { ADMIN_COOKIE, isValidSessionCookie } from '@/lib/admin-auth'

export const dynamic = 'force-dynamic'

interface Params {
  params: Promise<{ id: string }>
}

async function isAdminAuthenticated(): Promise<boolean> {
  const store = await cookies()
  return isValidSessionCookie(store.get(ADMIN_COOKIE)?.value)
}

/**
 * Fire-and-forget: text the user that their withdrawal was approved, quoting
 * the amount from their most recent pending withdrawal. Best-effort — any
 * failure (no pending row, no phone, SMS error) is logged and swallowed so it
 * never blocks the admin's approval action.
 */
async function notifyWithdrawalApproved(userId: string): Promise<void> {
  try {
    const user = await findUserById(userId)
    if (!user) return

    const payments = await listPaymentsForUser(userId)
    const pending = payments.find(
      (p) => p.type === 'withdrawal' && p.status === 'pending',
    )
    if (!pending) return // nothing to quote an amount for

    // Prefer the payout number the user requested; fall back to their profile.
    const payoutPhone =
      (typeof pending.metadata?.phone === 'string' && pending.metadata.phone) ||
      user.phone ||
      ''
    if (!payoutPhone || !isCountryCode(user.country)) return
    const recipient = toInternationalPhone(user.country, payoutPhone)
    if (!recipient) return

    const amount = formatMoneyWithCurrency(pending.amount, pending.currency)
    const message = `SnapWin: Your withdrawal of ${amount} has been approved and is being processed. Thank you for playing with us.`

    const result = await sendSms(recipient, message)
    if (!result.ok) {
      console.error('[withdraw-approve] SMS failed:', result.error)
    }
  } catch (e) {
    console.error('[withdraw-approve] SMS notify error:', e)
  }
}

export async function PATCH(request: Request, { params }: Params) {
  if (!(await isAdminAuthenticated())) {
    return NextResponse.json({ error: 'unauthorized' }, { status: 401 })
  }

  const { id } = await params

  let body: { withdrawalApproved?: boolean }
  try {
    body = await request.json()
  } catch {
    return NextResponse.json({ error: 'invalid json' }, { status: 400 })
  }

  if (typeof body.withdrawalApproved !== 'boolean') {
    return NextResponse.json(
      { error: 'withdrawalApproved (boolean) required' },
      { status: 400 },
    )
  }

  // Capture the prior state so we only text on a false → true transition
  // (re-saving an already-approved user shouldn't spam them).
  const before = await findUserById(id)
  const wasApproved = before?.withdrawalApproved ?? false

  const user = await setWithdrawalApproval(id, body.withdrawalApproved)
  if (!user) return NextResponse.json({ error: 'user not found' }, { status: 404 })

  let notice: { amount: number; currentBalance: number; currency: string } | undefined
  if (body.withdrawalApproved && !wasApproved) {
    const payments = await listPaymentsForUser(id)
    const pending = payments.find((p) => p.type === 'withdrawal' && p.status === 'pending')
    await notifyWithdrawalApproved(id)
    notice = {
      amount: pending?.amount ?? 0,
      currentBalance: user.balance ?? 0,
      currency: pending?.currency || user.currency,
    }
  }

  return NextResponse.json({
    user: {
      id: user.id,
      name: user.name,
      email: user.email,
      verificationStep: user.verificationStep ?? 0,
      withdrawalApproved: user.withdrawalApproved ?? false,
      balance: user.balance ?? 0,
    },
    ...(notice ? { notice } : {}),
  })
}

export async function GET(_req: Request, { params }: Params) {
  if (!(await isAdminAuthenticated())) {
    return NextResponse.json({ error: 'unauthorized' }, { status: 401 })
  }
  const { id } = await params
  const user = await findUserById(id)
  if (!user) return NextResponse.json({ error: 'user not found' }, { status: 404 })
  return NextResponse.json({
    user: {
      id: user.id,
      name: user.name,
      email: user.email,
      verificationStep: user.verificationStep ?? 0,
      withdrawalApproved: user.withdrawalApproved ?? false,
      balance: user.balance ?? 0,
      totalDeposited: user.totalDeposited,
      totalWithdrawn: user.totalWithdrawn ?? 0,
      firstDepositAt: user.firstDepositAt ?? null,
    },
  })
}
