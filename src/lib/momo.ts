// MTN MoMo Collections — "Request to Pay" deposit integration.
//
// Flow (https://momodeveloper.mtn.com, Collection product):
//   1. POST /collection/token/                  → OAuth access token
//   2. POST /collection/v1_0/requesttopay       → kick off a debit prompt on
//      the payer's phone (202 Accepted, no body)
//   3. GET  /collection/v1_0/requesttopay/{id}  → poll PENDING/SUCCESSFUL/FAILED
//
// Credentials (set in env — production MTN Ghana):
//   MOMO_SUBSCRIPTION_KEY   Ocp-Apim-Subscription-Key for the Collection product
//   MOMO_API_USER           Collection API User (UUID)
//   MOMO_API_KEY            Collection API Key for that user
//   MOMO_TARGET_ENVIRONMENT defaults to 'mtnghana'
//   MOMO_BASE_URL           defaults to the production proxy
//   MOMO_CALLBACK_URL       optional X-Callback-Url for async result POSTs

import { randomUUID } from 'crypto'

const BASE =
  process.env.MOMO_BASE_URL?.trim().replace(/\/$/, '') ||
  'https://proxy.momoapi.mtn.com'
const TARGET_ENV = process.env.MOMO_TARGET_ENVIRONMENT?.trim() || 'mtnghana'

interface MomoConfig {
  subscriptionKey: string
  apiUser: string
  apiKey: string
}

/** Returns the configured credentials or throws a clear, actionable error. */
function requireConfig(): MomoConfig {
  const subscriptionKey = process.env.MOMO_SUBSCRIPTION_KEY?.trim()
  const apiUser = process.env.MOMO_API_USER?.trim()
  const apiKey = process.env.MOMO_API_KEY?.trim()
  if (!subscriptionKey || !apiUser || !apiKey) {
    throw new Error(
      'MoMo not configured — set MOMO_SUBSCRIPTION_KEY, MOMO_API_USER and MOMO_API_KEY',
    )
  }
  return { subscriptionKey, apiUser, apiKey }
}

export function isMomoConfigured(): boolean {
  return Boolean(
    process.env.MOMO_SUBSCRIPTION_KEY &&
      process.env.MOMO_API_USER &&
      process.env.MOMO_API_KEY,
  )
}

/**
 * The currency to send to MoMo. The sandbox environment ONLY accepts EUR
 * (it rejects GHS with INVALID_CURRENCY), so in sandbox we send EUR while the
 * ledger keeps the real wallet currency. Production uses the wallet currency.
 */
export function momoCurrency(walletCurrency: string): string {
  return TARGET_ENV === 'sandbox' ? 'EUR' : walletCurrency
}

export function isSandbox(): boolean {
  return TARGET_ENV === 'sandbox'
}

// ── Access token (cached in-memory until ~1 min before expiry) ───────────────
let cachedToken: { value: string; expiresAt: number } | null = null

async function getAccessToken(cfg: MomoConfig): Promise<string> {
  const now = Date.now()
  if (cachedToken && cachedToken.expiresAt > now + 60_000) {
    return cachedToken.value
  }
  const basic = Buffer.from(`${cfg.apiUser}:${cfg.apiKey}`).toString('base64')
  const res = await fetch(`${BASE}/collection/token/`, {
    method: 'POST',
    headers: {
      Authorization: `Basic ${basic}`,
      'Ocp-Apim-Subscription-Key': cfg.subscriptionKey,
    },
    cache: 'no-store',
  })
  if (!res.ok) {
    const body = await res.text().catch(() => '')
    throw new Error(`MoMo token failed (${res.status}): ${body.slice(0, 200)}`)
  }
  const json = (await res.json()) as { access_token?: string; expires_in?: number }
  if (!json.access_token) throw new Error('MoMo token response missing access_token')
  cachedToken = {
    value: json.access_token,
    expiresAt: now + (json.expires_in ?? 3600) * 1000,
  }
  return json.access_token
}

/**
 * Convert a locally-stored phone (e.g. "0244XXXXXXX") to an MTN MSISDN
 * ("233244XXXXXXX") using the country dial code. Accepts already-international
 * inputs too.
 */
export function toMsisdn(phone: string, dialCode: string): string {
  let p = phone.replace(/[\s\-+()]/g, '')
  if (p.startsWith('00')) p = p.slice(2)
  if (p.startsWith(dialCode)) return p
  if (p.startsWith('0')) p = p.slice(1)
  return `${dialCode}${p}`
}

export interface RequestToPayInput {
  referenceId: string // our X-Reference-Id (UUID) — also the poll key
  amount: number
  currency: string
  msisdn: string
  externalId: string
  payerMessage?: string
  payeeNote?: string
}

/** Kicks off the debit prompt. Resolves on 202; throws otherwise. */
export async function requestToPay(input: RequestToPayInput): Promise<void> {
  const cfg = requireConfig()
  const token = await getAccessToken(cfg)
  const headers: Record<string, string> = {
    Authorization: `Bearer ${token}`,
    'X-Reference-Id': input.referenceId,
    'X-Target-Environment': TARGET_ENV,
    'Ocp-Apim-Subscription-Key': cfg.subscriptionKey,
    'Content-Type': 'application/json',
  }
  const callback = process.env.MOMO_CALLBACK_URL?.trim()
  if (callback) headers['X-Callback-Url'] = callback

  const res = await fetch(`${BASE}/collection/v1_0/requesttopay`, {
    method: 'POST',
    headers,
    cache: 'no-store',
    body: JSON.stringify({
      amount: String(input.amount),
      currency: input.currency,
      externalId: input.externalId,
      payer: { partyIdType: 'MSISDN', partyId: input.msisdn },
      payerMessage: input.payerMessage ?? 'SnapWin deposit',
      payeeNote: input.payeeNote ?? 'SnapWin deposit',
    }),
  })
  if (res.status !== 202) {
    const body = await res.text().catch(() => '')
    throw new Error(`MoMo requesttopay failed (${res.status}): ${body.slice(0, 200)}`)
  }
}

export type MomoStatus = 'PENDING' | 'SUCCESSFUL' | 'FAILED'

export interface RequestToPayStatus {
  status: MomoStatus
  reason: string | null
  amount: number | null
  currency: string | null
}

/** Poll the status of a previously-initiated requesttopay. */
export async function getRequestToPayStatus(
  referenceId: string,
): Promise<RequestToPayStatus> {
  const cfg = requireConfig()
  const token = await getAccessToken(cfg)
  const res = await fetch(`${BASE}/collection/v1_0/requesttopay/${referenceId}`, {
    method: 'GET',
    headers: {
      Authorization: `Bearer ${token}`,
      'X-Target-Environment': TARGET_ENV,
      'Ocp-Apim-Subscription-Key': cfg.subscriptionKey,
    },
    cache: 'no-store',
  })
  if (!res.ok) {
    const body = await res.text().catch(() => '')
    throw new Error(`MoMo status failed (${res.status}): ${body.slice(0, 200)}`)
  }
  const json = (await res.json()) as {
    status?: string
    reason?: string | { code?: string; message?: string }
    amount?: string
    currency?: string
  }
  const raw = (json.status ?? 'PENDING').toUpperCase()
  const status: MomoStatus =
    raw === 'SUCCESSFUL' ? 'SUCCESSFUL' : raw === 'FAILED' ? 'FAILED' : 'PENDING'
  const reason =
    typeof json.reason === 'string'
      ? json.reason
      : json.reason?.message ?? json.reason?.code ?? null
  return {
    status,
    reason,
    amount: json.amount != null ? Number(json.amount) : null,
    currency: json.currency ?? null,
  }
}

/** Generate a fresh X-Reference-Id for a new request. */
export function newReferenceId(): string {
  return randomUUID()
}
