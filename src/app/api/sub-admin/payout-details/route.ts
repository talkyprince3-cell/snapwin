import { NextResponse } from 'next/server'
import { currentSubAdmin } from '@/lib/sub-admin-session'
import { updateSubAdmin } from '@/lib/sub-admins-store'

export const dynamic = 'force-dynamic'

/**
 * The partner's saved payout destination — ports save_payout_details from the
 * reference. Set once, then every payout request is pre-filled from it rather
 * than retyped, which is what stops a typo landing a real transfer on the
 * wrong number.
 */
export async function GET() {
  const sa = await currentSubAdmin()
  if (!sa) return NextResponse.json({ error: 'unauthorized' }, { status: 401 })
  return NextResponse.json({
    payout: {
      name: sa.payoutName ?? '',
      network: sa.payoutNetwork ?? '',
      number: sa.payoutNumber ?? '',
    },
  })
}

export async function PUT(request: Request) {
  const sa = await currentSubAdmin()
  if (!sa) return NextResponse.json({ error: 'unauthorized' }, { status: 401 })

  let body: { name?: string; network?: string; number?: string }
  try {
    body = await request.json()
  } catch {
    return NextResponse.json({ error: 'invalid json' }, { status: 400 })
  }

  const name = (body.name ?? '').trim()
  const network = (body.network ?? '').trim()
  const number = (body.number ?? '').trim()

  if (!name || !network || !number) {
    return NextResponse.json(
      { error: 'Complete the account name, provider, and account number.' },
      { status: 400 },
    )
  }
  if (name.length > 120 || network.length > 80 || number.length > 80) {
    return NextResponse.json({ error: 'One or more payout details are too long.' }, { status: 400 })
  }

  const updated = await updateSubAdmin(sa.id, {
    payoutName: name,
    payoutNetwork: network,
    payoutNumber: number,
  })
  if (!updated) return NextResponse.json({ error: 'not found' }, { status: 404 })

  return NextResponse.json({
    payout: {
      name: updated.payoutName ?? '',
      network: updated.payoutNetwork ?? '',
      number: updated.payoutNumber ?? '',
    },
    message: 'Payout details saved.',
  })
}
