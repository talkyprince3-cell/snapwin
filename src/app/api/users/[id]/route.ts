import { NextResponse } from 'next/server'
import { findUserById } from '@/lib/users-store'
import { countQualifyingDeposits } from '@/lib/payments-store'
import { getVerificationAmount, getWithdrawQualifyCount, isCountryCode } from '@/lib/countries'
import { userCanWithdraw } from '../../../../lib/can-withdraw'

export const dynamic = 'force-dynamic'

export async function GET(
  _request: Request,
  { params }: { params: Promise<{ id: string }> },
) {
  const { id } = await params
  const user = await findUserById(id.trim())
  if (!user) {
    return NextResponse.json({ error: 'user not found' }, { status: 404 })
  }

  const balance = user.balance ?? user.totalDeposited - (user.totalWithdrawn ?? 0)

  // The withdrawal gate counts qualifying deposits, so the account page is
  // given the same count rather than inferring progress from the lifetime
  // total — otherwise the panel and the gate tell the player different
  // things about the same requirement.
  const country = isCountryCode(user.country) ? user.country : null
  const perDeposit = country ? getVerificationAmount(country) : 0
  const depositsNeeded = country ? getWithdrawQualifyCount(country) : 0
  const depositsMade = country
    ? await countQualifyingDeposits(user.id, perDeposit).catch(() => 0)
    : 0

  return NextResponse.json({
    depositsMade,
    depositsNeeded,
    perDeposit,
    id: user.id,
    name: user.name,
    email: user.email,
    country: user.country,
    currency: user.currency,
    totalDeposited: user.totalDeposited,
    totalWithdrawn: user.totalWithdrawn ?? 0,
    balance,
    verificationStep: user.verificationStep ?? 0,
    withdrawalApproved: user.withdrawalApproved ?? false,
    canWithdraw: await userCanWithdraw(user),
    phone: user.phone ?? null,
    firstDepositAt: user.firstDepositAt ?? null,
    referredByCode: user.referredByCode ?? null,
  })
}


