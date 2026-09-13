-- Make the partner commission rate configurable instead of a code constant.
--
-- Until now every partner earned COMMISSION_RATE, compiled in, so changing one
-- partner's cut meant a deploy and changed everyone's. This adds the three
-- controls the reference operates the programme with:
--
--   commission_pct          per-partner override, null = use the global default
--   commission_pause_exempt keeps earning while the programme is paused
--   app_settings keys       the global default, and the pause switch itself
--
-- Rates are stored as percentages (0-100) to match what an operator types,
-- and are converted to a fraction at the point of calculation.

alter table public.sub_admins
    add column if not exists commission_pct numeric(5, 2)
        check (commission_pct is null or (commission_pct >= 0 and commission_pct <= 100)),
    add column if not exists commission_pause_exempt boolean not null default false;

-- Seeded to the rate that was hardcoded, so applying this migration changes no
-- partner's earnings until an operator deliberately edits it.
insert into public.app_settings (key, value) values
    ('subadmin_default_commission_pct', '70'),
    ('subadmin_commission_paused', 'false')
on conflict (key) do nothing;
