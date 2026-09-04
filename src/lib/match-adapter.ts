// Bridges the backend/API match model (`@/lib/domain-types`) to the UI match
// model the public components were built around (`@/lib/types`). The API feed
// (API-Football, via `/api/matches`) speaks the domain shape; cards, the home
// page, and the match detail page all speak the UI shape. Everything in here
// is pure mapping — no fetching.

import type { Match as ApiMatch, MarketBook } from '@/lib/domain-types'
import type { Match as UiMatch, Market } from '@/lib/types'
import { getCountryFlag } from '@/lib/country-flags'
import { getBettingState, isLiveNow, liveMinuteFromKickoff, isHalfTime } from '@/lib/match-betting'

// Short, user-facing label for why betting is closed on a match.
const LOCK_LABEL: Record<string, string> = {
  'starting-soon': 'Starts soon',
  started: 'Started',
  finished: 'Ended',
  'admin-locked': 'Locked',
  postponed: 'Postponed',
}

// Deterministic team-badge palette. Same name always lands on the same colour
// so a team looks consistent across renders (the mock data hand-picked these;
// we approximate by hashing the name into the palette).
const BADGE_COLORS = [
  '#ef4444', '#3b82f6', '#a855f7', '#f97316', '#16a34a',
  '#facc15', '#06b6d4', '#dc2626', '#60a5fa', '#c2410c',
  '#f8fafc', '#38bdf8',
]

function hash(str: string): number {
  let h = 0
  for (let i = 0; i < str.length; i++) {
    h = (h << 5) - h + str.charCodeAt(i)
    h |= 0
  }
  return Math.abs(h)
}

function colorFor(name: string): string {
  return BADGE_COLORS[hash(name) % BADGE_COLORS.length]
}

/** Three-letter badge code, mirroring the mock convention (ARS, MCI, RMA). */
function shortCode(name: string): string {
  const words = name.replace(/[^a-zA-Z ]/g, '').trim().split(/\s+/).filter(Boolean)
  if (words.length === 0) return '?'
  if (words.length === 1) return words[0].slice(0, 3).toUpperCase()
  let code = words.map((w) => w[0]).join('').toUpperCase()
  if (code.length < 3) code = (code + words[0].slice(1)).toUpperCase()
  return code.slice(0, 3)
}

/** API minute is a string ("45'", "HT", "FT"); the UI card wants a number. */
function parseMinute(minute: string | undefined): number | undefined {
  if (!minute) return undefined
  const n = parseInt(minute, 10)
  return Number.isFinite(n) ? n : undefined
}

/** Rough "markets available" count for the detail-page stat ribbon. */
function countMarkets(book: MarketBook | undefined): number {
  if (!book) return 3 // just 1X2
  let n = 3 // match winner
  if (book.doubleChance) n += 3
  if (book.overUnder?.length) n += book.overUnder.length * 2
  if (book.btts) n += 2
  if (book.drawNoBet) n += 2
  if (book.correctScore?.length) n += book.correctScore.length
  if (book.halfTimeFullTime?.length) n += book.halfTimeFullTime.length
  if (book.firstHalf1X2) n += 3
  return n
}

/**
 * Format a kickoff time for display IN THE VIEWER'S TIMEZONE. The API feed
 * formats startTime server-side (UTC on the server), which reads wrong for
 * anyone not on UTC — so we always reformat from the full ISO timestamp on the
 * client. Shows just the time for today, with a day hint otherwise.
 */
function formatKickoff(iso: string | undefined, fallback: string | undefined): string {
  if (!iso) return fallback ?? 'TBD'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return fallback ?? 'TBD'
  const time = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false })
  const startOfDay = (x: Date) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime()
  const dayDiff = Math.round((startOfDay(d) - startOfDay(new Date())) / 86_400_000)
  if (dayDiff === 0) return time
  if (dayDiff === 1) return `Tmrw ${time}`
  if (dayDiff === -1) return `Yest ${time}`
  const date = d.toLocaleDateString([], { day: 'numeric', month: 'short' })
  return `${date} · ${time}`
}

const ONE_X_TWO = (o: ApiMatch['odds']): Market[] => [
  { label: '1', odds: o.home },
  { label: 'X', odds: o.draw },
  { label: '2', odds: o.away },
]

/**
 * Map a single API match to the UI shape consumed by MatchCard / detail page.
 * Prefers real crest URLs from the feed where available; otherwise falls back
 * to a generated initials badge + country flag emoji.
 */
export function apiMatchToUi(api: ApiMatch): UiMatch {
  // Show the match as live the moment it kicks off, even if the feed's isLive
  // flag hasn't caught up yet.
  const live = isLiveNow(api)
  const kickoff = live ? 'Live' : formatKickoff(api.startTimeISO, api.startTime)
  // When live but the feed gave no clock, derive the minute from kickoff.
  const minute = parseMinute(api.minute) ?? (live ? liveMinuteFromKickoff(api) ?? undefined : undefined)
  // Betting closes once a match starts / goes live (or an admin locks it).
  const bet = getBettingState(api)
  return {
    id: api.id,
    league: api.league,
    leagueFlag: getCountryFlag(api.country),
    leagueFlagUrl: api.countryFlagUrl,
    country: api.country,
    sport: api.sport ?? 'football',
    home: api.homeTeam,
    away: api.awayTeam,
    homeShort: shortCode(api.homeTeam),
    awayShort: shortCode(api.awayTeam),
    homeColor: colorFor(api.homeTeam),
    awayColor: colorFor(api.awayTeam),
    homeLogo: api.homeFlagUrl,
    awayLogo: api.awayFlagUrl,
    kickoff,
    startTimeISO: api.startTimeISO,
    live,
    minute,
    halfTime: live ? isHalfTime(api) : false,
    scoreHome: api.homeScore,
    scoreAway: api.awayScore,
    markets: ONE_X_TWO(api.odds),
    marketCount: countMarkets(api.markets),
    featured: false,
    locked: bet.closed,
    lockLabel: bet.reason ? LOCK_LABEL[bet.reason] : undefined,
  }
}

export type MarketGroup = {
  title: string
  picks: { label: string; odds: number }[]
  cols?: number
}

/**
 * Build the detail-page market groups from the real MarketBook. Only groups
 * the feed actually priced are emitted, so we never render fabricated odds.
 */
export function buildMarketGroupsFromApi(api: ApiMatch): MarketGroup[] {
  const groups: MarketGroup[] = []
  const book = api.markets

  groups.push({
    title: 'Match Result (1X2)',
    cols: 3,
    picks: [
      { label: api.homeTeam, odds: api.odds.home },
      { label: 'Draw', odds: api.odds.draw },
      { label: api.awayTeam, odds: api.odds.away },
    ],
  })

  if (book?.doubleChance) {
    groups.push({
      title: 'Double Chance',
      cols: 3,
      picks: [
        { label: `${api.homeTeam} or Draw`, odds: book.doubleChance.homeOrDraw },
        { label: 'Home or Away', odds: book.doubleChance.homeOrAway },
        { label: `${api.awayTeam} or Draw`, odds: book.doubleChance.drawOrAway },
      ],
    })
  }

  if (book?.overUnder?.length) {
    groups.push({
      title: 'Total Goals (Over / Under)',
      cols: 2,
      picks: book.overUnder.flatMap((l) => [
        { label: `Over ${l.line}`, odds: l.over },
        { label: `Under ${l.line}`, odds: l.under },
      ]),
    })
  }

  if (book?.btts) {
    groups.push({
      title: 'Both Teams To Score',
      cols: 2,
      picks: [
        { label: 'Yes', odds: book.btts.yes },
        { label: 'No', odds: book.btts.no },
      ],
    })
  }

  if (book?.drawNoBet) {
    groups.push({
      title: 'Draw No Bet',
      cols: 2,
      picks: [
        { label: api.homeTeam, odds: book.drawNoBet.home },
        { label: api.awayTeam, odds: book.drawNoBet.away },
      ],
    })
  }

  if (book?.correctScore?.length) {
    groups.push({
      title: 'Correct Score',
      cols: 3,
      picks: book.correctScore.map((s) => ({ label: s.score, odds: s.odds })),
    })
  }

  if (book?.halfTimeFullTime?.length) {
    groups.push({
      title: 'Half Time / Full Time',
      cols: 3,
      picks: book.halfTimeFullTime.map((h) => ({ label: h.label, odds: h.odds })),
    })
  }

  return groups
}
