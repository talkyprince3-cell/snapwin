import { NextResponse } from 'next/server'
import { verifyAndCreditEdibytes } from '@/lib/edibytes-credit'

export const dynamic = 'force-dynamic'

// Poll target for the Edibytes MoMo checkout. Re-verifies by our reference and
// credits on success (idempotent). The frontend polls this while the customer
// approves the prompt on their phone.
export async function GET(request: Request) {
  const { searchParams } = new URL(request.url)
  const reference = (searchParams.get('reference') ?? '').trim()
  if (!reference) {
    return NextResponse.json({ error: 'reference required' }, { status: 400 })
  }
  const result = await verifyAndCreditEdibytes(reference)
  return NextResponse.json(result)
}
