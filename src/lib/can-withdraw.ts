export const PLAYER_BLOCKED_MESSAGE =
  'Withdrawals are currently unavailable for this account. Please contact support.'

/** Players can request a withdrawal unless a later block list is wired in. */
export async function userCanWithdraw(user: { id?: string } | null | undefined): Promise<boolean> {
  return Boolean(user?.id)
}
