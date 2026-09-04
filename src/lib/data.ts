import type { Sport, Match, Txn, Bet } from "./types";

export const sports: Sport[] = [
  { id: "football", name: "Football", icon: "⚽", count: 348 },
  { id: "basketball", name: "Basketball", icon: "🏀", count: 64 },
  { id: "tennis", name: "Tennis", icon: "🎾", count: 41 },
  { id: "cricket", name: "Cricket", icon: "🏏", count: 18 },
  { id: "rugby", name: "Rugby", icon: "🏉", count: 12 },
  { id: "esports", name: "eSports", icon: "🎮", count: 27 },
  { id: "boxing", name: "Boxing", icon: "🥊", count: 6 },
  { id: "volleyball", name: "Volleyball", icon: "🏐", count: 14 },
];

export const competitions = [
  { id: "epl", name: "Premier League", flag: "🏴", count: 10 },
  { id: "laliga", name: "La Liga", flag: "🇪🇸", count: 8 },
  { id: "ucl", name: "Champions League", flag: "🇪🇺", count: 16 },
  { id: "seriea", name: "Serie A", flag: "🇮🇹", count: 7 },
  { id: "bundesliga", name: "Bundesliga", flag: "🇩🇪", count: 6 },
  { id: "ligue1", name: "Ligue 1", flag: "🇫🇷", count: 5 },
  { id: "gpl", name: "Ghana Premier", flag: "🇬🇭", count: 9 },
  { id: "npfl", name: "Nigeria NPFL", flag: "🇳🇬", count: 7 },
];

const mk = (a: number, b: number, c: number) => [
  { label: "1", odds: a },
  { label: "X", odds: b },
  { label: "2", odds: c },
];

export const matches: Match[] = [
  {
    id: "m1", league: "Champions League", leagueFlag: "🇪🇺", country: "Europe", sport: "football",
    home: "Arsenal", away: "Real Madrid", homeShort: "ARS", awayShort: "RMA",
    homeColor: "#ef4444", awayColor: "#f8fafc",
    kickoff: "Live", live: true, minute: 67, scoreHome: 2, scoreAway: 1,
    markets: mk(2.1, 3.4, 3.2), marketCount: 142, featured: true,
  },
  {
    id: "m2", league: "Premier League", leagueFlag: "🏴", country: "England", sport: "football",
    home: "Manchester City", away: "Liverpool", homeShort: "MCI", awayShort: "LIV",
    homeColor: "#60a5fa", awayColor: "#dc2626",
    kickoff: "Live", live: true, minute: 34, scoreHome: 1, scoreAway: 1,
    markets: mk(1.95, 3.8, 3.9), marketCount: 138, featured: true,
  },
  {
    id: "m3", league: "La Liga", leagueFlag: "🇪🇸", country: "Spain", sport: "football",
    home: "Barcelona", away: "Atletico Madrid", homeShort: "BAR", awayShort: "ATM",
    homeColor: "#a855f7", awayColor: "#ef4444",
    kickoff: "Live", live: true, minute: 78, scoreHome: 3, scoreAway: 0,
    markets: mk(1.25, 6.0, 11.0), marketCount: 96,
  },
  {
    id: "m4", league: "Serie A", leagueFlag: "🇮🇹", country: "Italy", sport: "football",
    home: "Inter Milan", away: "Juventus", homeShort: "INT", awayShort: "JUV",
    homeColor: "#3b82f6", awayColor: "#f8fafc",
    kickoff: "Today · 18:30", live: false,
    markets: mk(2.3, 3.1, 3.0), marketCount: 124,
  },
  {
    id: "m5", league: "Premier League", leagueFlag: "🏴", country: "England", sport: "football",
    home: "Chelsea", away: "Tottenham", homeShort: "CHE", awayShort: "TOT",
    homeColor: "#2563eb", awayColor: "#f8fafc",
    kickoff: "Today · 20:00", live: false,
    markets: mk(2.05, 3.5, 3.4), marketCount: 131,
  },
  {
    id: "m6", league: "Bundesliga", leagueFlag: "🇩🇪", country: "Germany", sport: "football",
    home: "Bayern Munich", away: "Dortmund", homeShort: "BAY", awayShort: "BVB",
    homeColor: "#dc2626", awayColor: "#facc15",
    kickoff: "Today · 17:30", live: false,
    markets: mk(1.65, 4.2, 4.6), marketCount: 118,
  },
  {
    id: "m7", league: "Ghana Premier", leagueFlag: "🇬🇭", country: "Ghana", sport: "football",
    home: "Hearts of Oak", away: "Asante Kotoko", homeShort: "HOA", awayShort: "KOT",
    homeColor: "#dc2626", awayColor: "#facc15",
    kickoff: "Tomorrow · 15:00", live: false,
    markets: mk(2.4, 2.9, 3.1), marketCount: 64,
  },
  {
    id: "m8", league: "Ligue 1", leagueFlag: "🇫🇷", country: "France", sport: "football",
    home: "PSG", away: "Marseille", homeShort: "PSG", awayShort: "OM",
    homeColor: "#1e3a8a", awayColor: "#38bdf8",
    kickoff: "Tomorrow · 20:45", live: false,
    markets: mk(1.45, 4.6, 6.5), marketCount: 109,
  },
  {
    id: "m9", league: "NPFL", leagueFlag: "🇳🇬", country: "Nigeria", sport: "football",
    home: "Enyimba", away: "Rivers United", homeShort: "ENY", awayShort: "RIV",
    homeColor: "#16a34a", awayColor: "#2563eb",
    kickoff: "Tomorrow · 16:00", live: false,
    markets: mk(2.2, 2.95, 3.3), marketCount: 52,
  },
  {
    id: "m10", league: "Champions League", leagueFlag: "🇪🇺", country: "Europe", sport: "football",
    home: "AC Milan", away: "Manchester Utd", homeShort: "MIL", awayShort: "MUN",
    homeColor: "#dc2626", awayColor: "#ef4444",
    kickoff: "Sat · 20:00", live: false,
    markets: mk(2.6, 3.3, 2.7), marketCount: 140,
  },
  {
    id: "m11", league: "La Liga", leagueFlag: "🇪🇸", country: "Spain", sport: "football",
    home: "Sevilla", away: "Valencia", homeShort: "SEV", awayShort: "VAL",
    homeColor: "#dc2626", awayColor: "#f97316",
    kickoff: "Sat · 18:30", live: false,
    markets: mk(2.15, 3.2, 3.5), marketCount: 88,
  },
  {
    id: "m12", league: "Premier League", leagueFlag: "🏴", country: "England", sport: "football",
    home: "Newcastle", away: "Aston Villa", homeShort: "NEW", awayShort: "AVL",
    homeColor: "#f8fafc", awayColor: "#c2410c",
    kickoff: "Sun · 16:30", live: false,
    markets: mk(2.0, 3.4, 3.7), marketCount: 102,
  },
];

export const liveMatches = matches.filter((m) => m.live);
export const todayMatches = matches.filter((m) => m.kickoff.startsWith("Today"));
export const tomorrowMatches = matches.filter((m) => m.kickoff.startsWith("Tomorrow"));
export const weekMatches = matches.filter(
  (m) => !m.live && !m.kickoff.startsWith("Today") && !m.kickoff.startsWith("Tomorrow"),
);

export function getMatch(id: string) {
  return matches.find((m) => m.id === id);
}

export const tickerItems = [
  "⚽ Arsenal 2–1 Real Madrid · 67'",
  "🔥 Man City 1–1 Liverpool · 34'",
  "💥 Barcelona 3–0 Atletico · 78'",
  "🎯 Boosted: PSG to win @ 1.55",
  "🏆 UCL Final acca paying GH₵ 4,200",
  "⚡ Cash out live on 142 markets",
];

export const promos = [
  { tone: "brand", eyebrow: "⚡ Welcome Bonus", title: "100% Match Bonus", sub: "Up to GH₵ 500 on your first deposit." , cta: "Claim Now"},
  { tone: "magenta", eyebrow: "🛡️ Acca Insurance", title: "One Leg Down? Refund.", sub: "5+ selection accumulators covered.", cta: "Learn More" },
  { tone: "emerald", eyebrow: "🎯 Same Game Parlay", title: "Build From One Match", sub: "Combine markets into one slip.", cta: "Build Bet" },
  { tone: "gold", eyebrow: "📊 Daily Boost", title: "Enhanced Odds", sub: "Selected markets boosted ×2 today.", cta: "View All" },
];

export const stats = [
  { val: "348", label: "Live Now", chg: "+24", up: true },
  { val: "1.85", label: "Best Odds", chg: "↑ 0.05", up: true },
  { val: "4,820", label: "Bets Today", chg: "+12%", up: true },
  { val: "23", label: "Markets / Match", chg: "Avg", up: true },
];

export const transactions: Txn[] = [
  { id: "TXN-90218", type: "deposit", method: "MTN MoMo", amount: 200, status: "completed", date: "Jun 6, 09:12" },
  { id: "TXN-90201", type: "winning", method: "Bet payout", amount: 540.5, status: "completed", date: "Jun 5, 22:48" },
  { id: "TXN-90188", type: "bet", method: "Multi bet", amount: -50, status: "completed", date: "Jun 5, 20:10" },
  { id: "TXN-90175", type: "withdrawal", method: "Vodafone Cash", amount: -300, status: "pending", date: "Jun 5, 14:02" },
  { id: "TXN-90150", type: "deposit", method: "Telecel Cash", amount: 100, status: "completed", date: "Jun 4, 18:33" },
  { id: "TXN-90142", type: "winning", method: "Bet payout", amount: 128, status: "completed", date: "Jun 4, 11:20" },
  { id: "TXN-90130", type: "bet", method: "Single bet", amount: -30, status: "completed", date: "Jun 3, 21:55" },
  { id: "TXN-90118", type: "withdrawal", method: "MTN MoMo", amount: -150, status: "failed", date: "Jun 3, 16:40" },
];

export const bets: Bet[] = [
  {
    id: "NW-7F2A91", type: "multi", stake: 50, totalOdds: 12.4, potential: 620, status: "pending",
    date: "Jun 6, 12:30",
    legs: [
      { match: "Arsenal vs Real Madrid", pick: "Arsenal", odds: 2.1, result: "pending" },
      { match: "Man City vs Liverpool", pick: "Over 2.5", odds: 1.85, result: "won" },
      { match: "PSG vs Marseille", pick: "PSG", odds: 1.45, result: "pending" },
      { match: "Bayern vs Dortmund", pick: "BTTS", odds: 1.7, result: "pending" },
    ],
  },
  {
    id: "NW-6A1C04", type: "single", stake: 100, totalOdds: 1.95, potential: 195, status: "won",
    date: "Jun 5, 19:00",
    legs: [{ match: "Chelsea vs Tottenham", pick: "Chelsea", odds: 1.95, result: "won" }],
  },
  {
    id: "NW-5B8E77", type: "multi", stake: 30, totalOdds: 8.2, potential: 246, status: "lost",
    date: "Jun 4, 15:20",
    legs: [
      { match: "Inter vs Juventus", pick: "Draw", odds: 3.1, result: "lost" },
      { match: "Sevilla vs Valencia", pick: "Sevilla", odds: 2.15, result: "won" },
    ],
  },
  {
    id: "NW-4D3F12", type: "single", stake: 80, totalOdds: 2.6, potential: 208, status: "cashout",
    date: "Jun 3, 22:10",
    legs: [{ match: "AC Milan vs Man Utd", pick: "Man Utd", odds: 2.6, result: "pending" }],
  },
];
