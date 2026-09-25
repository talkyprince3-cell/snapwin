// Which Flutterwave verifier owns a reference.
//
// Deposits now start on V4, but V3 rows are still settling and both write
// `provider: 'flutterwave'` to the ledger. The webhook and the reconcile
// sweeper only have a reference to go on, so they route on its prefix: a V4
// charge is looked up by its chg_ id, a V3 one by tx_ref against the V3 verify
// API. Sending a V4 reference to the V3 verifier just returns "not found", and
// the deposit would sit pending until someone resolved it by hand.

import { verifyAndCreditFlutterwave } from '@/lib/flutterwave-credit'
import { verifyAndCreditFlutterwaveV4 } from '@/lib/flutterwave-v4-credit'

/** Prefix minted by /api/payments/flutterwave-v4/momo/start. */
export const V4_REFERENCE_PREFIX = 'FW4-'

export function isV4Reference(reference: string): boolean {
  return reference.trim().startsWith(V4_REFERENCE_PREFIX)
}

export interface FlwCreditResult {
  status: string
  ok: boolean
  reference: string
}

/** Verify-then-credit against whichever API issued the charge. Idempotent. */
export async function verifyAndCreditFlutterwaveAny(reference: string): Promise<FlwCreditResult> {
  const ref = (reference ?? '').trim()
  return isV4Reference(ref)
    ? verifyAndCreditFlutterwaveV4(ref)
    : verifyAndCreditFlutterwave(ref)
}
