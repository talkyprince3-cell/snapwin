import { NextResponse } from 'next/server'
import { currentSubAdmin } from '@/lib/sub-admin-session'
import {
  availableBalance,
  createRequest,
  listForSubAdmin,
  pendingTotalFor,
} from '@/lib/sub-admin-withdrawals-store'
import { isCurrencyCode, type CurrencyCode } from '@/lib/countries'
import { notifyPayoutRequested } from '@/lib/sub-admin-notify'

export const dynamic = 'force-dynamic'

/** The agent's own payout history, plus what they can still request. */
export async function GET() {
  const sa = await currentSubAdmin()
  if (!sa) return NextResponse.json({ error: 'unauthorized' }, { status: 401 })

  const withdrawals = await listForSubAdmin(sa.id)

  // Available = balance minus anything already queued, so the figure shown is
  // what they can actually ask for right now.
  const currencies = Object.keys(sa.commissionBalances).filter(isCurrencyCode) as CurrencyCode[]
  const available: Record<string, { balance: number; pending: number; requestable: number }> = {}
  for (const c of currencies) {
    const balance = availableBalance(sa, c)
    const pending = await pendingTotalFor(sa.id, c)
    available[c] = { balance, pending, requestable: +Math.max(0, balance - pending).toFixed(2) }
  }

  return NextResponse.json({ withdrawals, available })
}

export async function POST(request: Request) {
  const sa = await currentSubAdmin()
  if (!sa) return NextResponse.json({ error: 'unauthorized' }, { status: 401 })
  if (!sa.approved) {
    return NextResponse.json(
      { error: 'Your agent account is awaiting approval.' },
      { status: 403 },
    )
  }

  let body: {
    amount?: number
    currency?: string
    payoutMethod?: string
    payoutDestination?: string
  }
  try {
    body = await request.json()
  } catch {
    return NextResponse.json({ error: 'invalid json' }, { status: 400 })
  }

  const amount = Number(body.amount)
  if (!Number.isFinite(amount) || amount <= 0) {
    return NextResponse.json({ error: 'Enter an amount greater than zero.' }, { status: 400 })
  }

  const currency = (body.currency ?? 'GHS').toUpperCase()
  if (!isCurrencyCode(currency)) {
    return NextResponse.json({ error: 'Unsupported currency.' }, { status: 400 })
  }

  const payoutMethod = (body.payoutMethod ?? '').trim()
  const payoutDestination = (body.payoutDestination ?? '').trim()
  if (!payoutMethod || !payoutDestination) {
    return NextResponse.json(
      { error: 'Tell us how and where to pay you (method and number/account).' },
      { status: 400 },
    )
  }

  // Balance minus what is already queued. Checking only the raw balance would
  // let an agent stack several requests that each look affordable alone.
  const balance = availableBalance(sa, currency)
  const pending = await pendingTotalFor(sa.id, currency)
  const requestable = +Math.max(0, balance - pending).toFixed(2)
  if (amount > requestable + 0.001) {
    return NextResponse.json(
      {
        error:
          pending > 0
            ? `You can request up to ${currency} ${requestable.toFixed(2)} — ${currency} ${pending.toFixed(2)} of your ${currency} ${balance.toFixed(2)} balance is already awaiting approval.`
            : `You can request up to ${currency} ${balance.toFixed(2)}.`,
        balance,
        pending,
        requestable,
      },
      { status: 400 },
    )
  }

  const withdrawal = await createRequest({
    subAdminId: sa.id,
    amount,
    currency,
    payoutMethod,
    payoutDestination,
  })

  // Best-effort confirmation; never blocks the request being recorded.
  await notifyPayoutRequested({
    amount,
    currency,
    payoutMethod,
    payoutDestination,
  })

  return NextResponse.json(
    {
      withdrawal,
      message: 'Payout request submitted. It will show here once an admin settles it.',
    },
    { status: 201 },
  )
}
