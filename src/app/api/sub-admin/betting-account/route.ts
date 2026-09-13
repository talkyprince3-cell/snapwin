import { NextResponse } from 'next/server'
import { currentSubAdmin } from '@/lib/sub-admin-session'
import { ensureBettingAccountForSubAdmin } from '@/lib/users-store'

export const dynamic = 'force-dynamic'

/**
 * The partner's own player wallet — the account they bet with on the main site.
 *
 * GET creates it on first call and returns it thereafter, so the dashboard can
 * show the balance without the partner having to "set up" anything. Sign-in uses
 * the credentials they already have: the wallet carries the same email and
 * password hash as their partner login.
 */
export async function GET() {
  const sa = await currentSubAdmin()
  if (!sa) return NextResponse.json({ error: 'unauthorized' }, { status: 401 })
  if (!sa.approved) {
    return NextResponse.json(
      { error: 'Your partner account is awaiting approval.' },
      { status: 403 },
    )
  }

  try {
    const user = await ensureBettingAccountForSubAdmin({
      id: sa.id,
      name: sa.name,
      email: sa.email,
      passwordHash: sa.passwordHash,
    })
    return NextResponse.json({
      account: {
        id: user.id,
        name: user.name,
        email: user.email,
        currency: user.currency,
        balance: user.balance ?? 0,
        totalDeposited: user.totalDeposited,
      },
    })
  } catch (e) {
    console.error('[sub-admin betting-account]', e)
    return NextResponse.json(
      { error: 'Could not open your betting account. Please try again.' },
      { status: 500 },
    )
  }
}
