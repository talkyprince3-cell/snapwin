import { createHash, randomInt } from 'crypto'
import { supabaseServer } from '@/lib/supabase'

// A booking is a saved bet slip retrievable by a short code — no stake, no
// account. The selections are stored exactly in the UI slip shape so loading a
// code drops them straight back into the bet slip.
export interface BookingSelection {
  id: string
  matchId: string
  match: string
  market: string
  pick: string
  odds: number
}

export interface Booking {
  code: string
  createdAt: string
  totalOdds: number
  selections: BookingSelection[]
  /** When the code stops working (latest kickoff + buffer). Null = never. */
  expiresAt: string | null
}

interface BookingRow {
  code: string
  created_at: string
  total_odds: number
  selections: BookingSelection[]
  expires_at: string | null
}

/** A booking is expired once now is past its expires_at (if one was set). */
export function isBookingExpired(booking: Booking, now: Date = new Date()): boolean {
  if (!booking.expiresAt) return false
  const t = new Date(booking.expiresAt).getTime()
  return Number.isFinite(t) && now.getTime() > t
}

// Same human-friendly alphabet the bet codes use (no 0/O/1/I/L mix-ups).
const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'

function generateCode(length = 6): string {
  let s = ''
  for (let i = 0; i < length; i++) s += CODE_ALPHABET[randomInt(0, CODE_ALPHABET.length)]
  return s
}

/** Same matches / markets / picks / odds → same fingerprint, regardless of order. */
function slipFingerprint(selections: BookingSelection[]): string {
  return [...selections]
    .map(
      (s) =>
        `${s.matchId}|${s.market.trim().toUpperCase()}|${s.pick.trim().toUpperCase()}|${Number(s.odds).toFixed(4)}`,
    )
    .sort()
    .join('\n')
}

function codeFromFingerprint(fingerprint: string, length = 6): string {
  const hash = createHash('sha256').update(fingerprint).digest()
  let s = ''
  for (let i = 0; i < length; i++) s += CODE_ALPHABET[hash[i] % CODE_ALPHABET.length]
  return s
}

function sameSlip(a: BookingSelection[], b: BookingSelection[]): boolean {
  return slipFingerprint(a) === slipFingerprint(b)
}

async function generateUniqueBookingCode(): Promise<string> {
  for (let i = 0; i < 20; i++) {
    const code = generateCode()
    const { data, error } = await supabaseServer()
      .from('bookings')
      .select('code')
      .eq('code', code)
      .maybeSingle()
    if (error) throw new Error(`bookings.generateCode: ${error.message}`)
    if (!data) return code
  }
  return generateCode(8)
}

function rowToBooking(row: BookingRow): Booking {
  return {
    code: row.code,
    createdAt: row.created_at,
    totalOdds: Number(row.total_odds),
    selections: Array.isArray(row.selections) ? row.selections : [],
    expiresAt: row.expires_at ?? null,
  }
}

export async function createBooking(
  selections: BookingSelection[],
  totalOdds: number,
  expiresAt: string | null = null,
): Promise<Booking> {
  const preferred = codeFromFingerprint(slipFingerprint(selections))
  const existing = await findBookingByCode(preferred)
  if (existing && sameSlip(existing.selections, selections)) {
    return existing
  }

  const code = existing ? await generateUniqueBookingCode() : preferred
  const sb = supabaseServer()
  const base = { code, total_odds: totalOdds, selections }

  let { data, error } = await sb
    .from('bookings')
    .insert({ ...base, expires_at: expiresAt })
    .select('*')
    .single()

  // If the expires_at column hasn't been migrated yet, PostgREST rejects the
  // unknown column (PGRST204). Retry without it so booking keeps working — the
  // expiry simply lights up once the migration runs.
  if (error && (error.code === 'PGRST204' || /expires_at/i.test(error.message))) {
    console.warn('[bookings.create] expires_at column missing — run migration 0020. Booking without expiry.')
    ;({ data, error } = await sb.from('bookings').insert(base).select('*').single())
  }

  if (error && (error.code === '23505' || /duplicate/i.test(error.message))) {
    const raced = await findBookingByCode(code)
    if (raced && sameSlip(raced.selections, selections)) return raced
  }

  if (error) throw new Error(`bookings.create: ${error.message}`)
  return rowToBooking(data as BookingRow)
}

export async function findBookingByCode(code: string): Promise<Booking | null> {
  const upper = code.trim().toUpperCase()
  const { data, error } = await supabaseServer()
    .from('bookings')
    .select('*')
    .eq('code', upper)
    .maybeSingle()
  if (error) throw new Error(`bookings.findByCode: ${error.message}`)
  if (!data) return null
  return rowToBooking(data as BookingRow)
}
