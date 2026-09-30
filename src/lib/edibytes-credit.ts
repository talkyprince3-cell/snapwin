// Verify-then-credit for Edibytes Ghana MoMo deposits.
//
// The pending row written at /edibytes/momo/start is keyed on our own
// reference, which is also the one Edibytes acknowledged, so the status poll
// needs nothing but that string. The wallet moves ONLY when Edibytes reports
// the payment confirmed. Idempotent via markPaymentResolved.

import { findPaymentByReference, markPaymentFailed, markPaymentResolved } from '@/lib/payments-store'
import { getPaymentStatus } from '@/lib/edibytes'
import { applyDepositCredit } from '@/lib/deposit-credit'

export interface EdibytesCreditResult {
  status: string
  ok: boolean
  reference: string
}

export async function verifyAndCreditEdibytes(ref: string): Promise<EdibytesCreditResult> {
  const reference = (ref ?? '').trim()
  if (!reference) return { status: 'missing-reference', ok: false, reference }

  const pending = await findPaymentByReference(reference)
  if (!pending) return { status: 'unknown-reference', ok: false, reference }
  if (pending.status === 'success') return { status: 'already-credited', ok: true, reference }

  const outcome = await getPaymentStatus(reference)
  if (outcome.status === 'failed') {
    // Close it, or it is swept again on every account-page load and sits in
    // the operator's pending queue for ever looking like an unapproved
    // prompt. Edibytes does not un-fail a payment.
    try {
      await markPaymentFailed(pending.id, 'edibytes reported the charge failed')
    } catch (e) {
      console.error('[edibytes-credit] could not close failed payment:', e)
    }
    return { status: 'failed', ok: false, reference }
  }
  if (outcome.status !== 'confirmed') return { status: 'pending', ok: false, reference }

  // Guard the amount and currency before anything moves. Edibytes reports both
  // in major units, the same as the figure we opened the payment with.
  if (outcome.paidCurrency && pending.currency && outcome.paidCurrency !== pending.currency) {
    return { status: 'currency-mismatch', ok: false, reference }
  }
  // A player can open a GH₵500 deposit and approve GH₵5 on the handset, and
  // the rail still calls that a success. Anything short of what was asked for
  // is held for an operator rather than credited.
  if (typeof outcome.paidAmount === 'number' && outcome.paidAmount + 0.01 < pending.amount) {
    return { status: 'amount-mismatch', ok: false, reference }
  }
  if (!pending.userId) return { status: 'no-user', ok: false, reference }

  try {
    const resolved = await markPaymentResolved(pending.id, 'edibytes auto-verify')
    if (!resolved) return { status: 'already-credited', ok: true, reference }
    await applyDepositCredit(pending.userId, pending.amount, { reference })
  } catch (e) {
    console.error('[edibytes-credit] credit pipeline failed:', e)
    return { status: 'credit-failed', ok: false, reference }
  }
  return { status: 'success', ok: true, reference }
}
