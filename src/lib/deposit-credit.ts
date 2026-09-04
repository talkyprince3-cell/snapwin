// Apply a confirmed deposit to a user's wallet: update totals (via
// recordDeposit, which also stamps firstDepositAt for first-timers),
// advance the withdrawal-verification gate when the amount qualifies,
// and fire sub-admin commission for referred users.
//
// Reused by every path that confirms a deposit:
//   - /api/admin/users/[id]/credit (admin manually crediting from /admin/users)
//   - /api/admin/payments/[id]/resolve (admin 'Credit & resolve')
//   - the payment auto-credit pipelines (Paystack / Moolre)
//
// Pure 'bonus' credits (which should NOT count toward verification or
// commission) should bypass this helper and call creditBalance directly.
//
// The verification threshold is country-aware (see lib/countries.ts).

import {
  addCommission,
  advanceVerificationStep,
  creditBalance,
  findUserById,
  recordDeposit,
} from '@/lib/users-store'
import { creditCommission, findSubAdminById } from '@/lib/sub-admins-store'
import { COMMISSION_RATE, type AppUser } from '@/lib/domain-types'
import { getVerificationAmount, isCountryCode, toInternationalPhone } from '@/lib/countries'
import { formatMoneyWithCurrency } from '@/lib/format-money'
import { sendSms } from '@/lib/sms'

// One-time welcome bonus credited on a user's FIRST confirmed deposit, in the
// user's wallet currency. Override per-deployment with FIRST_DEPOSIT_BONUS.
const FIRST_DEPOSIT_BONUS = Number(process.env.FIRST_DEPOSIT_BONUS ?? 100) || 0

export interface ApplyDepositResult {
  user: AppUser
  isFirstDeposit: boolean
  commission: {
    amount: number
    rate: number
    subAdminId: string
    currency: AppUser['currency']
  } | null
}

/**
 * Fire-and-forget: text the user a "payment received" confirmation styled like
 * a mobile-money transaction alert (amount, available balance, reference).
 * Sent from the approved SnapWin sender ID — NOT impersonating any telco.
 * Best-effort: any failure (no phone, SMS error, unsupported country) is
 * logged and swallowed so it never affects the credited deposit.
 */
async function notifyDepositReceived(
  user: AppUser,
  amount: number,
  reference: string,
): Promise<void> {
  try {
    if (!user.phone || !isCountryCode(user.country)) return
    const recipient = toInternationalPhone(user.country, user.phone)
    if (!recipient) return

    const received = formatMoneyWithCurrency(amount, user.currency)
    const balance = formatMoneyWithCurrency(user.balance ?? 0, user.currency)
    const message =
      `SnapWin: Payment received. Amount: ${received}. ` +
      `Available Balance: ${balance}. Ref: ${reference}. ` +
      `Thank you for playing with SnapWin.`

    const result = await sendSms(recipient, message)
    if (!result.ok) {
      console.error('[deposit-credit] payment-received SMS failed:', result.error)
    }
  } catch (e) {
    console.error('[deposit-credit] payment-received SMS notify error:', e)
  }
}

export async function applyDepositCredit(
  userId: string,
  amount: number,
  opts: { reference?: string } = {},
): Promise<ApplyDepositResult | null> {
  // Need the user's country to know what verification threshold applies and
  // what currency to attribute the commission row to.
  const userBefore = await findUserById(userId)
  if (!userBefore) return null

  const result = await recordDeposit(userId, amount)
  if (!result) return null

  let user = result.user
  const verificationThreshold = getVerificationAmount(userBefore.country)

  // First-deposit welcome bonus — one-time, credited on the very first
  // confirmed deposit. Pure bonus: does NOT count toward verification or
  // commission. Best-effort: a failure here must not fail the deposit.
  if (result.isFirst && FIRST_DEPOSIT_BONUS > 0) {
    try {
      const bonused = await creditBalance(userId, FIRST_DEPOSIT_BONUS)
      if (bonused) user = bonused
      console.log('[deposit-credit] first-deposit bonus credited', {
        userId: user.id,
        bonus: FIRST_DEPOSIT_BONUS,
        currency: user.currency,
      })
    } catch (e) {
      console.error('[deposit-credit] first-deposit bonus failed (deposit still landed)', {
        userId: user.id,
        error: e instanceof Error ? e.message : String(e),
      })
    }
  }

  // Commission fires on EVERY confirmed deposit (not just the first) as long
  // as the user was referred by an approved sub-admin. Skip reasons are logged
  // so "I deposited but my referrer wasn't paid" reports are easy to diagnose.
  // Runs BEFORE the verification bump so a failed step can't swallow it.
  let commission: ApplyDepositResult['commission'] = null
  if (!user.referredBySubAdminId) {
    console.log('[deposit-credit] commission skipped: user not referred', {
      userId: user.id,
      amount,
      depositNumber: result.isFirst ? 1 : '2+',
    })
  } else {
    const sa = await findSubAdminById(user.referredBySubAdminId)
    if (!sa) {
      console.warn('[deposit-credit] commission skipped: referring sub-admin not found', {
        userId: user.id,
        subAdminId: user.referredBySubAdminId,
        amount,
      })
    } else if (!sa.approved) {
      console.warn('[deposit-credit] commission skipped: referring sub-admin not approved', {
        userId: user.id,
        subAdminId: sa.id,
        subAdminName: sa.name,
        amount,
      })
    } else {
      const amt = +(amount * COMMISSION_RATE).toFixed(2)
      commission = await fireCommission({
        subAdminId: sa.id,
        userId: user.id,
        amount,
        commissionAmount: amt,
        currency: user.currency,
        depositNumber: result.isFirst ? 1 : '2+',
      })
    }
  }

  // Verification step is best-effort: a failure here must not roll back the
  // commission or the wallet credit that already happened above.
  if (amount >= verificationThreshold && (user.verificationStep ?? 0) < 4) {
    try {
      const advanced = await advanceVerificationStep(userId)
      if (advanced) user = advanced
    } catch (e) {
      console.error('[deposit-credit] verification-step advance failed (deposit + commission already landed)', {
        userId: user.id,
        currentStep: user.verificationStep ?? 0,
        error: e instanceof Error ? e.message : String(e),
      })
    }
  }

  // Confirm receipt to the depositor (styled like a MoMo alert), using the
  // final balance after any first-deposit bonus. Reference falls back to a
  // generated deposit ref when the caller didn't pass the ledger reference.
  const reference =
    opts.reference || `PB-DEP-${userId.slice(0, 8).toUpperCase()}`
  await notifyDepositReceived(user, amount, reference)

  return { user, isFirstDeposit: result.isFirst, commission }
}

// Two-attempt commission write so a single transient supabase error doesn't
// strand a commission. Never rethrows — by this point the wallet is already
// funded; we'd rather lose the commission row (logged loudly for backfill)
// than fail the depositor.
async function fireCommission(params: {
  subAdminId: string
  userId: string
  amount: number
  commissionAmount: number
  currency: AppUser['currency']
  depositNumber: number | string
}): Promise<ApplyDepositResult['commission']> {
  const { subAdminId, userId, amount, commissionAmount, currency, depositNumber } = params
  let lastErr: unknown = null
  for (let attempt = 1; attempt <= 2; attempt++) {
    try {
      await creditCommission(subAdminId, commissionAmount, currency)
      await addCommission({
        subAdminId,
        userId,
        depositAmount: amount,
        commission: commissionAmount,
        rate: COMMISSION_RATE,
        currency,
      })
      console.log('[deposit-credit] commission credited', {
        userId,
        subAdminId,
        depositAmount: amount,
        commissionAmount,
        currency,
        depositNumber,
        attempt,
      })
      return { amount: commissionAmount, rate: COMMISSION_RATE, subAdminId, currency }
    } catch (e) {
      lastErr = e
      console.error('[deposit-credit] commission attempt failed', {
        attempt,
        userId,
        subAdminId,
        amount,
        commissionAmount,
        currency,
        error: e instanceof Error ? e.message : String(e),
      })
      if (attempt < 2) await new Promise((r) => setTimeout(r, 150))
    }
  }
  console.error('[deposit-credit] commission permanently failed — backfill required', {
    userId,
    subAdminId,
    amount,
    commissionAmount,
    currency,
    error: lastErr instanceof Error ? lastErr.message : String(lastErr),
  })
  return null
}
