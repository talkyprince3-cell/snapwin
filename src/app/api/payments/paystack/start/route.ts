import { NextResponse } from 'next/server'
import { findUserById } from '@/lib/users-store'
import { recordPayment } from '@/lib/payments-store'
import { getPaystackPublicKey, initialiseTransaction, toMinorUnits } from '@/lib/paystack'
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

  const refPrefix = purpose === 'verification' ? 'PB-VRF' : 'PB-DEP'
  const reference = `${refPrefix}-${userId.slice(0, 8)}-${Date.now()}`
  const callbackUrl = `${originFromRequest(request)}/api/payments/paystack/callback?returnPath=${encodeURIComponent(returnPath)}`

  try {
    await recordPayment({
      userId,
      reference,
      amount,
      type: 'deposit',
      status: 'pending',
      provider: 'paystack',
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
    console.error('[paystack/start] pending ledger write failed:', e)
  }

  // Show the real customer on the Paystack account: send their actual email.
  // Fall back to a neutral placeholder only if a user has no email on file.
  const placeholderEmail = user.email?.trim() || `customer+${userId}@snapwin.app`

  try {
    const init = await initialiseTransaction({
      email: placeholderEmail,
      amount,
      currency: user.currency,
      reference,
      callbackUrl,
      metadata: {
        userId,
        purpose,
        country: user.country,
        userName: user.name,
      },
    })
    // We return everything Inline JS needs (publicKey + amount in minor
    // units + currency + email + reference) plus the hosted-page URL as a
    // fallback for any caller that still does a full-page redirect.
    return NextResponse.json(
      {
        url: init.authorization_url,
        reference: init.reference,
        accessCode: init.access_code,
        publicKey: getPaystackPublicKey(),
        amountMinor: toMinorUnits(amount, user.currency),
        currency: user.currency,
        email: placeholderEmail,
      },
      { status: 201 },
    )
  } catch (e) {
    console.error('[paystack/start] init failed:', e)
    return NextResponse.json(
      { error: e instanceof Error ? e.message : 'paystack init failed' },
      { status: 502 },
    )
  }
}
