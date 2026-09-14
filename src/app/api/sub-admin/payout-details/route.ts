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

  // Say what actually went wrong. Letting the store throw returned a bare 500,
  // which the panel could only render as "Could not save." - identical whether
  // the migration was missing, the network dropped, or the row was gone.
  let updated
  try {
    updated = await updateSubAdmin(sa.id, {
      payoutName: name,
      payoutNetwork: network,
      payoutNumber: number,
    })
  } catch (e) {
    const message = e instanceof Error ? e.message : String(e)
    console.error('[payout-details] save failed:', message)
    if (/payout_(name|network|number).*does not exist/i.test(message)) {
      return NextResponse.json(
        {
          error:
            'Payout details are not set up on the database yet. Run migration 0025_sub_admin_payout_details.sql, then try again.',
        },
        { status: 503 },
      )
    }
    return NextResponse.json(
      { error: 'Could not save payout details. Please try again.' },
      { status: 500 },
    )
  }
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
