import { NextResponse } from 'next/server'
import { getMatchesForSport, supportedSports } from '@/lib/api/odds'
import { readCustomMatchesForSport } from '@/lib/custom-matches-store'
import { readMatchOverridesMap, type MatchOverride } from '@/lib/match-overrides-store'
import { deriveMarketBook } from '@/lib/markets'
import { settlePendingBets } from '@/lib/settle-bets'
import { runGoalAlerts } from '@/lib/goal-alerts'
import { liveClockLabel } from '@/lib/match-betting'
import type { Match } from '@/lib/domain-types'

/**
 * A match has finished and should drop off the public listings when it's been
 * explicitly finalised (minute 'FT') OR its auto-clock has run past full time.
 */
function isFinished(m: Match): boolean {
  return m.minute === 'FT' || liveClockLabel(m.startTimeISO, m.sport).label === 'FT'
}

// Auto-settle finished bets off the match feed. This route is polled every 30s
// by every visitor, so while the site has any traffic, a finished match's bets
// settle (and winners get credited) on their own within ~30s — no admin step.
// Throttled per instance; settleBetIfPending makes duplicate runs harmless.
let lastSettleAt = 0
const SETTLE_THROTTLE_MS = 25_000
async function maybeSettleBets(): Promise<void> {
  const now = Date.now()
  if (now - lastSettleAt < SETTLE_THROTTLE_MS) return
  lastSettleAt = now
  try {
    await settlePendingBets()
  } catch (e) {
    console.error('[matches] auto-settle failed (feed still returned):', e)
  }
}

// Push live goal alerts off the same feed traffic (throttled per instance;
// runGoalAlerts is idempotent so duplicate runs don't double-notify).
let lastGoalCheckAt = 0
const GOAL_THROTTLE_MS = 15_000
async function maybeGoalAlerts(): Promise<void> {
  const now = Date.now()
  if (now - lastGoalCheckAt < GOAL_THROTTLE_MS) return
  lastGoalCheckAt = now
  try {
    await runGoalAlerts()
  } catch (e) {
    console.error('[matches] goal alerts failed (feed still returned):', e)
  }
}

// 30s so the ticking minute on live custom matches stays close to real
// time. The Odds API responses inside this handler are cached for 60s.
export const revalidate = 30

function withDerivedMarkets(m: Match): Match {
  if (m.markets) return m
  const derived = deriveMarketBook(m)
  return derived ? { ...m, markets: derived } : m
}

/**
 * Overlay an admin override on top of a match. Only fields the admin
 * explicitly set are applied; everything else stays from the source.
 */
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

function hydrateAll(list: Match[], overrides: Map<string, MatchOverride>): Match[] {
  return list.map((m) => withDerivedMarkets(applyOverride(m, overrides.get(m.id))))
}

function isToday(iso: string | undefined, tzOffsetMinutes: number): boolean {
  if (!iso) return true
  const t = new Date(iso).getTime()
  if (Number.isNaN(t)) return true
  const local = new Date(t - tzOffsetMinutes * 60_000)
  const nowLocal = new Date(Date.now() - tzOffsetMinutes * 60_000)
  return (
    local.getUTCFullYear() === nowLocal.getUTCFullYear() &&
    local.getUTCMonth() === nowLocal.getUTCMonth() &&
    local.getUTCDate() === nowLocal.getUTCDate()
  )
}

function filterToday(matches: Match[], tzOffsetMinutes: number): Match[] {
  return matches.filter((m) => m.isLive || isToday(m.startTimeISO, tzOffsetMinutes))
}

export async function GET(request: Request) {
  const { searchParams } = new URL(request.url)
  const sport = (searchParams.get('sport') ?? 'football').toLowerCase()
  const todayOnly = searchParams.get('today') === '1'
  const tzOffsetMinutes = Number(searchParams.get('tzOffset') ?? '0')
  const tzOffset = Number.isFinite(tzOffsetMinutes) ? tzOffsetMinutes : 0

  if (!supportedSports().includes(sport)) {
    return NextResponse.json(
      { error: `unsupported sport: ${sport}` },
      { status: 400 },
    )
  }

  // Settle finished bets + push goal alerts off the feed (both throttled).
  await Promise.all([maybeSettleBets(), maybeGoalAlerts()])

  // Pull admin overrides up front; one map applies to both custom + API matches.
  // If the table doesn't exist yet (migration not run) we fall back to empty.
  let overrides: Map<string, MatchOverride>
  try {
    overrides = await readMatchOverridesMap()
  } catch {
    overrides = new Map()
  }

  // Hide finished custom matches from the public feed — both ones the admin
  // finalised (minute 'FT') and ones whose auto-clock has run past full time.
  const allCustom = await readCustomMatchesForSport(sport)
  const customMatches = hydrateAll(
    allCustom.filter((m) => !isFinished(m)),
    overrides,
  )
  const maybeFilter = (list: Match[]) => (todayOnly ? filterToday(list, tzOffset) : list)

  try {
    const apiMatches = await getMatchesForSport(sport)
    // Same FT filter we apply to custom matches: hide finished API matches.
    const liveOrUpcomingApi = apiMatches.filter((m) => m.minute !== 'FT')
    return NextResponse.json({
      source: customMatches.length > 0 ? 'mixed' : 'odds-api',
      // Says what is true — the list came out empty — without naming a cause.
      // This used to read "no upcoming events from provider", which blamed
      // the feed for an empty board that the league whitelist, the
      // odds-required filter or a cached empty response could equally have
      // caused, and sent debugging to the provider's dashboard first.
      reason: apiMatches.length === 0 ? 'no matches after filtering' : undefined,
      matches: maybeFilter([...customMatches, ...hydrateAll(liveOrUpcomingApi, overrides)]),
      customCount: customMatches.length,
    })
  } catch (err) {
    const message = err instanceof Error ? err.message : String(err)
    return NextResponse.json({
      source: 'odds-api',
      reason: message,
      matches: maybeFilter(customMatches),
      customCount: customMatches.length,
    })
  }
}
