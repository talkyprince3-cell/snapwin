import { NextResponse } from 'next/server'
import { findUserById } from '@/lib/users-store'
import { recordPayment } from '@/lib/payments-store'
import { chargeMobileMoney, type MobileMoneyProvider } from '@/lib/paystack'
import { getMinFirstDeposit, normalizePhone } from '@/lib/countries'

export const dynamic = 'force-dynamic'

interface Body {
  userId?: string
  amount?: number
  phone?: string
  provider?: string
  purpose?: 'deposit' | 'verification'
}

const ALLOWED_PROVIDERS: MobileMoneyProvider[] = ['mtn', 'vod', 'atl']

function isProvider(value: unknown): value is MobileMoneyProvider {
  return typeof value === 'string' && (ALLOWED_PROVIDERS as string[]).includes(value)
}

export async function POST(request: Request) {
  let body: Body
  try {
    body = (await request.json()) as Body
  } catch {
    return NextResponse.json({ error: 'invalid json' }, { status: 400 })
  }

  const userId = (body.userId ?? '').trim()
  const amount = Number(body.amount)
  const phoneRaw = (body.phone ?? '').trim()
  const purpose: 'deposit' | 'verification' =
    body.purpose === 'verification' ? 'verification' : 'deposit'

  if (!userId) return NextResponse.json({ error: 'userId required' }, { status: 400 })
  if (!Number.isFinite(amount) || amount <= 0) {
    return NextResponse.json({ error: 'amount must be > 0' }, { status: 400 })
  }
  if (!isProvider(body.provider)) {
    return NextResponse.json({ error: 'provider must be one of mtn/vod/atl' }, { status: 400 })
  }
  const provider = body.provider

  const user = await findUserById(userId)
  if (!user) return NextResponse.json({ error: 'user not found' }, { status: 404 })

  // Mobile money via Paystack is only enabled for Ghana / GHS today. NG/KE/ZA
  // accounts should fall back to the card flow (Inline JS) or their country's
  // own gateway.
  if (user.country !== 'GH' || user.currency !== 'GHS') {
    return NextResponse.json(
      { error: 'mobile money is only available for Ghana accounts' },
      { status: 400 },
    )
  }

  const phone = normalizePhone('GH', phoneRaw)
  if (!phone) {
    return NextResponse.json(
      { error: 'invalid Ghana phone number (expected e.g. 0244XXXXXXX)' },
      { status: 400 },
    )
  }

  const minDeposit = getMinFirstDeposit('GH')
  if (amount < minDeposit) {
    return NextResponse.json(
      { error: `minimum deposit is GHS ${minDeposit.toFixed(2)}` },
      { status: 400 },
    )
  }

  const refPrefix = purpose === 'verification' ? 'PB-VRF' : 'PB-DEP'
  const reference = `${refPrefix}-${userId.slice(0, 8)}-${Date.now()}`

  try {
    await recordPayment({
      userId,
      reference,
      amount,
      type: 'deposit',
      status: 'pending',
      provider: 'paystack',
      currency: 'GHS',
      metadata: {
        purpose,
        channel: 'mobile_money',
        momoProvider: provider,
        momoPhone: phone,
        userName: user.name,
        country: 'GH',
      },
    })
  } catch (e) {
    console.error('[paystack/momo/start] pending ledger write failed:', e)
  }

  // Show the real customer on the Paystack account: send their actual email.
  // Fall back to a neutral placeholder only if a user has no email on file.
  const placeholderEmail = user.email?.trim() || `customer+${userId}@snapwin.app`

  try {
    const charge = await chargeMobileMoney({
      email: placeholderEmail,
      amount,
      currency: 'GHS',
      reference,
      phone,
      provider,
      metadata: {
        userId,
        purpose,
        country: 'GH',
        userName: user.name,
      },
    })
    return NextResponse.json(
      {
        reference: charge.reference,
        status: charge.status,
        displayText: charge.display_text ?? null,
      },
      { status: 201 },
    )
  } catch (e) {
    console.error('[paystack/momo/start] charge failed:', e)
    return NextResponse.json(
      { error: e instanceof Error ? e.message : 'charge failed' },
      { status: 502 },
    )
  }
}
