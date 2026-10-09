import type { Match, MarketBook, OverUnderLine } from '@/lib/domain-types'
import { deriveMarketBook, mergeMarketBook } from '@/lib/markets'

/**
 * Fallback football feed: ESPN's public scoreboard (no key, no quota).
 *
 * Used only when API-Football fails — most commonly the free tier's daily
 * request limit — so the board shows real matches instead of going blank
 * until midnight. These are REAL fixtures with REAL bookmaker prices
 * (DraftKings 1X2 moneylines and a total line, converted to decimals), not
 * placeholders.
 *
 * Ids are prefixed `espn-` so they can never collide with an API-Football
 * fixture id: settlement routes by that prefix, and a bare numeric ESPN id
 * could otherwise be mistaken for a different real fixture and settle a bet
 * against the wrong match's score.
 */

const SCOREBOARD =
  'https://site.api.espn.com/apis/site/v2/sports/soccer/all/scoreboard'

export const ESPN_ID_PREFIX = 'espn-'

/** Matches served in fallback mode — roughly the "100 matches" the board needs. */
const MAX_MATCHES = 150

interface EspnOddsSide {
  open?: { odds?: string; line?: string }
  close?: { odds?: string; line?: string }
}

interface EspnCompetition {
  status?: {
    displayClock?: string
    type?: {
      name?: string
      state?: 'pre' | 'in' | 'post'
      completed?: boolean
      detail?: string
    }
  }
  venue?: { address?: { country?: string } }
  altGameNote?: string
  competitors?: Array<{
    homeAway?: 'home' | 'away'
    score?: string
    team?: { displayName?: string; logo?: string }
  }>
  odds?: Array<{
    moneyline?: { home?: EspnOddsSide; away?: EspnOddsSide }
    drawOdds?: { moneyLine?: number }
    total?: { over?: EspnOddsSide; under?: EspnOddsSide }
  }>
}

interface EspnEvent {
  id: string
  date: string
  competitions?: EspnCompetition[]
}

/** American odds ("-245", "+650", "EVEN", or a bare number) to decimal, 0 if unusable. */
function americanToDecimal(v: string | number | undefined | null): number {
  if (v == null) return 0
  if (typeof v === 'string' && v.trim().toUpperCase() === 'EVEN') return 2
  const n = typeof v === 'number' ? v : parseFloat(v)
  if (!Number.isFinite(n) || n === 0) return 0
  const dec = n > 0 ? 1 + n / 100 : 1 + 100 / Math.abs(n)
  return +dec.toFixed(2)
}

/** Closing price when the book has one, opening price otherwise. */
function sidePrice(side: EspnOddsSide | undefined): number {
  return americanToDecimal(side?.close?.odds ?? side?.open?.odds)
}

async function fetchDay(date: string, revalidateSeconds: number): Promise<EspnEvent[]> {
  const ymd = date.replace(/-/g, '')
  try {
    const res = await fetch(`${SCOREBOARD}?dates=${ymd}&limit=300`, {
      next: { revalidate: revalidateSeconds },
    })
    if (!res.ok) return []
    const json = (await res.json()) as { events?: EspnEvent[] }
    return json.events ?? []
  } catch {
    return []
  }
}

function toMatch(ev: EspnEvent): Match | null {
  const comp = ev.competitions?.[0]
  if (!comp) return null

  const home = comp.competitors?.find((c) => c.homeAway === 'home')
  const away = comp.competitors?.find((c) => c.homeAway === 'away')
  if (!home?.team?.displayName || !away?.team?.displayName) return null

  // Only matches with a full 1X2 book are served — the feed filter drops
  // odds-less matches anyway, and invented prices have no place on a board.
  const book = comp.odds?.[0]
  const oddsHome = sidePrice(book?.moneyline?.home)
  const oddsAway = sidePrice(book?.moneyline?.away)
  const oddsDraw = americanToDecimal(book?.drawOdds?.moneyLine)
  if (oddsHome <= 0 || oddsDraw <= 0 || oddsAway <= 0) return null

  const state = comp.status?.type?.state
  const typeName = comp.status?.type?.name ?? ''
  if (typeName === 'STATUS_POSTPONED' || typeName === 'STATUS_CANCELED') return null

  const isLive = state === 'in'
  const finished = state === 'post'
  const start = new Date(ev.date)

  const minute = finished
    ? 'FT'
    : typeName === 'STATUS_HALFTIME'
      ? 'HT'
      : isLive
        ? (comp.status?.displayClock ?? '').trim() || undefined
        : undefined

  const base: Match = {
    id: `${ESPN_ID_PREFIX}${ev.id}`,
    league: comp.altGameNote || 'Football',
    country: comp.venue?.address?.country ?? 'World',
    homeTeam: home.team.displayName,
    awayTeam: away.team.displayName,
    isLive,
    startTime: isLive
      ? undefined
      : start.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }),
    startTimeISO: start.toISOString(),
    minute,
    odds: { home: oddsHome, draw: oddsDraw, away: oddsAway },
    sport: 'football',
  }
  if (isLive || finished) {
    const hs = Number(home.score)
    const as = Number(away.score)
    if (Number.isFinite(hs)) base.homeScore = hs
    if (Number.isFinite(as)) base.awayScore = as
  }
  if (home.team.logo) base.homeFlagUrl = home.team.logo
  if (away.team.logo) base.awayFlagUrl = away.team.logo

  const derived = deriveMarketBook(base)
  if (derived) {
    // One real total line when the book carries it; everything else derived.
    const lineStr = book?.total?.over?.close?.line ?? book?.total?.over?.open?.line
    const line = lineStr ? parseFloat(lineStr.replace(/^[ou]/i, '')) : NaN
    const over = sidePrice(book?.total?.over)
    const under = sidePrice(book?.total?.under)
    const partial: Partial<MarketBook> = {}
    if (Number.isFinite(line) && over > 0 && under > 0) {
      const ou: OverUnderLine[] = [{ line, over, under }]
      partial.overUnder = ou
    }
    base.markets = mergeMarketBook(derived, partial)
  }

  return base
}

/**
 * Live and upcoming matches for today plus the next three days (mirrors the
 * primary feed's window). Today refreshes every 2 minutes so live clocks and
 * scores keep moving; later days barely change and cache for 30.
 */
export async function getFallbackMatches(): Promise<Match[]> {
  const dates: string[] = []
  for (let i = 0; i < 4; i++) {
    dates.push(new Date(Date.now() + i * 86_400_000).toISOString().slice(0, 10))
  }

  const days = await Promise.all(
    dates.map((d, i) => fetchDay(d, i === 0 ? 120 : 1800)),
  )

  const seen = new Set<string>()
  const matches: Match[] = []
  for (const events of days) {
    for (const ev of events) {
      if (seen.has(ev.id)) continue
      seen.add(ev.id)
      const m = toMatch(ev)
      if (m && m.minute !== 'FT') matches.push(m)
    }
  }

  return matches
    .sort((a, b) => {
      if (a.isLive !== b.isLive) return a.isLive ? -1 : 1
      return (a.startTimeISO ?? '').localeCompare(b.startTimeISO ?? '')
    })
    .slice(0, MAX_MATCHES)
}

export interface EspnFinalScore {
  home: number
  away: number
}

/**
 * Final scores for fallback (`espn-…`) match ids, for settlement. Takes the
 * id → kickoff-date map from the bet legs' match snapshots, fetches each
 * day's scoreboard once, and returns ONLY matches ESPN marks completed with
 * both scores present — anything less stays pending, never guessed.
 */
export async function fetchEspnResults(
  idToDateISO: Map<string, string>,
): Promise<Map<string, EspnFinalScore>> {
  const out = new Map<string, EspnFinalScore>()
  if (idToDateISO.size === 0) return out

  const byDate = new Map<string, Set<string>>()
  for (const [id, iso] of idToDateISO) {
    const date = (iso ?? '').slice(0, 10)
    if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) continue
    const set = byDate.get(date) ?? new Set<string>()
    set.add(id.replace(ESPN_ID_PREFIX, ''))
    byDate.set(date, set)
  }

  for (const [date, rawIds] of byDate) {
    const events = await fetchDay(date, 120)
    for (const ev of events) {
      if (!rawIds.has(ev.id)) continue
      const comp = ev.competitions?.[0]
      const t = comp?.status?.type
      if (!t?.completed || t.state !== 'post') continue
      if (t.name === 'STATUS_POSTPONED' || t.name === 'STATUS_CANCELED' || t.name === 'STATUS_ABANDONED') continue
      const home = Number(comp?.competitors?.find((c) => c.homeAway === 'home')?.score)
      const away = Number(comp?.competitors?.find((c) => c.homeAway === 'away')?.score)
      if (!Number.isFinite(home) || !Number.isFinite(away)) continue
      out.set(`${ESPN_ID_PREFIX}${ev.id}`, { home, away })
    }
  }

  return out
}
