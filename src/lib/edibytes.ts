// Edibytes — Ghana mobile money, charged on our own screen.
//
// Three calls, and only the first and last are authenticated:
//   1. POST /api/payments/initialize/        -> payment id + reference
//   2. POST /api/payments/{reference}/charge/ -> pushes the prompt to the phone
//   3. GET  /api/payments/verify/{reference}/ -> poll until it settles
//
// Step 2 is the same call their hosted page makes behind its "Pay now" button,
// so sending it ourselves keeps the player on our screen instead of bouncing
// them to a checkout page and back.
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
 * dashboard. It defaults to the host we were called on, so a preview
 * deployment does not silently claim production's domain.
 */
function domainFor(callbackUrl: string): string {
  const override = process.env.EDIBYTES_DOMAIN?.trim()
  if (override) return override
  try {
    return new URL(callbackUrl).host
  } catch {
    return 'www.snapwwin.com'
  }
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
      // No phone here: any number at all makes initialize answer 502. The
      // handset number belongs to the charge call below.
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

  const charge = await fetch(`${BASE}/api/payments/${encodeURIComponent(reference)}/charge/`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ phone: localGhanaNumber(input.phone) }),
    cache: 'no-store',
  })
  const chargeJson = (await charge.json().catch(() => null)) as Record<string, unknown> | null

  if (!charge.ok) {
    const reason = reasonFrom(chargeJson)
    console.error('[edibytes] charge refused', charge.status, reason)
    if (charge.status >= 500) throw new Error('The payment service is busy. Please try again in a minute.')
    throw new Error(reason || 'Could not send the payment prompt. Check the number and try again.')
  }

  return { id: id ? String(id) : undefined, reference }
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
