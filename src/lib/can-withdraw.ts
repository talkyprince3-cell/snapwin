import { getVerificationAmount, getWithdrawQualifyCount, isCountryCode } from '@/lib/countries'
import type { AppUser } from '@/lib/domain-types'

export const PLAYER_BLOCKED_MESSAGE =
  'Withdrawals are currently unavailable for this account. Please contact support.'

/** Hook for a future block list. A blocked account is refused outright. */
export async function userCanWithdraw(user: { id?: string } | null | undefined): Promise<boolean> {
  return Boolean(user?.id)
}

/**
 * A partner's own betting wallet, linked to their sub_admins row.
 *
 * Partners settle on the spot: no deposit-total gate, no operator approval, and
 * the first-deposit rule waived, since that wallet can hold winnings or
 * credited commission without a deposit of its own. Mirrors is_agent_user in
 * the reference api_withdraw.php.
 *
 * Being linked is not enough on its own — see `isExemptWithdrawer`. Anyone can
 * register as a partner and link a betting account, so the link alone would
 * let an unapproved stranger past both the deposit verification and the
 * operator's sign-off, which are the only two controls on money leaving here.
 */
export function isPartnerWallet(user: Pick<AppUser, 'linkedSubAdminId'>): boolean {
  return Boolean(user.linkedSubAdminId)
}

/**
 * Who withdraws without the deposit verification: an account linked to an
 * APPROVED sub_admins row, and nothing else.
 *
 * This is deliberately a property of the account, never of the session. An
 * admin session cookie identifies a browser, not a wallet — keying off it
 * meant that with an admin tab open, any ordinary player withdrawing in the
 * same browser was treated as staff. An operator who wants to withdraw as
 * staff links their own betting account to an approved partner record.
 */
export function isExemptWithdrawer(opts: { subAdminApproved: boolean }): boolean {
  return opts.subAdminApproved
}

export type WithdrawGate =
  /**
   * Has not made enough qualifying deposits — refuse and say how far off.
   *
   * `made` / `need` are the counts the gate actually decided on. The amount
   * fields describe the same requirement in money, for the progress bar.
   */
  | {
      kind: 'unverified'
      made: number
      need: number
      perDeposit: number
      qualifyTotal: number
      deposited: number
      remaining: number
    }
  /** Verified, but an operator still has to release it. */
  | { kind: 'needs-approval' }
  /** Settle now. `requireDeposit` stays on for players and off for partners. */
  | { kind: 'settle'; requireDeposit: boolean; instant: boolean }

/**
 * Which of the four withdrawal paths this account takes.
 *
 * Kept here rather than inline in the route so the ordering is stated once:
 * partner first, then the deposit gate, then admin approval. Reversing any
 * two of those changes who can take money out.
 *
 * `depositCount` is how many settled deposits of at least the per-deposit
 * minimum the player has made. It is passed in rather than read here because
 * counting them is a database query and this stays pure.
 */
export function withdrawGate(
  user: AppUser,
  exempt = false,
  depositCount = 0,
): WithdrawGate {
  // `exempt` is an approved partner. A bare link used to be enough, which
  // meant anyone who registered as a partner and linked a betting account
  // walked past both remaining controls.
  if (exempt) {
    return { kind: 'settle', requireDeposit: false, instant: true }
  }

  const country = isCountryCode(user.country) ? user.country : null
  const need = country ? getWithdrawQualifyCount(country) : 0
  const perDeposit = country ? getVerificationAmount(country) : 0

  // Counted, not summed. One large payment is not the same as several: the
  // old total gate let a single deposit clear the whole requirement.
  if (depositCount < need) {
    const deposited = user.totalDeposited ?? 0
    const qualifyTotal = +(need * perDeposit).toFixed(2)
    return {
      kind: 'unverified',
      made: depositCount,
      need,
      perDeposit,
      qualifyTotal,
      deposited,
      remaining: +Math.max(0, qualifyTotal - deposited).toFixed(2),
    }
  }

  if (!user.withdrawalApproved) return { kind: 'needs-approval' }

  return { kind: 'settle', requireDeposit: true, instant: false }
}
