import { NextResponse } from 'next/server'
import { cookies } from 'next/headers'
import { ADMIN_COOKIE, isValidSessionCookie } from '@/lib/admin-auth'
import { listAll, type SaWithdrawalStatus } from '@/lib/sub-admin-withdrawals-store'

export const dynamic = 'force-dynamic'

const STATUSES: SaWithdrawalStatus[] = ['pending', 'completed', 'rejected']

/** Admin queue of agent payout requests. `?status=pending` to filter. */
export async function GET(request: Request) {
  const token = (await cookies()).get(ADMIN_COOKIE)?.value
  if (!(await isValidSessionCookie(token))) {
    return NextResponse.json({ error: 'unauthorized' }, { status: 401 })
  }

  const raw = new URL(request.url).searchParams.get('status')
  const status = STATUSES.find((s) => s === raw)
  const withdrawals = await listAll(status)

  return NextResponse.json({
    withdrawals,
    pendingCount: withdrawals.filter((w) => w.status === 'pending').length,
  })
}
