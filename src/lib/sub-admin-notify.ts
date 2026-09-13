/**
 * SMS notifications for agent (sub-admin) commission payouts.
 *
 * SnapWin has no email sender — the PHP reference used SMTP via mailer.php,
 * and there is no equivalent here — so agents are reached the same way players
 * already are: Arkesel SMS.
 *
 * The number used is the payout destination the agent supplied, because
 * sub_admins stores an email but no phone. That is only a phone number when
 * the payout is mobile money, so a bank payout is skipped rather than texting
 * an account number to whoever happens to own it as a phone.
 *
 * Every function here is best-effort. A payout must never fail because a text
 * did not send, so failures are logged and swallowed.
 */

import { getCountryForCurrency, toInternationalPhone, type CurrencyCode } from '@/lib/countries'
import { formatMoneyWithCurrency } from '@/lib/format-money'
import { sendSms } from '@/lib/sms'

/** Payout methods whose destination is a phone we can legitimately text. */
const MOBILE_METHODS = /momo|mobile|telecel|vodafone|airtel|tigo|m-?pesa/i

function recipientFor(
  currency: CurrencyCode,
  payoutMethod: string | undefined,
  destination: string | undefined,
): string | null {
  if (!destination) return null
  if (!payoutMethod || !MOBILE_METHODS.test(payoutMethod)) return null
  // Currency implies the market, which gives us the dial code — sub-admins
  // carry no country of their own.
  const country = getCountryForCurrency(currency)
  return toInternationalPhone(country.code, destination)
}

async function send(to: string | null, message: string, tag: string): Promise<void> {
  if (!to) return
  try {
    const result = await sendSms(to, message)
    if (!result.ok) console.error(`[${tag}] SMS failed:`, result.error)
  } catch (e) {
    console.error(`[${tag}] SMS notify error:`, e)
  }
}

export async function notifyPayoutRequested(input: {
  amount: number
  currency: CurrencyCode
  payoutMethod?: string
  payoutDestination?: string
}): Promise<void> {
  const money = formatMoneyWithCurrency(input.amount, input.currency)
  await send(
    recipientFor(input.currency, input.payoutMethod, input.payoutDestination),
    `SnapWin: We've received your payout request of ${money}. You'll get a message once it's approved.`,
    'sa-payout-request',
  )
}

export async function notifyPayoutApproved(input: {
  amount: number
  currency: CurrencyCode
  payoutMethod?: string
  payoutDestination?: string
  paymentReference?: string
}): Promise<void> {
  const money = formatMoneyWithCurrency(input.amount, input.currency)
  const ref = input.paymentReference ? ` Ref: ${input.paymentReference}.` : ''
  await send(
    recipientFor(input.currency, input.payoutMethod, input.payoutDestination),
    `SnapWin: Your payout of ${money} has been approved and is being sent to ${input.payoutDestination}.${ref}`,
    'sa-payout-approved',
  )
}

export async function notifyPayoutRejected(input: {
  amount: number
  currency: CurrencyCode
  payoutMethod?: string
  payoutDestination?: string
  reason?: string
}): Promise<void> {
  const money = formatMoneyWithCurrency(input.amount, input.currency)
  const why = input.reason ? ` Reason: ${input.reason}` : ''
  await send(
    recipientFor(input.currency, input.payoutMethod, input.payoutDestination),
    `SnapWin: Your payout request of ${money} was not approved.${why} Your commission balance is unchanged.`,
    'sa-payout-rejected',
  )
}
