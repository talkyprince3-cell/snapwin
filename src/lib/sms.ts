// Arkesel SMS sender (https://developers.arkesel.com — SMS API v2).
//
// Best-effort by design: every function returns a result object and never
// throws, so callers can fire it after an admin action without risking the
// main request. Needs two env vars:
//   ARKESEL_API_KEY   — from the Arkesel dashboard → API Keys
//   ARKESEL_SENDER_ID — approved sender name, max 11 chars (defaults below)

const ARKESEL_SEND_URL = 'https://sms.arkesel.com/api/v2/sms/send'
const DEFAULT_SENDER = 'SnapWin'

export interface SmsResult {
  ok: boolean
  error?: string
}

/**
 * Send a single SMS. `recipient` must be in international MSISDN form without
 * a "+", e.g. "233241234567" (use toInternationalPhone() from countries.ts).
 */
export async function sendSms(recipient: string, message: string): Promise<SmsResult> {
  const apiKey = process.env.ARKESEL_API_KEY
  if (!apiKey) return { ok: false, error: 'ARKESEL_API_KEY not set' }
  if (!recipient) return { ok: false, error: 'no recipient' }

  const sender = (process.env.ARKESEL_SENDER_ID || DEFAULT_SENDER).slice(0, 11)

  try {
    const res = await fetch(ARKESEL_SEND_URL, {
      method: 'POST',
      headers: { 'api-key': apiKey, 'Content-Type': 'application/json' },
      body: JSON.stringify({ sender, message, recipients: [recipient] }),
    })
    const data = (await res.json().catch(() => ({}))) as {
      status?: string
      message?: string
    }
    // Arkesel returns { status: "success", ... } on a queued message.
    if (!res.ok || (data.status && data.status !== 'success')) {
      return { ok: false, error: data.message || `HTTP ${res.status}` }
    }
    return { ok: true }
  } catch (e) {
    return { ok: false, error: e instanceof Error ? e.message : 'sms request failed' }
  }
}
