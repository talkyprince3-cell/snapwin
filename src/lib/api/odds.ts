import type { Match, MarketBook, OverUnderLine } from '@/lib/domain-types'
import { deriveMarketBook, mergeMarketBook } from '@/lib/markets'

/**
 * Upstream: API-Football v3 (https://www.api-football.com).
 * Only the football host is wired up — basketball/tennis/etc require separate
 * API-Sports subscriptions (different hosts) and currently return [].
 *
 * IMPORTANT: API-Football enforces an optional IP allowlist per account.
 * If you see `{ errors: { Ip: "This IP is not allowed..." } }` go to the
 * dashboard → "My Access" and clear the IP restriction — Vercel Functions
 * use dynamic IPs that cannot be reliably whitelisted.
 */
const API_BASE = 'https://v3.football.api-sports.io'

/**
 * Whitelisted competition IDs — mirrors the leagues The Odds API was
 * configured to fetch. Look these up at https://www.api-football.com/leagues.
 */
const WHITELISTED_LEAGUE_IDS = new Set<number>([
  39, // Premier League
  40, // EFL Championship
  179, // Scottish Premiership
  140, // La Liga
  135, // Serie A
  78, // Bundesliga
  61, // Ligue 1
  88, // Eredivisie
  94, // Primeira Liga
  144, // Belgium Pro League
  203, // Süper Lig (Turkey)
  197, // Super League 1 (Greece)
  207, // Swiss Super League
  218, // Austrian Bundesliga
  119, // Danish Superliga
  103, // Eliteserien (Norway)
  113, // Allsvenskan (Sweden)
  106, // Ekstraklasa (Poland)
  // UEFA / international
  2, // Champions League
  3, // Europa League
  848, // Europa Conference League
  5, // Nations League
  1, // World Cup
  4, // Euro Championship
  // Americas
  253, // MLS
  262, // Liga MX
  71, // Brazil Serie A
  128, // Liga Profesional (Argentina)
  265, // Primera División (Chile)
  13, // Copa Libertadores
  9, // Copa America
  // Asia / Oceania
  98, // J1 League
  292, // K League 1
  169, // Chinese Super League
  188, // A-League
  // Friendlies & international windows
  10, // International friendlies
  667, // Club friendlies
])

// API-Football uses different bet IDs for pre-match (/odds) and live
// (/odds/live). These sets union both so averageOdds works for either.
const MATCH_WINNER_BET_IDS = new Set<number>([
  1, // pre-match: Match Winner
  59, // live: Fulltime Result
])
const OVER_UNDER_BET_IDS = new Set<number>([
  5, // pre-match: Goals Over/Under
  25, // live: Match Goals
])
const BTTS_BET_IDS = new Set<number>([
  8, // pre-match: Both Teams Score
  69, // live: Both Teams to Score
])
const DOUBLE_CHANCE_BET_IDS = new Set<number>([
  12, // pre-match: Double Chance
  72, // live: Double Chance
])

interface ApiResponse<T> {
  errors: unknown
  results: number
  paging: { current: number; total: number }
  response: T
}

interface FixtureStatus {
  long: string
  short: string
  elapsed: number | null
}

interface Fixture {
  fixture: {
    id: number
    date: string
    timestamp: number
    status: FixtureStatus
  }
  league: {
    id: number
    name: string
    country: string
    season: number
    flag: string | null
  }
  teams: {
    home: { id: number; name: string; logo: string }
    away: { id: number; name: string; logo: string }
  }
  goals: { home: number | null; away: number | null }
}

interface OddsValue {
  value: string
  odd: string
}
interface OddsBet {
  id: number
  name: string
  values: OddsValue[]
}
interface OddsBookmaker {
  id: number
  name: string
  bets: OddsBet[]
}
interface OddsRow {
  fixture: { id: number }
  league: { id: number; season: number }
  bookmakers: OddsBookmaker[]
}

/**
 * /odds/live has a different shape from /odds: no bookmaker wrapper, and
 * bet types live under `odds` (not `bookmakers[].bets`). We normalize live
 * rows into the OddsRow shape so averageOdds can consume both.
 */
interface LiveOddsValue {
  value: string
  odd: string
  suspended?: boolean
}
interface LiveOddsBet {
  id: number
  name: string
  values: LiveOddsValue[]
}
interface LiveOddsRow {
  fixture: { id: number }
  league: { id: number; season: number }
  odds: LiveOddsBet[]
}

// Status codes that mean "ball is in play" — see
// https://www.api-football.com/documentation-v3#section/Introduction/Status
const LIVE_STATUSES = new Set(['1H', '2H', 'HT', 'ET', 'BT', 'P', 'LIVE'])
const FINISHED_STATUSES = new Set([
  'FT',
  'AET',
  'PEN',
  'PST',
  'CANC',
  'ABD',
  'AWD',
  'WO',
])

async function apiFetch<T>(
  path: string,
  apiKey: string,
  revalidateSeconds: number,
): Promise<ApiResponse<T> | null> {
  const res = await fetch(`${API_BASE}${path}`, {
    headers: { 'x-apisports-key': apiKey },
    next: { revalidate: revalidateSeconds },
  })
  if (!res.ok) {
    if (res.status === 401 || res.status === 403) {
      throw new Error(
        `API-Football auth failed (${res.status}) — check API_FOOTBALL_KEY`,
      )
    }
    if (res.status === 429) throw new Error('API-Football quota exceeded (429)')
    return null
  }
  const json = (await res.json()) as ApiResponse<T>
  // API-Football returns 200 even for IP-block / quota errors; the failure
  // shows up inside `errors`. Surface IP errors loudly since they're the
  // single most common gotcha on a fresh account.
  if (json.errors && typeof json.errors === 'object' && !Array.isArray(json.errors)) {
    const errs = json.errors as Record<string, string>
    const ipMsg = errs.Ip ?? errs.ip
    if (ipMsg) throw new Error(`API-Football: ${ipMsg}`)
    // Quota: API-Football returns 200 with errors.requests when the daily
    // request limit is hit. Surface it loudly instead of silently showing
    // "no events" — it means the plan needs upgrading (or the day to reset).
    if (errs.requests) throw new Error(`API-Football daily request limit reached — ${errs.requests}`)
    const tokenMsg = errs.token ?? errs.Token ?? errs.plan
    if (tokenMsg) throw new Error(`API-Football: ${tokenMsg}`)
  }
  return json
}

async function fetchFixturesByDate(date: string, apiKey: string): Promise<Fixture[]> {
  // Pre-match fixtures barely change during the day — cache 30 min to spare the
  // (free-tier) request quota.
  const json = await apiFetch<Fixture[]>(`/fixtures?date=${date}`, apiKey, 1800)
  return json?.response ?? []
}

async function fetchLiveFixtures(apiKey: string): Promise<Fixture[]> {
  const json = await apiFetch<Fixture[]>(`/fixtures?live=all`, apiKey, 300)
  return json?.response ?? []
}

async function fetchOddsForLeague(
  leagueId: number,
  season: number,
  date: string,
  apiKey: string,
): Promise<OddsRow[]> {
  const all: OddsRow[] = []
  const MAX_PAGES = 5
  for (let page = 1; page <= MAX_PAGES; page++) {
    const json = await apiFetch<OddsRow[]>(
      `/odds?league=${leagueId}&season=${season}&date=${date}&page=${page}`,
      apiKey,
      1800,
    )
    if (!json) break
    all.push(...json.response)
    if (json.paging.current >= json.paging.total) break
  }
  return all
}

async function fetchLiveOdds(apiKey: string): Promise<OddsRow[]> {
  // /odds/live with no params returns every currently in-play game globally
  // in a single call — much cheaper than per-league when we want to include
  // any live football match, not just whitelisted leagues.
  const json = await apiFetch<LiveOddsRow[]>(`/odds/live`, apiKey, 300)
  const rows = json?.response ?? []
  return rows.map((r) => ({
    fixture: r.fixture,
    league: r.league,
    bookmakers: [
      {
        id: 0,
        name: 'live',
        bets: (r.odds ?? []).map((b) => ({
          id: b.id,
          name: b.name,
          values: (b.values ?? []).filter((v) => !v.suspended),
        })),
      },
    ],
  }))
}

interface AveragedOdds {
  matchWinner: { home: number; draw: number; away: number }
  overUnder: OverUnderLine[]
  btts: { yes: number; no: number } | null
  doubleChance: { homeOrDraw: number; homeOrAway: number; drawOrAway: number } | null
}

function averageOdds(rows: OddsRow[]): AveragedOdds {
  let homeS = 0,
    homeN = 0,
    drawS = 0,
    drawN = 0,
    awayS = 0,
    awayN = 0
  let yesS = 0,
    yesN = 0,
    noS = 0,
    noN = 0
  let hdS = 0,
    hdN = 0,
    haS = 0,
    haN = 0,
    daS = 0,
    daN = 0
  const totals = new Map<
    number,
    { overS: number; overN: number; underS: number; underN: number }
  >()

  for (const row of rows) {
    if (!row || !Array.isArray(row.bookmakers)) continue
    for (const bm of row.bookmakers) {
      if (!bm || !Array.isArray(bm.bets)) continue
      for (const bet of bm.bets) {
        if (!bet || !Array.isArray(bet.values)) continue
        if (MATCH_WINNER_BET_IDS.has(bet.id)) {
          for (const v of bet.values) {
            const odd = parseFloat(v.odd)
            if (!Number.isFinite(odd)) continue
            const label = v.value.toLowerCase().trim()
            // Pre-match returns "Home"/"Draw"/"Away"; some live providers
            // return "1"/"X"/"2" — handle both.
            if (label === 'home' || label === '1') {
              homeS += odd
              homeN++
            } else if (label === 'draw' || label === 'x') {
              drawS += odd
              drawN++
            } else if (label === 'away' || label === '2') {
              awayS += odd
              awayN++
            }
          }
        } else if (OVER_UNDER_BET_IDS.has(bet.id)) {
          for (const v of bet.values) {
            const odd = parseFloat(v.odd)
            if (!Number.isFinite(odd)) continue
            const m = v.value.match(/(over|under)\s+(-?\d+(?:\.\d+)?)/i)
            if (!m) continue
            const dir = m[1].toLowerCase()
            const line = parseFloat(m[2])
            const entry =
              totals.get(line) ?? { overS: 0, overN: 0, underS: 0, underN: 0 }
            if (dir === 'over') {
              entry.overS += odd
              entry.overN++
            } else {
              entry.underS += odd
              entry.underN++
            }
            totals.set(line, entry)
          }
        } else if (BTTS_BET_IDS.has(bet.id)) {
          for (const v of bet.values) {
            const odd = parseFloat(v.odd)
            if (!Number.isFinite(odd)) continue
            const label = v.value.toLowerCase().trim()
            if (label === 'yes') {
              yesS += odd
              yesN++
            } else if (label === 'no') {
              noS += odd
              noN++
            }
          }
        } else if (DOUBLE_CHANCE_BET_IDS.has(bet.id)) {
          for (const v of bet.values) {
            const odd = parseFloat(v.odd)
            if (!Number.isFinite(odd)) continue
            const label = v.value.toLowerCase().replace(/\s+/g, '').trim()
            if (label === 'home/draw' || label === '1x') {
              hdS += odd
              hdN++
            } else if (label === 'home/away' || label === '12') {
              haS += odd
              haN++
            } else if (label === 'draw/away' || label === 'x2') {
              daS += odd
              daN++
            }
          }
        }
      }
    }
  }

  const overUnder: OverUnderLine[] = [...totals.entries()]
    .filter(([, v]) => v.overN > 0 && v.underN > 0)
    .map(([line, v]) => ({
      line,
      over: +(v.overS / v.overN).toFixed(2),
      under: +(v.underS / v.underN).toFixed(2),
    }))
    .sort((a, b) => a.line - b.line)

  return {
    matchWinner: {
      home: homeN ? +(homeS / homeN).toFixed(2) : 0,
      draw: drawN ? +(drawS / drawN).toFixed(2) : 0,
      away: awayN ? +(awayS / awayN).toFixed(2) : 0,
    },
    overUnder,
    btts:
      yesN > 0 && noN > 0
        ? { yes: +(yesS / yesN).toFixed(2), no: +(noS / noN).toFixed(2) }
        : null,
    doubleChance:
      hdN > 0 || haN > 0 || daN > 0
        ? {
            homeOrDraw: hdN ? +(hdS / hdN).toFixed(2) : 0,
            homeOrAway: haN ? +(haS / haN).toFixed(2) : 0,
            drawOrAway: daN ? +(daS / daN).toFixed(2) : 0,
          }
        : null,
  }
}

function statusToClock(status: FixtureStatus): {
  minute: string | undefined
  isLive: boolean
} {
  const short = status.short
  if (FINISHED_STATUSES.has(short)) return { minute: 'FT', isLive: false }
  if (short === 'HT') return { minute: 'HT', isLive: true }
  if (LIVE_STATUSES.has(short)) {
    const elapsed = status.elapsed ?? 0
    return { minute: `${elapsed}'`, isLive: true }
  }
  return { minute: undefined, isLive: false }
}

function toMatch(fixture: Fixture, oddsRows: OddsRow[]): Match {
  const odds = averageOdds(oddsRows)
  const { minute, isLive } = statusToClock(fixture.fixture.status)
  const start = new Date(fixture.fixture.date)

  const base: Match = {
    id: String(fixture.fixture.id),
    league: fixture.league.name,
    country: fixture.league.country ?? 'International',
    homeTeam: fixture.teams.home.name,
    awayTeam: fixture.teams.away.name,
    isLive,
    startTime: isLive
      ? undefined
      : start.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }),
    startTimeISO: fixture.fixture.date,
    minute,
    odds: odds.matchWinner,
    sport: 'football',
  }
  if (typeof fixture.goals.home === 'number') base.homeScore = fixture.goals.home
  if (typeof fixture.goals.away === 'number') base.awayScore = fixture.goals.away
  if (fixture.teams.home.logo) base.homeFlagUrl = fixture.teams.home.logo
  if (fixture.teams.away.logo) base.awayFlagUrl = fixture.teams.away.logo
  if (fixture.league.flag) base.countryFlagUrl = fixture.league.flag

  const derived = deriveMarketBook(base)
  if (derived) {
    const partial: Partial<MarketBook> = {}
    if (odds.overUnder.length > 0) partial.overUnder = odds.overUnder
    if (odds.btts) partial.btts = odds.btts
    if (odds.doubleChance) partial.doubleChance = odds.doubleChance
    base.markets = mergeMarketBook(derived, partial)
  }

  return base
}

export async function getMatchesForSport(sport: string): Promise<Match[]> {
  if (sport !== 'football') return []

  const apiKey = process.env.API_FOOTBALL_KEY
  if (!apiKey) throw new Error('API_FOOTBALL_KEY missing')

  // Four days, not one. The home page has Highlights / Tomorrow / This week
  // sections, and fetching only today left the last two permanently empty and
  // emptied the first as the day's games finished — by evening the board
  // looked broken. Fixtures are one call per day and odds are only fetched
  // for leagues that actually have fixtures, so the extra days are cheap.
  const DAYS_AHEAD = 4
  const dates: string[] = []
  for (let i = 0; i < DAYS_AHEAD; i++) {
    dates.push(new Date(Date.now() + i * 86_400_000).toISOString().slice(0, 10))
  }

  const [fixturesByDate, fixturesLive] = await Promise.all([
    Promise.all(dates.map((d) => fetchFixturesByDate(d, apiKey))),
    fetchLiveFixtures(apiKey),
  ])

  const byId = new Map<number, Fixture>()
  for (const list of fixturesByDate) for (const f of list) byId.set(f.fixture.id, f)
  // Live fixtures take precedence — they carry the fresher elapsed minute.
  for (const f of fixturesLive) byId.set(f.fixture.id, f)

  // Whitelist applies to pre-match only. For live we surface every football
  // match that's in-play upstream — by late season most top European leagues
  // are off, and bettors expect to see whatever's currently happening on
  // other platforms (lower divisions, friendlies, Americas/Asia leagues).
  const liveFixtureIds = new Set(
    fixturesLive.map((f) => f.fixture.id),
  )
  const fixtures = [...byId.values()].filter(
    (f) =>
      liveFixtureIds.has(f.fixture.id) || WHITELISTED_LEAGUE_IDS.has(f.league.id),
  )
  if (fixtures.length === 0) return []

  // Pre-match odds still fetched per league+season — keeps the upstream call
  // count bounded by the whitelist size. Live odds fetched globally in one
  // call so we don't have to walk every live league individually.
  // Keyed by date as well as league: the odds endpoint is per-date, so a
  // fixture three days out needs its own call rather than today's book.
  const preMatchKeys = new Map<string, { leagueId: number; season: number; date: string }>()
  for (const f of fixtures) {
    if (!WHITELISTED_LEAGUE_IDS.has(f.league.id)) continue
    const date = (f.fixture.date ?? '').slice(0, 10)
    if (!date) continue
    preMatchKeys.set(`${f.league.id}:${f.league.season}:${date}`, {
      leagueId: f.league.id,
      season: f.league.season,
      date,
    })
  }

  const [preMatchResults, liveOdds] = await Promise.all([
    Promise.allSettled(
      [...preMatchKeys.values()].map((k) =>
        fetchOddsForLeague(k.leagueId, k.season, k.date, apiKey),
      ),
    ),
    fetchLiveOdds(apiKey).catch(() => [] as OddsRow[]),
  ])

  const oddsMap = new Map<number, OddsRow[]>()
  for (const r of preMatchResults) {
    if (r.status !== 'fulfilled') continue
    for (const row of r.value) {
      const list = oddsMap.get(row.fixture.id) ?? []
      list.push(row)
      oddsMap.set(row.fixture.id, list)
    }
  }
  // Live odds replace pre-match: once a game kicks off the pre-match book
  // disappears upstream anyway, and live prices are fresher.
  for (const row of liveOdds) oddsMap.set(row.fixture.id, [row])

  return fixtures
    .map((f) => toMatch(f, oddsMap.get(f.fixture.id) ?? []))
    .filter((m) => m.odds.home > 0 && m.odds.away > 0)
    .sort((a, b) => {
      if (a.isLive !== b.isLive) return a.isLive ? -1 : 1
      return (a.startTime ?? '').localeCompare(b.startTime ?? '')
    })
}

export function supportedSports(): string[] {
  // Football is the only sport with a real upstream right now (API-Football's
  // other sport hosts require separate subscriptions). The non-football
  // sports are kept here so the UI's sport tabs don't 400 — getMatchesForSport
  // returns [] for them and the page renders custom matches / empty state.
  return ['football', 'basketball', 'tennis', 'baseball', 'hockey', 'volleyball']
}
