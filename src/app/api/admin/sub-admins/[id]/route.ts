import { NextResponse } from 'next/server'
import { cookies } from 'next/headers'
import { ADMIN_COOKIE, isValidSessionCookie } from '@/lib/admin-auth'
import {
  clearCommissionBalance,
  deleteSubAdmin,
  findSubAdminById,
  updateSubAdmin,
} from '@/lib/sub-admins-store'
import { DEFAULT_CURRENCY, isCurrencyCode } from '@/lib/countries'

export const dynamic = 'force-dynamic'

interface Params {
  params: Promise<{ id: string }>
}

async function requireAdmin(): Promise<NextResponse | null> {
  const token = (await cookies()).get(ADMIN_COOKIE)?.value
  if (!(await isValidSessionCookie(token))) {
    return NextResponse.json({ error: 'unauthorized' }, { status: 401 })
  }
  return null
}

export async function PATCH(request: Request, { params }: Params) {
  const denied = await requireAdmin()
  if (denied) return denied
  const { id } = await params
  let body: {
    approved?: boolean
    clearCommissionBalance?: boolean
    currency?: string
    /** Per-partner rate override, 0-100. Send null to fall back to the default. */
    commissionPct?: number | null
    commissionPauseExempt?: boolean
  }
  try {
    body = (await request.json()) as typeof body
  } catch {
    return NextResponse.json({ error: 'invalid json' }, { status: 400 })
  }

  let updated = null as Awaited<ReturnType<typeof updateSubAdmin>>
  if (typeof body.approved === 'boolean') {
    updated = await updateSubAdmin(id, { approved: body.approved })
  }

  // Per-partner commission overrides. `null` clears the override, which is
  // why this tests for the key rather than for a truthy value.
  if ('commissionPct' in body) {
    const raw = body.commissionPct
    if (raw !== null && raw !== undefined) {
      const n = Number(raw)
      if (!Number.isFinite(n) || n < 0 || n > 100) {
        return NextResponse.json(
          { error: 'Commission must be between 0 and 100.' },
          { status: 400 },
        )
      }
      updated = await updateSubAdmin(id, { commissionPct: +n.toFixed(2) })
    } else {
      updated = await updateSubAdmin(id, { commissionPct: undefined })
    }
  }
  if (typeof body.commissionPauseExempt === 'boolean') {
    updated = await updateSubAdmin(id, { commissionPauseExempt: body.commissionPauseExempt })
  }

  // Clear the unpaid balance for one currency once the admin has paid out.
  if (body.clearCommissionBalance === true) {
    const currency = isCurrencyCode(body.currency) ? body.currency : DEFAULT_CURRENCY
    updated = await clearCommissionBalance(id, currency)
  }

  if (!updated) updated = await findSubAdminById(id)
  if (!updated) return NextResponse.json({ error: 'not found' }, { status: 404 })

  return NextResponse.json({
    subAdmin: {
      id: updated.id,
      name: updated.name,
      email: updated.email,
      approved: updated.approved,
      commissionBalance: updated.commissionBalance,
      totalCommissionEarned: updated.totalCommissionEarned,
      commissionBalances: updated.commissionBalances,
      totalCommissionEarnedBy: updated.totalCommissionEarnedBy,
      commissionPct: updated.commissionPct,
      commissionPauseExempt: updated.commissionPauseExempt,
    },
  })
}

export async function DELETE(_req: Request, { params }: Params) {
  const denied = await requireAdmin()
  if (denied) return denied
  const { id } = await params
  const ok = await deleteSubAdmin(id)
  if (!ok) return NextResponse.json({ error: 'not found' }, { status: 404 })
  return NextResponse.json({ ok: true })
}
