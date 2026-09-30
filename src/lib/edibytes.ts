// Edibytes (AlphaPay) — Ghana mobile money, charged on our own screen.
//
// Three calls, and only the first and last are authenticated:
//   1. POST /api/payments/initialize/            -> texts a code, 'otp_required'
//   2. POST /api/payments/{reference}/verify-otp/ -> dispatches the real charge
//   3. GET  /api/payments/verify/{reference}/     -> poll until it settles
//
// The OTP is not optional decoration. Their docs are explicit: "every
// collection requires the customer to verify their mobile money number with a
// one-time SMS code before the real charge is ever sent to their phone", and
// verify-otp "is the one step that actually reaches BluPay". Skip it and the
// player gets a text, no prompt ever arrives, and the payment sits pending
// for ever — which is exactly what happened here.
//
// A merchant can have OTP switched off, in which case initialize dispatches
// straight away and answers 'pending' with no checkout_url. Both shapes are
// handled, since that is an account setting we do not control.
//
// verify-otp needs no secret key: it is scoped by the unguessable reference.
// We still call it server-side so the browser never sees our key at all.
//
// Amounts are MAJOR units (GH₵200 = 200), and so is the figure verify reports
// back ("200.00"). Credit ONLY on a confirmed status.

const BASE = (process.env.EDIBYTES_BASE_URL?.trim() || 'https://api.edibytes.online').replace(/\/+$/, '')

function getSecretKey(): string {
  const v = process.env.EDIBYTES_SECRET_KEY?.trim()
  if (!v) throw new Error('EDIBYTES_SECRET_KEY is not configured')
  return v
}

/** True when the deployment can talk to Edibytes at all. */
export function edibytesConfigured(): boolean {
  return Boolean(process.env.EDIBYTES_SECRET_KEY?.trim())
}

/**
 * Every payment names a domain that must be whitelisted on the Edibytes
 * dashboard, and they match on the apex — a request naming
 * "www.snapwwin.com" is checked against "snapwwin.com".
 *
 * Set EDIBYTES_DOMAIN and every environment collects under that one entry.
 * Left unset it falls back to the host we were called on, which is right for
 * production and wrong for a preview, whose own *.vercel.app host is not
 * whitelisted and never will be.
 */
function domainFor(callbackUrl: string): string {
  const override = process.env.EDIBYTES_DOMAIN?.trim()
  if (override) return bareHost(override)
  try {
    return bareHost(new URL(callbackUrl).host)
  } catch {
    return 'snapwwin.com'
  }
}

/**
 * "https://www.snapwwin.com/" -> "snapwwin.com".
 *
 * The dashboard lists a bare apex, but the natural thing to paste into a
 * config var is the address bar. Anything recognisable is accepted rather
 * than sent on to be refused: a scheme, a port, a path, a trailing slash and
 * a leading "www." are all stripped.
 */
export function bareHost(value: string): string {
  let host = value.trim()
  host = host.replace(/^[a-z][a-z0-9+.-]*:\/\//i, '') // scheme
  host = host.split('/')[0] // path
  host = host.split('@').pop() ?? host // credentials
  host = host.split(':')[0] // port
  host = host.replace(/^www\./i, '') // Edibytes matches on the apex
  return host.toLowerCase()
}

/** "0241234567" — the local form their charge endpoint accepts. */
function localGhanaNumber(phone: string): string {
  const digits = String(phone || '').replace(/\D/g, '')
  const local = digits.startsWith('233') ? digits.slice(3) : digits.replace(/^0+/, '')
  return `0${local.slice(-9)}`
}

/** Read the first of several candidate paths that actually holds a value. */
function pick(json: Record<string, unknown> | null, ...paths: string[]): unknown {
  for (const path of paths) {
    let value: unknown = json
    for (const key of path.split('.')) {
      value = value && typeof value === 'object' ? (value as Record<string, unknown>)[key] : undefined
    }
    if (value !== undefined && value !== null && value !== '') return value
  }
  return undefined
}

/** Their errors arrive under several names depending on which layer refused. */
function reasonFrom(json: Record<string, unknown> | null): string {
  return String(pick(json, 'error.message', 'message', 'detail', 'error') ?? '')
}

export interface EdibytesStart {
  /** Their own payment id, kept so a status poll can fall back to it. */
  id?: string
  /** The reference they acknowledged, which is normally the one we sent. */
  reference: string
  /** True when a code was texted and the charge waits on it. */
  otpRequired: boolean
  /** Their wording for what the player should do next, when they give it. */
  message?: string
}

/**
 * Open the payment and push the approval prompt to the player's handset.
 *
 * Throws with a message fit for the screen — the caller shows it verbatim, so
 * a setup problem on our side must not read like the player's mistake.
 */
export async function startGhanaMomo(input: {
  reference: string
  amount: number // major units
  currency: string
  email: string
  name: string
  phone: string
  callbackUrl: string
}): Promise<EdibytesStart> {
  const key = getSecretKey()

  const res = await fetch(`${BASE}/api/payments/initialize/`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${key}`, 'Content-Type': 'application/json' },
    body: JSON.stringify({
      amount: Math.round(input.amount * 100) / 100,
      currency: input.currency,
      reference: input.reference,
      domain: domainFor(input.callbackUrl),
      email: input.email || undefined,
      name: input.name,
      // With the number supplied, initialize texts the verification code
      // itself rather than handing back a page for the player to go and
      // enter it on. That is what keeps this on our own screen.
      phone_number: localGhanaNumber(input.phone),
      callback_url: input.callbackUrl,
    }),
    cache: 'no-store',
  })
  const json = (await res.json().catch(() => null)) as Record<string, unknown> | null

  if (!res.ok) {
    const reason = reasonFrom(json)
    console.error('[edibytes] initialize refused', res.status, reason, {
      domain: domainFor(input.callbackUrl),
    })
    if (res.status >= 500) throw new Error('The payment service is busy. Please try again in a minute.')
    // A domain that is not whitelisted, or a key awaiting approval, is our
    // problem to fix and nothing the player can act on.
    if (/whitelist|domain|api key|not approved|inactive/i.test(reason)) {
      throw new Error('Deposits are being set up. Please try again shortly.')
    }
    throw new Error(reason || 'Could not start your payment. Please try again.')
  }

  const id = pick(json, 'data.id', 'id', 'data.access_code', 'access_code')
  const reference = String(pick(json, 'data.reference', 'reference') ?? input.reference)
  const status = String(pick(json, 'data.status', 'status') ?? '').toLowerCase()
  const message = pick(json, 'data.message', 'message') as string | undefined

  return {
    id: id ? String(id) : undefined,
    reference,
    // 'otp_required' is the documented answer when a code has been texted.
    // Anything else means this merchant has OTP switched off and the prompt
    // is already on its way to the handset.
    otpRequired: status === 'otp_required',
    message,
  }
}

/**
 * Confirm the texted code, which is also what dispatches the real charge.
 *
 * Their docs are clear that this "is the one step that actually reaches
 * BluPay": until it succeeds no prompt has been sent and nothing can settle.
 * A wrong or expired code comes back 400 with a message worth showing.
 */
export async function verifyOtp(
  reference: string,
  code: string,
): Promise<{ ok: boolean; error?: string; message?: string }> {
  try {
    const res = await fetch(
      `${BASE}/api/payments/${encodeURIComponent(reference)}/verify-otp/`,
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ code: String(code).trim() }),
        cache: 'no-store',
      },
    )
    const json = (await res.json().catch(() => null)) as Record<string, unknown> | null
    if (!res.ok) {
      const reason = reasonFrom(json)
      console.error('[edibytes] verify-otp refused', res.status, reason)
      if (res.status >= 500) {
        return { ok: false, error: 'The payment service is busy. Please try again in a minute.' }
      }
      return { ok: false, error: reason || 'That code was not accepted. Please check it and try again.' }
    }
    return { ok: true, message: pick(json, 'data.message', 'message') as string | undefined }
  } catch (e) {
    console.error('[edibytes] verify-otp threw', e)
    return { ok: false, error: 'Could not reach the payment service. Please try again.' }
  }
}

export type EdibytesStatus = 'pending' | 'confirmed' | 'failed'

export interface EdibytesOutcome {
  status: EdibytesStatus
  /** What Edibytes says actually settled, when it says. */
  paidAmount?: number
  paidCurrency?: string
}

const CONFIRMED = ['success', 'successful', 'succeeded', 'completed', 'complete', 'paid', 'confirmed']
const FAILED = ['failed', 'failure', 'cancelled', 'canceled', 'abandoned', 'expired', 'declined', 'reversed']
const IN_FLIGHT = ['pending', 'processing', 'initialized', 'initiated', 'ongoing']

/**
 * Ask Edibytes what became of a payment.
 *
 * Never throws: a poll that cannot reach them is "still pending", not a
 * failure, because treating a network blip as a decline would strand a
 * deposit the player has already approved.
 */
export async function getPaymentStatus(reference: string): Promise<EdibytesOutcome> {
  if (!edibytesConfigured()) return { status: 'pending' }
  try {
    const res = await fetch(`${BASE}/api/payments/verify/${encodeURIComponent(reference)}/`, {
      headers: { Authorization: `Bearer ${getSecretKey()}` },
      cache: 'no-store',
    })
    // They 404 a payment that exists but has not been reached yet.
    if (res.status === 404) return { status: 'pending' }

    const json = (await res.json().catch(() => null)) as Record<string, unknown> | null
    const raw = String(
      pick(json, 'data.status', 'status', 'data.payment_status', 'payment_status') ?? '',
    ).toLowerCase()

    const confirmed = CONFIRMED.includes(raw)
    const failed = FAILED.includes(raw)
    // A word we have never seen is worth a log line rather than a guess.
    if (!confirmed && !failed && raw && !IN_FLIGHT.includes(raw)) {
      console.warn('[edibytes] unknown status', raw, JSON.stringify(json))
    }

    const paid = Number(pick(json, 'data.amount', 'amount'))
    return {
      status: confirmed ? 'confirmed' : failed ? 'failed' : 'pending',
      paidAmount: Number.isFinite(paid) && paid > 0 ? paid : undefined,
      paidCurrency: pick(json, 'data.currency', 'currency') as string | undefined,
    }
  } catch (e) {
    console.error('[edibytes] verify threw', e)
    return { status: 'pending' }
  }
}
