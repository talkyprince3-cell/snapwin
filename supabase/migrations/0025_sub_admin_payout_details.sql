-- Saved payout details for a partner: where their commission gets sent.
--
-- Ports save_payout_details from the reference (payout_name / payout_network /
-- payout_number). A partner sets these once and every payout request is
-- pre-filled from them, instead of retyping a mobile number each time and
-- risking a typo on a real transfer.
--
-- Nullable: a partner who has never set them simply gets an empty form.

alter table public.sub_admins
    add column if not exists payout_name    text,
    add column if not exists payout_network text,
    add column if not exists payout_number  text;
