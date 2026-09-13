import { NextResponse } from 'next/server'
import { cookies } from 'next/headers'
import { ADMIN_COOKIE, isValidSessionCookie } from '@/lib/admin-auth'
import { readCommissionConfig, writeCommissionConfig } from '@/lib/commission-config'

export const dynamic = 'force-dynamic'

async function admin(): Promise<boolean> {
  return isValidSessionCookie((await cookies()).get(ADMIN_COOKIE)?.value)
}

/** Global partner-commission settings: the default rate and the pause switch. */
export async function GET() {
  if (!(await admin())) return NextResponse.json({ error: 'unauthorized' }, { status: 401 })
  return NextResponse.json(await readCommissionConfig())
}

export async function PATCH(request: Request) {
  if (!(await admin())) return NextResponse.json({ error: 'unauthorized' }, { status: 401 })

  let body: { defaultPct?: number; paused?: boolean }
  try {
    body = await request.json()
  } catch {
    return NextResponse.json({ error: 'invalid json' }, { status: 400 })
  }

  if (body.defaultPct !== undefined) {
    const n = Number(body.defaultPct)
    if (!Number.isFinite(n) || n < 0 || n > 100) {
      return NextResponse.json({ error: 'Default rate must be between 0 and 100.' }, { status: 400 })
    }
  }

  await writeCommissionConfig({
    defaultPct: body.defaultPct,
    paused: body.paused,
  })
  return NextResponse.json(await readCommissionConfig())
}
