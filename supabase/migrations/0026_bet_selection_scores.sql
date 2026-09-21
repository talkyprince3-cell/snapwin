-- Keep the final score on each bet leg.
--
-- A settled ticket showed "-" for the score. bet_selections stored teams,
-- market, pick and result but never the score, so the ticket page could only
-- borrow one from the live feed — and that feed carries current and upcoming
-- fixtures only. Once a game aged out there was nothing left to show, which is
-- exactly when someone opens an old ticket.
--
-- The settle path already reads the final score to grade the leg, so it has the
-- number in hand and merely discarded it. These columns give it somewhere to go.
--
-- Nullable: a leg that is still pending has no score yet, and legs settled
-- before this migration have none recorded.

alter table public.bet_selections
    add column if not exists home_score integer,
    add column if not exists away_score integer;
