import { NextResponse } from 'next/server'
import { findPaymentByReference } from '@/lib/payments-store'
import { verifyOtp } from '@/lib/edibytes'

export const dynamic = 'force-dynamic'

/**
 * Submit the code Edibytes texted, which is also what dispatches the charge.
 *
 * Edibytes needs no secret key here — the endpoint is scoped by the
 * unguessable reference — but this still goes through our server so the
 * browser never holds a payment credential, and so an unknown reference is
 * turned away before it reaches them.
 *
 * On success the prompt is on its way to the handset and the caller polls
 * /edibytes/momo/status as usual. Nothing is credited here.
 */
export async function POST(request: Request) {
  let body: { reference?: string; otp?: string; code?: string }
  try {
    body = (await request.json()) as { reference?: string; otp?: string; code?: string }
  } catch {
    return NextResponse.json({ error: 'invalid json' }, { status: 400 })
  }

  const reference = (body.reference ?? '').trim()
  // The deposit modal posts `otp` for every rail; `code` is what Edibytes
  // itself calls it, so both are accepted.
  const code = (body.otp ?? body.code ?? '').trim()

  if (!reference) return NextResponse.json({ error: 'reference required' }, { status: 400 })
  if (!code) return NextResponse.json({ status: 'otp-invalid', error: 'Enter the code from the text message.' })

  const pending = await findPaymentByReference(reference)
  if (!pending) return NextResponse.json({ status: 'unknown-reference', error: 'That deposit was not found.' })
  if (pending.status === 'success') return NextResponse.json({ status: 'already-credited' })

  const result = await verifyOtp(reference, code)
  if (!result.ok) {
    // A rejected code is worth another try on the same reference; the modal
    // keeps the input open when it sees this status.
    return NextResponse.json({ status: 'otp-invalid', error: result.error })
  }

  return NextResponse.json({ status: 'pending', instruction: result.message ?? null })
}
