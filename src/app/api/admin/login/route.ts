import { NextResponse } from 'next/server'
import { ADMIN_COOKIE, ADMIN_COOKIE_MAX_AGE, sessionTokenFor } from '@/lib/admin-auth'

export const dynamic = 'force-dynamic'

export async function POST(request: Request) {
  if (!process.env.ADMIN_PASSWORD) {
    // Point at the right place. On Vercel there is no .env.local to edit —
    // the variable lives in the project's Environment Variables, and a fresh
    // project (or the Preview scope) starts with none, which is the usual
    // reason this fires on a deployment that works fine locally.
    const onVercel = !!process.env.VERCEL
    const where = onVercel
      ? `this deployment. Add it to the Vercel project's Environment Variables (${process.env.VERCEL_ENV ?? 'production'} scope) and redeploy`
      : '.env.local, then restart the dev server'
    return NextResponse.json(
      { error: `admin disabled — ADMIN_PASSWORD is not set in ${where}` },
      { status: 503 },
    )
  }

  let body: unknown
  try {
    body = await request.json()
  } catch {
    return NextResponse.json({ error: 'invalid json' }, { status: 400 })
  }
  const { password } = body as { password?: string }
  if (typeof password !== 'string' || password.length === 0) {
    return NextResponse.json({ error: 'password required' }, { status: 400 })
  }

  if (password !== process.env.ADMIN_PASSWORD) {
    return NextResponse.json({ error: 'invalid password' }, { status: 401 })
  }

  const token = await sessionTokenFor(password)
  const res = NextResponse.json({ ok: true })
  res.cookies.set(ADMIN_COOKIE, token, {
    httpOnly: true,
    sameSite: 'lax',
    path: '/',
    maxAge: ADMIN_COOKIE_MAX_AGE,
    secure: process.env.NODE_ENV === 'production',
  })
  return res
}
