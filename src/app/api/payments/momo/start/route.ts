import { NextResponse } from 'next/server'
import { findUserById } from '@/lib/users-store'
import { recordPayment } from '@/lib/payments-store'
import {
  isMomoConfigured,
  momoCurrency,
  newReferenceId,
  requestToPay,
  toMsisdn,
} from '@/lib/momo'
import { getCountry, getMinFirstDeposit, normalizePhone } from '@/lib/countries'

export const dynamic = 'force-dynamic'

interface Body {
  userId?: string
  amount?: number
  phone?: string
  purpose?: 'deposit' | 'verification'
}

export async function POST(request: Request) {
  if (!isMomoConfigured()) {
    return NextResponse.json(
      { error: 'MoMo not configured — set MOMO_SUBSCRIPTION_KEY / MOMO_API_USER / MOMO_API_KEY' },
      { status: 503 },
    )
  }

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

  const user = await findUserById(userId)
  if (!user) return NextResponse.json({ error: 'user not found' }, { status: 404 })

  const cfg = getCountry(user.country)
  // Normalise / validate the payer phone against the user's country, then turn
  // it into an MTN MSISDN (e.g. 233244XXXXXXX).
  const localPhone = normalizePhone(user.country, phoneRaw)
  if (!localPhone) {
    return NextResponse.json(
      { error: `invalid ${cfg.name} phone number` },
      { status: 400 },
    )
  }
  const msisdn = toMsisdn(localPhone, cfg.dialCode)

  const minDeposit = getMinFirstDeposit(user.country)
  if (amount < minDeposit) {
    return NextResponse.json(
      { error: `minimum deposit is ${user.currency} ${minDeposit.toFixed(2)}` },
      { status: 400 },
    )
  }

  // The MoMo X-Reference-Id is our canonical payment reference + poll key.
  const reference = newReferenceId()
  const externalId = `${purpose === 'verification' ? 'PB-VRF' : 'PB-DEP'}-${userId.slice(0, 8)}-${Date.now()}`

  try {
    await recordPayment({
      userId,
      reference,
      amount,
      type: 'deposit',
      status: 'pending',
      provider: 'momo',
      currency: user.currency,
      metadata: {
        purpose,
        channel: 'mobile_money',
        momoProvider: 'mtn',
        momoPhone: localPhone,
        msisdn,
        externalId,
        userName: user.name,
        country: user.country,
      },
    })
  } catch (e) {
    console.error('[momo/start] pending ledger write failed:', e)
  }

  try {
    await requestToPay({
      referenceId: reference,
      amount,
      // Sandbox only accepts EUR; production uses the wallet currency. The
      // pending ledger row above keeps the real currency for accounting.
      currency: momoCurrency(user.currency),
      msisdn,
      externalId,
      payerMessage: 'SnapWin deposit',
      payeeNote: `Deposit ${user.currency} ${amount}`,
    })
  } catch (e) {
    console.error('[momo/start] requesttopay failed:', e)
    return NextResponse.json(
      { error: e instanceof Error ? e.message : 'could not start payment' },
      { status: 502 },
    )
  }

  return NextResponse.json(
    {
      reference,
      status: 'pending',
      displayText: 'Approve the payment prompt on your phone to complete the deposit.',
    },
    { status: 201 },
  )
}
