import { NextResponse } from 'next/server'
import { findUserById } from '@/lib/users-store'
import { recordPayment } from '@/lib/payments-store'
import { getFlutterwavePublicKey, initialisePayment } from '@/lib/flutterwave'
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
  // Prefer the host the player is actually on, so after payment we always send
  // them back to the same site (works across domains, ignores a stale
  // NEXT_PUBLIC_APP_URL). Fall back to the configured app URL, then req.url.
  const host = req.headers.get('x-forwarded-host') ?? req.headers.get('host')
  if (host) {
    const proto =
      req.headers.get('x-forwarded-proto') ?? (host.includes('localhost') ? 'http' : 'https')
    return `${proto}://${host}`
  }
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

  const refPrefix = purpose === 'verification' ? 'FW-VRF' : 'FW-DEP'
  const txRef = `${refPrefix}-${userId.slice(0, 8)}-${Date.now()}`
  const origin = originFromRequest(request)
  // Bake returnPath + our tx_ref into the redirect so the callback can credit
  // immediately, regardless of what query params Flutterwave appends.
  const redirectUrl = `${origin}/api/payments/flutterwave/callback?returnPath=${encodeURIComponent(returnPath)}&tx_ref=${encodeURIComponent(txRef)}`

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
        returnPath,
        userName: user.name,
        userPhone: user.phone ?? null,
        country: user.country,
      },
    })
  } catch (e) {
    console.error('[flutterwave/start] pending ledger write failed:', e)
  }

  // Show the real customer on the Flutterwave account; fall back to a neutral
  // placeholder only if a user has no email on file.
  const customerEmail = user.email?.trim() || `customer+${userId}@snapwin.app`

  // Ghana → open the checkout straight on Mobile Money. Nigeria has no
  // Ghana-style MoMo, so leave all methods (card / bank transfer / USSD).
  const paymentOptions = user.currency === 'GHS' ? 'mobilemoneyghana' : undefined

  try {
    const init = await initialisePayment({
      email: customerEmail,
      name: user.name,
      phone: user.phone,
      amount,
      currency: user.currency,
      txRef,
      redirectUrl,
      title: purpose === 'verification' ? 'Account verification' : 'Deposit',
      paymentOptions,
      meta: { userId, purpose, country: user.country },
    })
    return NextResponse.json(
      {
        url: init.link,
        reference: txRef,
        publicKey: getFlutterwavePublicKey(),
        amount,
        currency: user.currency,
        email: customerEmail,
      },
      { status: 201 },
    )
  } catch (e) {
    console.error('[flutterwave/start] init failed:', e)
    return NextResponse.json(
      { error: e instanceof Error ? e.message : 'flutterwave init failed' },
      { status: 502 },
    )
  }
}
