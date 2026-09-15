import { NextResponse } from 'next/server'
import { findUserById, recordWithdrawal, setUserPhone } from '@/lib/users-store'
import { recordPayment } from '@/lib/payments-store'
import {
  getCountry,
  isCountryCode,
  normalizePhone,
  toInternationalPhone,
} from '@/lib/countries'
import { formatMoneyWithCurrency } from '@/lib/format-money'
import { sendSms } from '@/lib/sms'
import { sendPushToUsers } from '@/lib/push'
import { PLAYER_BLOCKED_MESSAGE, userCanWithdraw } from '@/lib/can-withdraw'

export const dynamic = 'force-dynamic'

/**
 * Fire-and-forget: text the user confirming we received their withdrawal
 * request. Best-effort — any failure (no phone, SMS error, unsupported
 * country) is logged and swallowed so it never blocks the withdrawal.
 * `payoutPhone` is the number the user typed for a mobile-money payout, if any;
 * we fall back to their saved profile phone for bank-payout countries.
 */
async function notifyWithdrawalRequested(
  country: string,
  currency: string,
  amount: number,
  payoutPhone: string,
  /** Partner payouts settle on the spot, so they are told it is done rather
   *  than that it is queued for someone to look at. */
  completed = false,
): Promise<void> {
  try {
    if (!isCountryCode(country)) return
    const phone = payoutPhone || ''
    if (!phone) return
    const recipient = toInternationalPhone(country, phone)
    if (!recipient) return

    const money = formatMoneyWithCurrency(amount, currency)
    const message = completed
      ? `SnapWin: Your withdrawal of ${money} has been completed and sent to ${phone}. Thank you.`
      : `SnapWin: We've received your withdrawal request of ${money}. It's being processed and we'll notify you once it's approved.`

    const result = await sendSms(recipient, message)
    if (!result.ok) {
      console.error('[withdraw-request] SMS failed:', result.error)
    }
  } catch (e) {
    console.error('[withdraw-request] SMS notify error:', e)
  }
}

/**
 * Real OS-level notification for the withdrawing account.
 *
 * Web push, not SMS: it lands in the phone's notification centre, needs no
 * carrier sender-ID approval, and costs nothing per message. It only reaches a
 * device that has actually subscribed (Account -> alerts toggle), so a partner
 * who has never enabled notifications simply gets nothing rather than an error.
 *
 * Best-effort, like every other notify path here: a withdrawal must never fail
 * because a push did not go out.
 */
async function pushWithdrawalNotice(
  userId: string,
  currency: string,
  amount: number,
  settled: boolean,
): Promise<void> {
  try {
    const money = formatMoneyWithCurrency(amount, currency)
    await sendPushToUsers([userId], {
      title: settled ? 'Withdrawal sent' : 'Withdrawal requested',
      body: settled
        ? `${money} has been sent to your payout number.`
        : `We received your withdrawal of ${money}. You'll be notified once it's approved.`,
      url: '/account',
      tag: `withdrawal-${userId}`,
    })
  } catch (e) {
    console.error('[withdraw] push notify error:', e)
  }
}

export async function POST(request: Request) {
  let body: {
    userId?: string
    amount?: number
    network?: string
    phone?: string
    /** Bank account number for ZA/NG users (and any future bank-payout country). */
    accountNumber?: string
    /** Bank name shown to the operator processing the payout. */
    bankName?: string
  }
  try {
    body = await request.json()
  } catch {
    return NextResponse.json({ error: 'invalid json' }, { status: 400 })
  }

  const userId = (body.userId ?? '').trim()
  const amount = Number(body.amount)
  if (!userId) return NextResponse.json({ error: 'userId required' }, { status: 400 })
  if (!Number.isFinite(amount) || amount <= 0) {
    return NextResponse.json({ error: 'amount must be > 0' }, { status: 400 })
  }

  // Look up the user first so payout validation can match the country.
  const user = await findUserById(userId)
  if (!user) {
    return NextResponse.json({ error: 'user not found' }, { status: 404 })
  }

  if (!(await userCanWithdraw(user))) {
    return NextResponse.json({ error: PLAYER_BLOCKED_MESSAGE }, { status: 403 })
  }

  const cfg = getCountry(user.country)

  const network = (body.network ?? '').trim().toLowerCase()
  const validNetworks = new Set(cfg.payoutNetworks.map((n) => n.key))
  if (!validNetworks.has(network)) {
    const labels = cfg.payoutNetworks.map((n) => n.label).join(', ')
    return NextResponse.json(
      { error: `pick a payout option (${labels})` },
      { status: 400 },
    )
  }

  // Mobile-money countries (GH/KE) require a phone; bank countries (NG/ZA)
  // require an account number + bank name. Save whichever the user supplied
  // on the payment metadata so the operator can process the payout.
  let payoutMeta: Record<string, unknown> = { network }
  if (cfg.payoutTarget === 'mobile') {
    const phone = normalizePhone(user.country, body.phone ?? '')
    if (!phone) {
      return NextResponse.json(
        { error: `enter a valid ${cfg.name} mobile-money number` },
        { status: 400 },
      )
    }
    payoutMeta = { ...payoutMeta, phone }
    // Cache the canonical phone on the user for next time.
    if (phone !== user.phone) {
      await setUserPhone(userId, phone).catch(() => null)
    }
  } else {
    const accountNumber = (body.accountNumber ?? '').replace(/\s|-/g, '')
    const bankName = (body.bankName ?? '').trim()
    if (!/^\d{6,20}$/.test(accountNumber)) {
      return NextResponse.json(
        { error: 'enter a valid bank account number (digits only)' },
        { status: 400 },
      )
    }
    if (!bankName) {
      return NextResponse.json({ error: 'bank name is required' }, { status: 400 })
    }
    payoutMeta = { ...payoutMeta, accountNumber, bankName }
  }

  const result = await recordWithdrawal(userId, +amount.toFixed(2), {
    requireDeposit: false,
  })
  if ('error' in result) {
    if (result.error === 'not-found') {
      return NextResponse.json({ error: 'user not found' }, { status: 404 })
    }
    if (result.error === 'no-deposit') {
      return NextResponse.json(
        { error: 'make a deposit before withdrawing' },
        { status: 400 },
      )
    }
    return NextResponse.json({ error: 'insufficient funds' }, { status: 400 })
  }

  try {
    await recordPayment({
      userId,
      reference: `PB-WDR-${userId.slice(0, 8)}-${Date.now()}`,
      amount,
      type: 'withdrawal',
      status: 'success',
      provider: 'manual',
      currency: user.currency,
      metadata: payoutMeta,
    })
  } catch (e) {
    console.error('[withdraw] payment ledger write failed:', e)
  }

  const settledAmount = +amount.toFixed(2)
  const newBalance = result.user.balance ?? 0

  await notifyWithdrawalRequested(
    user.country,
    user.currency,
    settledAmount,
    (typeof payoutMeta.phone === 'string' && payoutMeta.phone) || user.phone || '',
    true,
  )
  await pushWithdrawalNotice(userId, user.currency, settledAmount, true)

  return NextResponse.json(
    {
      success: true,
      message: 'Withdrawal completed successfully.',
      completed: true,
      amount: settledAmount,
      new_balance: newBalance,
      currency: result.user.currency,
      user: {
        id: result.user.id,
        name: result.user.name,
        country: result.user.country,
        currency: result.user.currency,
        totalDeposited: result.user.totalDeposited,
        totalWithdrawn: result.user.totalWithdrawn ?? 0,
        balance: newBalance,
        verificationStep: result.user.verificationStep ?? 0,
      },
    },
    { status: 201 },
  )
}
