-- Let a partner (sub-admin) bet on the main site with their own account.
--
-- A sub_admins row can log into the partner dashboard but cannot place a bet:
-- bets, balances and deposits all hang off users. This links one users row to
-- a sub_admin so the partner has a real player wallet, created from the same
-- email and password hash they already sign in with — so /login just works for
-- them, and deposits credit that wallet through the existing rails with no
-- payment code of their own.
--
-- Unique, so a partner can never end up with two betting wallets and a balance
-- split across them. Null for ordinary players, which is nearly every row.

alter table public.users
    add column if not exists linked_sub_admin_id uuid
        references public.sub_admins(id) on delete set null;

create unique index if not exists idx_users_linked_sub_admin
    on public.users (linked_sub_admin_id)
    where linked_sub_admin_id is not null;
