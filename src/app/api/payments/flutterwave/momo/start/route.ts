import { NextResponse } from 'next/server'
import { findUserById } from '@/lib/users-store'
import { recordPayment, mergePaymentMetadata } from '@/lib/payments-store'
import { chargeMobileMoneyGhana, type GhanaMomoNetwork } from '@/lib/flutterwave'
import { getMinFirstDeposit } from '@/lib/countries'

export const dynamic = 'force-dynamic'

interface StartBody {
  userId?: string
  amount?: number
  phone?: string
  /** UI network id: mtn | vod | atl */
  network?: string
  purpose?: 'deposit' | 'verification'
}

// UI network id → Flutterwave Ghana MoMo network code.
const NETWORK_MAP: Record<string, GhanaMomoNetwork> = {
  mtn: 'MTN',
  vod: 'VODAFONE',
  atl: 'AIRTELTIGO',
}

export async function POST(request: Request) {
  let body: StartBody
  try {
    body = (await request.json()) as StartBody
  } catch {
    return NextResponse.json({ error: 'invalid json' }, { status: 400 })
  }

  const userId = (body.userId ?? '').trim()
  const amount = Number(body.amount)
  const phone = (body.phone ?? '').trim()
  const network = NETWORK_MAP[(body.network ?? '').toLowerCase()]
  const purpose: 'deposit' | 'verification' =
    body.purpose === 'verification' ? 'verification' : 'deposit'

  if (!userId) return NextResponse.json({ error: 'userId required' }, { status: 400 })
  if (!Number.isFinite(amount) || amount <= 0) {
    return NextResponse.json({ error: 'amount must be > 0' }, { status: 400 })
  }
  if (!phone) return NextResponse.json({ error: 'mobile money number required' }, { status: 400 })
  if (!network) return NextResponse.json({ error: 'pick a valid network' }, { status: 400 })

  const user = await findUserById(userId)
  if (!user) return NextResponse.json({ error: 'user not found' }, { status: 404 })
  if (user.currency !== 'GHS') {
    return NextResponse.json({ error: 'mobile money is Ghana-only' }, { status: 400 })
  }

  const minDeposit = getMinFirstDeposit(user.country)
  if (amount < minDeposit) {
    return NextResponse.json(
      { error: `minimum deposit is ${user.currency} ${minDeposit.toFixed(2)}` },
      { status: 400 },
    )
  }

  const refPrefix = purpose === 'verification' ? 'FW-VRF' : 'FW-DEP'
  const txRef = `${refPrefix}-${userId.slice(0, 8)}-${Date.now()}`

  try {
    await recordPayment({
      userId,
      reference: txRef,
      amount,
      type: 'deposit',
      status: 'pending',
      provider: 'flutterwave',
      currency: user.currency,
      metadata: {
        purpose,
        flow: 'momo',
        network: body.network,
        userName: user.name,
        userPhone: phone,
        country: user.country,
      },
    })
  } catch (e) {
    console.error('[flutterwave/momo/start] pending ledger write failed:', e)
  }

  const customerEmail = user.email?.trim() || `customer+${userId}@snapwin.app`

  try {
    const charge = await chargeMobileMoneyGhana({
      txRef,
      amount,
      email: customerEmail,
      phone,
      network,
      fullname: user.name,
    })

    // OTP mode: the network texted the customer a code. Stash Flutterwave's
    // flw_ref on the pending row so /momo/otp can validate the code, and tell
    // the frontend to show its own inline OTP field (no redirect off-site).
    if (charge.mode === 'otp' && charge.flwRef) {
      await mergePaymentMetadata(txRef, { flwRef: charge.flwRef }).catch((e) =>
        console.error('[flutterwave/momo/start] flwRef stash failed:', e),
      )
      return NextResponse.json(
        { reference: txRef, status: charge.status, otpRequired: true },
        { status: 201 },
      )
    }

    return NextResponse.json(
      {
        reference: txRef,
        status: charge.status,
        // Voucher/redirect networks still hand off to Flutterwave's page;
        // otherwise the frontend polls /momo/status during the phone prompt.
        redirect: charge.redirect,
      },
      { status: 201 },
    )
  } catch (e) {
    console.error('[flutterwave/momo/start] charge failed:', e)
    return NextResponse.json(
      { error: e instanceof Error ? e.message : 'flutterwave charge failed' },
      { status: 502 },
    )
  }
}
