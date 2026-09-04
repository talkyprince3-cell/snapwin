import { NextResponse } from 'next/server'

export const dynamic = 'force-dynamic'

/**
 * Read-only diagnostic: confirms whether the deployed server can see the
 * Arkesel SMS env vars. Returns ONLY booleans, the (non-secret) sender ID, and
 * the key's length — never the API key itself. Safe to hit from a browser to
 * verify a Vercel env var landed without doing a full withdrawal.
 *
 * Visit: /api/debug/sms-check
 */
export async function GET() {
  const apiKey = process.env.ARKESEL_API_KEY ?? ''
  const senderId = process.env.ARKESEL_SENDER_ID ?? ''

  return NextResponse.json({
    hasApiKey: apiKey.length > 0,
    apiKeyLength: apiKey.length, // 27 for the current key — 0 means "not set"
    senderId: senderId || null, // the approved Arkesel sender ID (ARKESEL_SENDER_ID), max 11 chars
    ready: apiKey.length > 0 && senderId.length > 0,
  })
}
