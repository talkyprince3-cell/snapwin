-- Storage bucket for manual-deposit payment screenshots.
--
-- Manual deposits (customer pays our MoMo number and uploads proof) store the
-- screenshot here; the admin views it on the Payments page before crediting.
-- /api/payments/manual/start uploads to this bucket by name, so without it the
-- manual-deposit flow fails at upload time.
--
-- This file previously documented the bucket as a manual dashboard step and
-- shipped its SQL commented out, which meant a fresh environment never got the
-- bucket. It now creates it, idempotently, the same way 0014 does for
-- 'team-flags'.
--
-- Uploads use the service-role key (bypasses RLS); the admin reads via the
-- public URL, so no extra storage RLS policies are required.

insert into storage.buckets (id, name, public, file_size_limit)
values ('deposit-screenshots', 'deposit-screenshots', true, 5000000)
on conflict (id) do update set public = true;
