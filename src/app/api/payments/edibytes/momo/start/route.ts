import { NextResponse } from 'next/server'
import { findUserById } from '@/lib/users-store'
import { recordPayment } from '@/lib/payments-store'
import { startGhanaMomo } from '@/lib/edibytes'
import { getMinFirstDeposit } from '@/lib/countries'

export const dynamic = 'force-dynamic'

interface Body {
  userId?: string
  amount?: number
  phone?: string
  network?: string // mtn | vod | atl — recorded, not sent
}

/**
 * Ghana MoMo deposit via Edibytes. Opens the payment, pushes the approval
 * prompt to the handset, and writes a pending row keyed on our reference —
 * which is the only thing the status poll needs, since Edibytes verifies by
 * reference rather than by an id of its own.
 */
export async function POST(request: Request) {
  let body: Body
  try {
    body = (await request.json()) as Body
  } catch {
    return NextResponse.json({ error: 'invalid json' }, { status: 400 })
  }

  const userId = (body.userId ?? '').trim()
  const amount = Number(body.amount)
  const phone = (body.phone ?? '').trim()
  const network = (body.network ?? '').toLowerCase()

  if (!userId) return NextResponse.json({ error: 'userId required' }, { status: 400 })
  if (!Number.isFinite(amount) || amount <= 0) {
    return NextResponse.json({ error: 'amount must be > 0' }, { status: 400 })
  }
  if (!phone) return NextResponse.json({ error: 'mobile money number required' }, { status: 400 })
  if (!['mtn', 'vod', 'atl'].includes(network)) {
    return NextResponse.json({ error: 'pick a valid network' }, { status: 400 })
  }

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

  const reference = `EDI-DEP-${userId.slice(0, 8)}-${Date.now()}`
  const email = user.email?.trim() || `customer+${userId}@snapwin.app`
  // The callback host is also the domain Edibytes checks against its
  // whitelist, so it has to be the address the player actually came in on.
  const origin = new URL(request.url).origin

  let started
  try {
    started = await startGhanaMomo({
      reference,
      amount,
      currency: user.currency,
      email,
      name: user.name || 'Customer',
      phone,
      callbackUrl: `${origin}/account`,
    })
  } catch (e) {
    console.error('[edibytes/momo/start] start failed:', e)
    return NextResponse.json(
      { error: e instanceof Error ? e.message : 'could not start your payment' },
      { status: 502 },
    )
  }

  try {
    await recordPayment({
      userId,
      reference: started.reference,
      amount,
      type: 'deposit',
      status: 'pending',
      provider: 'edibytes',
      currency: user.currency,
      metadata: {
        flow: 'momo-edibytes',
        edibytesId: started.id,
        network: body.network,
        purpose: 'deposit',
        userName: user.name,
        userPhone: phone,
      },
    })
  } catch (e) {
    console.error('[edibytes/momo/start] pending ledger write failed:', e)
  }

  return NextResponse.json({ reference: started.reference, status: 'pending' }, { status: 201 })
}
