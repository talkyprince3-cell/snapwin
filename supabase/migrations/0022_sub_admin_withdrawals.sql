-- Sub-admin (agent) commission payouts, as requests rather than a silent wipe.
--
-- Until now an admin could only zero a sub-admin's commission balance, which
-- left no record of who asked for what, when it was paid, or how. This table
-- makes a payout a request the agent raises and the admin then approves or
-- rejects, with the money movement and the paying reference recorded.
--
-- Balance is deducted on APPROVAL, not when the request is raised, and
-- balance_deducted_at records that it happened. A rejection refunds only when
-- that stamp is present, so a reject can never hand back money that was never
-- taken — including on a re-run.

create table if not exists public.sub_admin_withdrawals (
    id                  uuid primary key default gen_random_uuid(),
    sub_admin_id        uuid not null references public.sub_admins(id) on delete cascade,
    amount              numeric(18, 2) not null check (amount > 0),
    currency            text not null default 'GHS'
                        check (currency in ('GHS', 'NGN', 'KES', 'ZAR', 'UGX', 'TZS', 'XAF', 'ZMW', 'XOF', 'RWF', 'USD', 'GBP')),
    status              text not null default 'pending'
                        check (status in ('pending', 'completed', 'rejected')),
    -- Where the agent wants the money sent. Free-form so mobile money and bank
    -- payouts share one shape.
    payout_method       text,
    payout_destination  text,
    -- Filled in by the admin at approval time: how it was actually paid.
    payment_method      text,
    payment_reference   text,
    payment_note        text,
    created_at          timestamptz not null default now(),
    processed_at        timestamptz,
    balance_deducted_at timestamptz
);

create index if not exists idx_sa_withdrawals_sub_admin
    on public.sub_admin_withdrawals (sub_admin_id, created_at desc);

-- The admin queue reads pending first; this keeps that scan cheap as the table
-- grows with settled history.
create index if not exists idx_sa_withdrawals_pending
    on public.sub_admin_withdrawals (created_at desc)
    where status = 'pending';

alter table public.sub_admin_withdrawals enable row level security;
-- No anon policy: every read and write goes through the server on the service
-- role key, same as the rest of the money tables.
