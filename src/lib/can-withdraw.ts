import { getWithdrawQualifyTotal, isCountryCode } from '@/lib/countries'
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
 */
export function isPartnerWallet(user: Pick<AppUser, 'linkedSubAdminId'>): boolean {
  return Boolean(user.linkedSubAdminId)
}

export type WithdrawGate =
  /** Never deposited enough to unlock withdrawals — refuse and say how far off. */
  | { kind: 'unverified'; qualifyTotal: number; deposited: number; remaining: number }
  /** Verified, but an operator still has to release it. */
  | { kind: 'needs-approval' }
  /** Settle now. `requireDeposit` stays on for players and off for partners. */
  | { kind: 'settle'; requireDeposit: boolean; instant: boolean }

/**
 * Which of the four withdrawal paths this account takes.
 *
 * Kept here rather than inline in the route so the ordering is stated once:
 * partner first, then the deposit-total gate, then admin approval. Reversing
 * any two of those changes who can take money out.
 */
export function withdrawGate(user: AppUser): WithdrawGate {
  if (isPartnerWallet(user)) {
    return { kind: 'settle', requireDeposit: false, instant: true }
  }

  const qualifyTotal = isCountryCode(user.country) ? getWithdrawQualifyTotal(user.country) : 0
  const deposited = user.totalDeposited ?? 0
  if (deposited < qualifyTotal) {
    return {
      kind: 'unverified',
      qualifyTotal,
      deposited,
      remaining: +(qualifyTotal - deposited).toFixed(2),
    }
  }

  if (!user.withdrawalApproved) return { kind: 'needs-approval' }

  return { kind: 'settle', requireDeposit: true, instant: false }
}
