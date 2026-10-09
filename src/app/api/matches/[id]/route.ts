import { NextResponse } from 'next/server'
import { getMatchesForSport, supportedSports } from '@/lib/api/odds'
import { getFallbackMatches, ESPN_ID_PREFIX } from '@/lib/api/espn-fallback'
import { readCustomMatchesForSport } from '@/lib/custom-matches-store'
import { readMatchOverridesMap, type MatchOverride } from '@/lib/match-overrides-store'
import { deriveMarketBook } from '@/lib/markets'
import type { Match } from '@/lib/domain-types'

export const revalidate = 30
export const dynamic = 'force-dynamic'

function withDerivedMarkets(m: Match): Match {
  if (m.markets) return m
  const derived = deriveMarketBook(m)
  return derived ? { ...m, markets: derived } : m
}

function applyOverride(m: Match, o: MatchOverride | undefined): Match {
  if (!o) return m
  const next: Match = { ...m }
  if (o.homeScore !== null && o.homeScore !== undefined) next.homeScore = o.homeScore
  if (o.awayScore !== null && o.awayScore !== undefined) next.awayScore = o.awayScore
  if (o.minute !== null && o.minute !== undefined) next.minute = o.minute
  if (o.isLive !== null && o.isLive !== undefined) next.isLive = o.isLive
  if (o.locked) next.locked = true
  if (o.postponed) next.postponed = true
  return next
}

/**
 * Sport-agnostic single-match lookup. Tries `?sport=` first (cheap), then
 * falls back to scanning every supported sport so a link from any feed
 * resolves regardless of which sport the match belongs to.
 */
export async function GET(
  request: Request,
  { params }: { params: Promise<{ id: string }> },
) {
  const { id } = await params
  if (!id) return NextResponse.json({ error: 'id required' }, { status: 400 })

  const { searchParams } = new URL(request.url)
  const hintSport = (searchParams.get('sport') ?? '').toLowerCase()

  let overrides: Map<string, MatchOverride>
  try {
    overrides = await readMatchOverridesMap()
  } catch {
    overrides = new Map()
  }

  // Fallback-feed matches resolve straight from ESPN. Going through
  // getMatchesForSport would lose them the moment the primary feed recovers,
  // 404ing the match page for anyone holding a slip or link to one.
  if (id.startsWith(ESPN_ID_PREFIX)) {
    const fallback = await getFallbackMatches().catch(() => [])
    const match = fallback.find((m) => m.id === id)
    if (match) {
      return NextResponse.json({
        match: withDerivedMarkets(applyOverride(match, overrides.get(id))),
      })
    }
    return NextResponse.json({ error: 'match not found' }, { status: 404 })
  }

  // Try the hinted sport first for speed, then fall through to the rest.
  const sportsToTry = [
    ...(hintSport && supportedSports().includes(hintSport) ? [hintSport] : []),
    ...supportedSports().filter((s) => s !== hintSport),
  ]

  for (const sport of sportsToTry) {
    // Custom matches first — cheapest lookup, no upstream call.
    const customs = await readCustomMatchesForSport(sport).catch(() => [])
    const customMatch = customs.find((m) => m.id === id)
    if (customMatch) {
      const hydrated = withDerivedMarkets(applyOverride(customMatch, overrides.get(id)))
      return NextResponse.json({ match: hydrated })
    }

    // Upstream Odds API
    try {
      const apiMatches = await getMatchesForSport(sport)
      const apiMatch = apiMatches.find((m) => m.id === id)
      if (apiMatch) {
        const hydrated = withDerivedMarkets(applyOverride(apiMatch, overrides.get(id)))
        return NextResponse.json({ match: hydrated })
      }
    } catch {
      // Soft-fail per sport; keep trying the next one.
    }
  }

  return NextResponse.json({ error: 'match not found' }, { status: 404 })
}
