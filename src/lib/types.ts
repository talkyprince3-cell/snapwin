export type Sport = {
  id: string;
  name: string;
  icon: string;
  count: number;
};

export type Market = {
  label: string; // e.g. "1", "X", "2"
  odds: number;
};

export type Match = {
  id: string;
  league: string;
  leagueFlag: string;
  leagueFlagUrl?: string; // real flag image from the feed; emoji is the fallback
  country: string;
  sport: string;
  home: string;
  away: string;
  homeShort: string;
  awayShort: string;
  homeColor: string;
  awayColor: string;
  homeLogo?: string; // real crest URL from the feed; falls back to initials badge
  awayLogo?: string;
  kickoff: string; // ISO-ish display string
  startTimeISO?: string; // full ISO kickoff timestamp, for the live second-clock
  live: boolean;
  minute?: number;
  halfTime?: boolean; // live match currently in the half-time break (show "HT")
  scoreHome?: number;
  scoreAway?: number;
  markets: Market[]; // 1 X 2
  marketCount: number;
  featured?: boolean;
  locked?: boolean; // betting closed (match started / live / admin-locked)
  lockLabel?: string; // short reason shown on the card, e.g. "Started"
};

export type Selection = {
  id: string; // matchId-market
  matchId: string;
  match: string; // "Arsenal vs Chelsea"
  market: string; // "Match Result"
  pick: string; // "Arsenal" / "Draw"
  odds: number;
  /** Competition the fixture belongs to. Persisted with the bet so a ticket
   *  can still name it long after the match has left the live feed. */
  league?: string;
  country?: string;
};

export type Txn = {
  id: string;
  type: "deposit" | "withdrawal" | "winning" | "bet";
  method: string;
  amount: number;
  status: "completed" | "pending" | "failed";
  date: string;
};

export type BetLeg = {
  matchId?: string;
  /** "Home v Away", pre-joined for the compact card. */
  match: string;
  /** Teams kept apart as well, so the ticket page can lay them out itself. */
  home?: string;
  away?: string;
  league?: string;
  country?: string;
  /** e.g. "Match Result" — which market the pick was made in. */
  market?: string;
  /** The chosen outcome, e.g. "Draw" or a team name. */
  pick: string;
  odds: number;
  result: "won" | "lost" | "pending";
};

export type Bet = {
  id: string;
  type: "single" | "multi";
  legs: BetLeg[];
  stake: number;
  totalOdds: number;
  potential: number;
  status: "won" | "lost" | "pending" | "cashout";
  date: string;
  currency?: string;
  verifyCode?: string; // 17-char ticket verification code (won-splash + ticket)
};
