import { NextResponse } from 'next/server'
import { readBets } from '@/lib/bets-store'
import { sumUserMoney } from '@/lib/users-store'

export const dynamic = 'force-dynamic'

export async function GET() {
  // Wallets are denominated in different currencies; these sums are grouped by
  // currency so the admin dashboard can render one row per currency instead
  // of summing GHS+NGN+KES+ZAR into a meaningless number.
  let bets, money
  try {
    ;[bets, money] = await Promise.all([readBets(), sumUserMoney()])
  } catch (e) {
    // Every figure here comes from the database, so there is no partial
    // answer worth assembling. Say what went wrong instead of throwing: a
    // bare 500 in the console told nobody that the project was over its
    // egress quota and had been cut off.
    const detail = e instanceof Error ? e.message : String(e)
    console.error('[admin/stats] load failed:', detail)
    return NextResponse.json(
      { error: 'stats-unavailable', detail },
      { status: 503 },
    )
  }

  const { depositsByCurrency, withdrawalsByCurrency } = money
  const stakesByCurrency: Record<string, number> = {}
  const returnsByCurrency: Record<string, number> = {}
  for (const b of bets) {
    stakesByCurrency[b.currency] = +(((stakesByCurrency[b.currency] ?? 0) + b.stake)).toFixed(2)
    if (b.status === 'won') {
      const ret = b.payout ?? b.potentialWin
      returnsByCurrency[b.currency] = +(((returnsByCurrency[b.currency] ?? 0) + ret)).toFixed(2)
    }
  }

  const open = bets.filter((b) => b.status === 'pending')
  const won = bets.filter((b) => b.status === 'won')
  const lost = bets.filter((b) => b.status === 'lost')

  const totalStake = bets.reduce((s, b) => s + b.stake, 0)
  const openStake = open.reduce((s, b) => s + b.stake, 0)
  const settledStake = won.reduce((s, b) => s + b.stake, 0) + lost.reduce((s, b) => s + b.stake, 0)
  const totalReturns = won.reduce((s, b) => s + (b.payout ?? b.potentialWin), 0)
  const netPL = totalReturns - settledStake
  const winRate = won.length + lost.length > 0
    ? Math.round((won.length / (won.length + lost.length)) * 100)
    : 0

  // Bets per day for the last 7 days (UTC, inclusive of today)
  const dayKey = (d: Date) => d.toISOString().slice(0, 10)
  const byDay: { date: string; count: number; stake: number }[] = []
  const now = new Date()
  for (let i = 6; i >= 0; i--) {
    const d = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate() - i))
    const key = dayKey(d)
    const matching = bets.filter((b) => b.placedAt.slice(0, 10) === key)
    byDay.push({
      date: key,
      count: matching.length,
      stake: matching.reduce((s, b) => s + b.stake, 0),
    })
  }

  // Top leagues by selection count
  const leagueCounts = new Map<string, number>()
  for (const b of bets) {
    for (const s of b.selections) {
      leagueCounts.set(s.match.league, (leagueCounts.get(s.match.league) ?? 0) + 1)
    }
  }
  const topLeagues = Array.from(leagueCounts.entries())
    .sort((a, b) => b[1] - a[1])
    .slice(0, 5)
    .map(([league, picks]) => ({ league, picks }))

  // Recent activity
  const recent = bets.slice(0, 8).map((b) => ({
    id: b.id,
    code: b.code,
    placedAt: b.placedAt,
    settledAt: b.settledAt,
    status: b.status,
    stake: b.stake,
    totalOdds: b.totalOdds,
    potentialWin: b.potentialWin,
    payout: b.payout,
    selectionCount: b.selections.length,
    firstMatch:
      b.selections.length > 0
        ? `${b.selections[0].match.homeTeam} vs ${b.selections[0].match.awayTeam}`
        : '',
  }))

  return NextResponse.json({
    counts: {
      total: bets.length,
      open: open.length,
      won: won.length,
      lost: lost.length,
      users: money.count,
    },
    money: {
      // Legacy single-number fields are kept for backwards compatibility but
      // are only meaningful in a single-currency deployment. New consumers
      // should read the *ByCurrency maps below.
      totalStake: +totalStake.toFixed(2),
      openStake: +openStake.toFixed(2),
      settledStake: +settledStake.toFixed(2),
      totalReturns: +totalReturns.toFixed(2),
      netPL: +netPL.toFixed(2),
      depositsByCurrency,
      withdrawalsByCurrency,
      stakesByCurrency,
      returnsByCurrency,
    },
    winRate,
    byDay,
    topLeagues,
    recent,
  })
}
