/**
 * Sub-admin (agent) commission payout requests.
 *
 * The money model, which is the part worth being careful about:
 *
 *   request  — records intent only. Nothing moves. The agent's commission
 *              balance is untouched, so a request sitting in the queue cannot
 *              strand funds.
 *   approve  — re-reads the balance and deducts it, then stamps
 *              balance_deducted_at. The check happens here rather than at
 *              request time because the balance can change in between.
 *   reject   — refunds only when balance_deducted_at is set, so it can never
 *              hand back money that was never taken.
 *
 * Approve and reject both refuse anything that is not still pending, which is
 * what stops a double-click paying an agent twice.
 */

import type { SubAdmin } from '@/lib/domain-types'
import { supabaseServer } from '@/lib/supabase'
import { findSubAdminById, updateSubAdmin } from '@/lib/sub-admins-store'
import { isCurrencyCode, type CurrencyCode } from '@/lib/countries'

export type SaWithdrawalStatus = 'pending' | 'completed' | 'rejected'

export interface SaWithdrawal {
  id: string
  subAdminId: string
  amount: number
  currency: CurrencyCode
  status: SaWithdrawalStatus
  payoutMethod?: string
  payoutDestination?: string
  paymentMethod?: string
  paymentReference?: string
  paymentNote?: string
  createdAt: string
  processedAt?: string
  balanceDeductedAt?: string
  /** Joined for the admin queue so it can name the agent without a second read. */
  subAdminName?: string
  subAdminEmail?: string
}

interface Row {
  id: string
  sub_admin_id: string
  amount: number | string
  currency: string
  status: SaWithdrawalStatus
  payout_method: string | null
  payout_destination: string | null
  payment_method: string | null
  payment_reference: string | null
  payment_note: string | null
  created_at: string
  processed_at: string | null
  balance_deducted_at: string | null
  sub_admins?: { name: string; email: string } | null
}

const TABLE = 'sub_admin_withdrawals'

function toModel(r: Row): SaWithdrawal {
  return {
    id: r.id,
    subAdminId: r.sub_admin_id,
    amount: Number(r.amount) || 0,
    currency: (isCurrencyCode(r.currency) ? r.currency : 'GHS') as CurrencyCode,
    status: r.status,
    payoutMethod: r.payout_method ?? undefined,
    payoutDestination: r.payout_destination ?? undefined,
    paymentMethod: r.payment_method ?? undefined,
    paymentReference: r.payment_reference ?? undefined,
    paymentNote: r.payment_note ?? undefined,
    createdAt: r.created_at,
    processedAt: r.processed_at ?? undefined,
    balanceDeductedAt: r.balance_deducted_at ?? undefined,
    subAdminName: r.sub_admins?.name,
    subAdminEmail: r.sub_admins?.email,
  }
}

/** Balance the agent actually has in one currency. */
export function availableBalance(sa: SubAdmin, currency: CurrencyCode): number {
  return +(sa.commissionBalances[currency] ?? 0).toFixed(2)
}

/**
 * Total already asked for in this currency and not yet decided. Subtracting it
 * from the balance is what stops an agent queueing five requests for their full
 * balance and being paid five times.
 */
export async function pendingTotalFor(
  subAdminId: string,
  currency: CurrencyCode,
): Promise<number> {
  const { data, error } = await supabaseServer()
    .from(TABLE)
    .select('amount')
    .eq('sub_admin_id', subAdminId)
    .eq('currency', currency)
    .eq('status', 'pending')
  if (error) throw new Error(error.message)
  return +(data ?? []).reduce((sum, r) => sum + (Number(r.amount) || 0), 0).toFixed(2)
}

export async function listForSubAdmin(subAdminId: string): Promise<SaWithdrawal[]> {
  const { data, error } = await supabaseServer()
    .from(TABLE)
    .select('*')
    .eq('sub_admin_id', subAdminId)
    .order('created_at', { ascending: false })
    .limit(100)
  if (error) throw new Error(error.message)
  return (data as Row[]).map(toModel)
}

export async function listAll(status?: SaWithdrawalStatus): Promise<SaWithdrawal[]> {
  let q = supabaseServer()
    .from(TABLE)
    .select('*, sub_admins(name, email)')
    .order('created_at', { ascending: false })
    .limit(200)
  if (status) q = q.eq('status', status)
  const { data, error } = await q
  if (error) throw new Error(error.message)
  return (data as Row[]).map(toModel)
}

export async function findById(id: string): Promise<SaWithdrawal | null> {
  const { data, error } = await supabaseServer()
    .from(TABLE)
    .select('*, sub_admins(name, email)')
    .eq('id', id)
    .maybeSingle()
  if (error) throw new Error(error.message)
  return data ? toModel(data as Row) : null
}

export async function createRequest(input: {
  subAdminId: string
  amount: number
  currency: CurrencyCode
  payoutMethod?: string
  payoutDestination?: string
}): Promise<SaWithdrawal> {
  const { data, error } = await supabaseServer()
    .from(TABLE)
    .insert({
      sub_admin_id: input.subAdminId,
      amount: +input.amount.toFixed(2),
      currency: input.currency,
      status: 'pending',
      payout_method: input.payoutMethod ?? null,
      payout_destination: input.payoutDestination ?? null,
    })
    .select('*')
    .single()
  if (error) throw new Error(error.message)
  return toModel(data as Row)
}

type Settle =
  | { ok: true; withdrawal: SaWithdrawal }
  | { ok: false; reason: 'not-found' | 'not-pending' | 'insufficient'; message: string }

/**
 * Approve: deduct the agent's balance, then mark the row completed.
 *
 * The status guard is written as part of the UPDATE rather than checked first,
 * so two concurrent approvals cannot both pass a read and each pay out — the
 * second matches no row and stops here.
 */
export async function approve(
  id: string,
  by: { paymentMethod?: string; paymentReference?: string; paymentNote?: string },
): Promise<Settle> {
  const db = supabaseServer()
  const existing = await findById(id)
  if (!existing) return { ok: false, reason: 'not-found', message: 'Withdrawal not found.' }
  if (existing.status !== 'pending') {
    return { ok: false, reason: 'not-pending', message: `Already ${existing.status}.` }
  }

  const sa = await findSubAdminById(existing.subAdminId)
  if (!sa) return { ok: false, reason: 'not-found', message: 'Sub-admin not found.' }

  const balance = availableBalance(sa, existing.currency)
  if (!existing.balanceDeductedAt && balance + 0.001 < existing.amount) {
    return {
      ok: false,
      reason: 'insufficient',
      message: `Agent's ${existing.currency} balance is ${balance.toFixed(2)} — not enough to approve ${existing.amount.toFixed(2)}.`,
    }
  }

  const now = new Date().toISOString()

  // Claim the row first. If this matches nothing, someone else already settled
  // it and no money should move.
  const { data: claimed, error: claimErr } = await db
    .from(TABLE)
    .update({
      status: 'completed',
      processed_at: now,
      balance_deducted_at: existing.balanceDeductedAt ?? now,
      payment_method: by.paymentMethod ?? null,
      payment_reference: by.paymentReference ?? null,
      payment_note: by.paymentNote ?? null,
    })
    .eq('id', id)
    .eq('status', 'pending')
    .select('*')
    .maybeSingle()
  if (claimErr) throw new Error(claimErr.message)
  if (!claimed) {
    return { ok: false, reason: 'not-pending', message: 'This request was already settled.' }
  }

  // Only now move the money, and only if it has not already been taken.
  if (!existing.balanceDeductedAt) {
    await applyBalanceDelta(sa, existing.currency, -existing.amount)
  }
  return { ok: true, withdrawal: toModel(claimed as Row) }
}

/** Reject: refund only if the balance was actually deducted. */
export async function reject(id: string, note?: string): Promise<Settle> {
  const db = supabaseServer()
  const existing = await findById(id)
  if (!existing) return { ok: false, reason: 'not-found', message: 'Withdrawal not found.' }
  if (existing.status !== 'pending') {
    return { ok: false, reason: 'not-pending', message: `Already ${existing.status}.` }
  }

  const { data: claimed, error } = await db
    .from(TABLE)
    .update({
      status: 'rejected',
      processed_at: new Date().toISOString(),
      payment_note: note ?? null,
    })
    .eq('id', id)
    .eq('status', 'pending')
    .select('*')
    .maybeSingle()
  if (error) throw new Error(error.message)
  if (!claimed) {
    return { ok: false, reason: 'not-pending', message: 'This request was already settled.' }
  }

  if (existing.balanceDeductedAt) {
    const sa = await findSubAdminById(existing.subAdminId)
    if (sa) await applyBalanceDelta(sa, existing.currency, existing.amount)
  }
  return { ok: true, withdrawal: toModel(claimed as Row) }
}

/**
 * Move one currency's commission balance by `delta`, clamped at zero.
 *
 * Lifetime earnings are deliberately left alone: a payout is not un-earning the
 * commission, and the admin dashboard reports lifetime separately from what is
 * still owed.
 */
async function applyBalanceDelta(
  sa: SubAdmin,
  currency: CurrencyCode,
  delta: number,
): Promise<void> {
  const next = { ...sa.commissionBalances }
  next[currency] = +Math.max(0, (next[currency] ?? 0) + delta).toFixed(2)
  const patch: Partial<SubAdmin> = { commissionBalances: next }
  if (currency === 'GHS') {
    patch.commissionBalance = +Math.max(0, sa.commissionBalance + delta).toFixed(2)
  }
  await updateSubAdmin(sa.id, patch)
}
