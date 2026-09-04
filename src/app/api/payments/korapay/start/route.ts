import { NextResponse } from 'next/server'
import { findUserById } from '@/lib/users-store'
import { recordPayment } from '@/lib/payments-store'
import { getKorapayPublicKey, initialiseCharge } from '@/lib/korapay'
import { getMinFirstDeposit } from '@/lib/countries'

export const dynamic = 'force-dynamic'

interface StartBody {
  userId?: string
  amount?: number
  returnPath?: string
  purpose?: 'deposit' | 'verification'
}

function sanitizeReturnPath(raw: string | undefined): string {
  if (!raw || !raw.startsWith('/') || raw.startsWith('//')) return '/me'
  return raw
}

function originFromRequest(req: Request): string {
  const explicit = process.env.NEXT_PUBLIC_APP_URL?.trim()
  if (explicit) return explicit.replace(/\/$/, '')
  const url = new URL(req.url)
  return `${url.protocol}//${url.host}`
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
  const purpose: 'deposit' | 'verification' =
    body.purpose === 'verification' ? 'verification' : 'deposit'
  const returnPath = sanitizeReturnPath(body.returnPath)

  if (!userId) return NextResponse.json({ error: 'userId required' }, { status: 400 })
  if (!Number.isFinite(amount) || amount <= 0) {
    return NextResponse.json({ error: 'amount must be > 0' }, { status: 400 })
  }

  const user = await findUserById(userId)
  if (!user) return NextResponse.json({ error: 'user not found' }, { status: 404 })

  const minDeposit = getMinFirstDeposit(user.country)
  if (amount < minDeposit) {
    return NextResponse.json(
      { error: `minimum deposit is ${user.currency} ${minDeposit.toFixed(2)}` },
      { status: 400 },
    )
  }

  const refPrefix = purpose === 'verification' ? 'KR-VRF' : 'KR-DEP'
  const reference = `${refPrefix}-${userId.slice(0, 8)}-${Date.now()}`
  const origin = originFromRequest(request)
  // Bake our reference into the redirect URL so the callback can credit
  // immediately on return, even if Korapay doesn't append its own ?reference.
  // URLSearchParams.get() returns the first value, so ours wins regardless.
  const redirectUrl = `${origin}/api/payments/korapay/callback?returnPath=${encodeURIComponent(returnPath)}&reference=${encodeURIComponent(reference)}`
  const notificationUrl = `${origin}/api/payments/korapay/webhook`

  try {
    await recordPayment({
      userId,
      reference,
      amount,
      type: 'deposit',
      status: 'pending',
      provider: 'korapay',
      currency: user.currency,
      metadata: {
        purpose,
        returnPath,
        userName: user.name,
        userPhone: user.phone ?? null,
        country: user.country,
      },
    })
  } catch (e) {
    console.error('[korapay/start] pending ledger write failed:', e)
  }

  // Show the real customer on the Korapay account: send their actual email and
  // name. Fall back to a neutral placeholder only if a user somehow has no
  // email on file (every account normally has one).
  const customerEmail = user.email?.trim() || `customer+${userId}@snapwin.app`

  try {
    const init = await initialiseCharge({
      email: customerEmail,
      name: user.name,
      amount,
      currency: user.currency,
      country: user.country,
      reference,
      redirectUrl,
      notificationUrl,
      narration: purpose === 'verification' ? 'Account verification' : 'Deposit',
      metadata: {
        userId,
        purpose,
        country: user.country,
        userName: user.name,
      },
    })
    return NextResponse.json(
      {
        url: init.checkout_url,
        reference: init.reference,
        publicKey: getKorapayPublicKey(user.country),
        amount,
        currency: user.currency,
        email: customerEmail,
      },
      { status: 201 },
    )
  } catch (e) {
    console.error('[korapay/start] init failed:', e)
    return NextResponse.json(
      { error: e instanceof Error ? e.message : 'korapay init failed' },
      { status: 502 },
    )
  }
}
