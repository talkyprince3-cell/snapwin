import { randomUUID } from 'crypto'
import type { AppUser, Commission } from '@/lib/domain-types'
import { supabaseServer } from '@/lib/supabase'
import {
  currencyFromCountry,
  DEFAULT_COUNTRY,
  DEFAULT_CURRENCY,
  isCountryCode,
  isCurrencyCode,
  type CountryCode,
  type CurrencyCode,
} from '@/lib/countries'

interface UserRow {
  id: string
  name: string
  email: string
  password_hash: string
  phone: string | null
  country: string | null
  currency: string | null
  ghana_card: string | null
  kyc_id: string | null
  referred_by_code: string | null
  referred_by_sub_admin_id: string | null
  linked_sub_admin_id: string | null
  first_deposit_amount: number
  first_deposit_at: string | null
  total_deposited: number
  total_withdrawn: number
  balance: number
  verification_step: number
  withdrawal_approved: boolean
  created_at: string
}

interface CommissionRow {
  id: string
  sub_admin_id: string
  user_id: string
  deposit_amount: number
  commission_amount: number
  rate: number
  currency: string | null
  created_at: string
}

function rowToUser(row: UserRow): AppUser {
  const step = Number(row.verification_step ?? 0)
  const clamped = (step < 0 ? 0 : step > 4 ? 4 : step) as 0 | 1 | 2 | 3 | 4
  const country: CountryCode = isCountryCode(row.country) ? row.country : DEFAULT_COUNTRY
  const currency: CurrencyCode = isCurrencyCode(row.currency) ? row.currency : currencyFromCountry(country)
  return {
    id: row.id,
    name: row.name,
    email: row.email,
    passwordHash: row.password_hash,
    phone: row.phone ?? undefined,
    country,
    currency,
    ghanaCard: row.ghana_card ?? undefined,
    kycId: row.kyc_id ?? row.ghana_card ?? undefined,
    referredByCode: row.referred_by_code ?? undefined,
    referredBySubAdminId: row.referred_by_sub_admin_id ?? undefined,
    linkedSubAdminId: row.linked_sub_admin_id ?? undefined,
    firstDepositAmount: Number(row.first_deposit_amount),
    firstDepositAt: row.first_deposit_at ?? undefined,
    totalDeposited: Number(row.total_deposited),
    totalWithdrawn: Number(row.total_withdrawn),
    balance: Number(row.balance),
    verificationStep: clamped,
    withdrawalApproved: row.withdrawal_approved ?? false,
    createdAt: row.created_at,
  }
}

export async function readUsers(): Promise<AppUser[]> {
  // Supabase/PostgREST caps a single select at 1000 rows by default, so once the
  // platform passed 1000 players the admin list silently stopped growing. Page
  // through in 1000-row chunks to return every user.
  const sb = supabaseServer()
  const PAGE = 1000
  const out: AppUser[] = []
  for (let from = 0; ; from += PAGE) {
    const { data, error } = await sb
      .from('users')
      .select('*')
      .order('created_at', { ascending: false })
      .range(from, from + PAGE - 1)
    if (error) throw new Error(`users.readAll: ${error.message}`)
    const rows = data ?? []
    out.push(...rows.map(rowToUser))
    if (rows.length < PAGE) break
  }
  return out
}

export async function findUserByEmail(email: string): Promise<AppUser | null> {
  const { data, error } = await supabaseServer()
    .from('users')
    .select('*')
    .eq('email', email.trim().toLowerCase())
    .maybeSingle()
  if (error) throw new Error(`users.findByEmail: ${error.message}`)
  return data ? rowToUser(data) : null
}

export async function findUserByPhone(phone: string): Promise<AppUser | null> {
  const cleaned = phone.trim()
  if (!cleaned) return null
  const { data, error } = await supabaseServer()
    .from('users')
    .select('*')
    .eq('phone', cleaned)
    .maybeSingle()
  if (error) throw new Error(`users.findByPhone: ${error.message}`)
  return data ? rowToUser(data) : null
}

export async function findUserById(id: string): Promise<AppUser | null> {
  const { data, error } = await supabaseServer()
    .from('users')
    .select('*')
    .eq('id', id)
    .maybeSingle()
  if (error) throw new Error(`users.findById: ${error.message}`)
  return data ? rowToUser(data) : null
}

export async function addUser(
  input: Omit<
    AppUser,
    'id' | 'createdAt' | 'firstDepositAmount' | 'totalDeposited' | 'totalWithdrawn' | 'balance' | 'verificationStep' | 'currency'
  > & { currency?: CurrencyCode },
): Promise<AppUser> {
  const country: CountryCode = isCountryCode(input.country) ? input.country : DEFAULT_COUNTRY
  const currency: CurrencyCode = input.currency && isCurrencyCode(input.currency)
    ? input.currency
    : currencyFromCountry(country)
  const insert = {
    name: input.name,
    email: input.email.trim().toLowerCase(),
    password_hash: input.passwordHash,
    phone: input.phone?.trim() || null,
    country,
    currency,
    // Mirror KYC into ghana_card too when the user is from Ghana so existing
    // admin views that still read ghana_card keep working during the rollout.
    ghana_card: country === 'GH' ? (input.kycId ?? input.ghanaCard ?? null) : null,
    kyc_id: input.kycId?.trim() || input.ghanaCard?.trim() || null,
    referred_by_code: input.referredByCode ?? null,
    referred_by_sub_admin_id: input.referredBySubAdminId ?? null,
  }
  const { data, error } = await supabaseServer()
    .from('users')
    .insert(insert)
    .select('*')
    .single()
  if (error) throw new Error(`users.add: ${error.message}`)
  return rowToUser(data)
}

export async function recordDeposit(
  userId: string,
  amount: number,
): Promise<{ user: AppUser; isFirst: boolean } | null> {
  const current = await findUserById(userId)
  if (!current) return null

  const isFirst = !current.firstDepositAt
  const currentBalance = current.balance ?? 0
  const newBalance = +(currentBalance + amount).toFixed(2)
  const newTotal = +(current.totalDeposited + amount).toFixed(2)

  const patch: Record<string, unknown> = {
    total_deposited: newTotal,
    balance: newBalance,
  }
  if (isFirst) {
    patch.first_deposit_amount = amount
    patch.first_deposit_at = new Date().toISOString()
  }

  const { data, error } = await supabaseServer()
    .from('users')
    .update(patch)
    .eq('id', userId)
    .select('*')
    .single()
  if (error) throw new Error(`users.recordDeposit: ${error.message}`)
  return { user: rowToUser(data), isFirst }
}

/**
 * Reverse a credited deposit: subtract the amount back off the user's balance
 * and lifetime total. Used when an admin deletes a deposit that was a mistake
 * or never actually paid. Clamped at zero so a balance the user already spent
 * down can't go negative.
 */
export async function reverseDeposit(
  userId: string,
  amount: number,
): Promise<AppUser | null> {
  const current = await findUserById(userId)
  if (!current) return null
  const newBalance = +Math.max(0, (current.balance ?? 0) - amount).toFixed(2)
  const newTotal = +Math.max(0, (current.totalDeposited ?? 0) - amount).toFixed(2)
  const { data, error } = await supabaseServer()
    .from('users')
    .update({ balance: newBalance, total_deposited: newTotal })
    .eq('id', userId)
    .select('*')
    .single()
  if (error) throw new Error(`users.reverseDeposit: ${error.message}`)
  return rowToUser(data)
}

export async function recordWithdrawal(
  userId: string,
  amount: number,
  opts: {
    /**
     * Players must have deposited at least once before they can withdraw.
     * Partners withdrawing their own betting balance are exempt, so this is an
     * explicit opt-out rather than a rule quietly relaxed for everyone.
     */
    requireDeposit?: boolean
  } = {},
): Promise<{ user: AppUser } | { error: 'not-found' | 'insufficient-funds' | 'no-deposit' }> {
  const { requireDeposit = true } = opts
  const current = await findUserById(userId)
  if (!current) return { error: 'not-found' }
  if (requireDeposit && !current.firstDepositAt) return { error: 'no-deposit' }
  const currentBalance = current.balance ?? 0
  const currentWithdrawn = current.totalWithdrawn ?? 0
  if (amount > currentBalance) return { error: 'insufficient-funds' }

  const { data, error } = await supabaseServer()
    .from('users')
    .update({
      total_withdrawn: +(currentWithdrawn + amount).toFixed(2),
      balance: +(currentBalance - amount).toFixed(2),
    })
    .eq('id', userId)
    .select('*')
    .single()
  if (error) throw new Error(`users.recordWithdrawal: ${error.message}`)
  return { user: rowToUser(data) }
}

/**
 * Bump the user's withdrawal-verification step by 1 (capped at 4).
 * Called after each qualifying deposit clears (manual admin credit or
 * Paystack auto-credit). Withdrawals unlock at step 4.
 */
export async function advanceVerificationStep(userId: string): Promise<AppUser | null> {
  const current = await findUserById(userId)
  if (!current) return null
  const next = Math.min(4, (current.verificationStep ?? 0) + 1)
  if (next === current.verificationStep) return current
  const { data, error } = await supabaseServer()
    .from('users')
    .update({ verification_step: next })
    .eq('id', userId)
    .select('*')
    .single()
  if (error) throw new Error(`users.advanceVerification: ${error.message}`)
  return rowToUser(data)
}

/**
 * Update the user's password hash. Used by /api/users/change-password
 * after the caller has verified the current password.
 */
export async function setUserPassword(
  userId: string,
  passwordHash: string,
): Promise<AppUser | null> {
  const { data, error } = await supabaseServer()
    .from('users')
    .update({ password_hash: passwordHash })
    .eq('id', userId)
    .select('*')
    .maybeSingle()
  if (error) throw new Error(`users.setPassword: ${error.message}`)
  return data ? rowToUser(data) : null
}

/**
 * Save / update the user's mobile-money phone number so it can be
 * pre-filled on subsequent withdrawals.
 */
export async function setUserPhone(
  userId: string,
  phone: string,
): Promise<AppUser | null> {
  const cleaned = phone.trim() || null
  const { data, error } = await supabaseServer()
    .from('users')
    .update({ phone: cleaned })
    .eq('id', userId)
    .select('*')
    .maybeSingle()
  if (error) throw new Error(`users.setPhone: ${error.message}`)
  return data ? rowToUser(data) : null
}

/**
 * Admin gate for withdrawals — set/unset the per-user approval flag.
 */
export async function setWithdrawalApproval(
  userId: string,
  approved: boolean,
): Promise<AppUser | null> {
  const { data, error } = await supabaseServer()
    .from('users')
    .update({ withdrawal_approved: approved })
    .eq('id', userId)
    .select('*')
    .maybeSingle()
  if (error) throw new Error(`users.setWithdrawalApproval: ${error.message}`)
  return data ? rowToUser(data) : null
}

/**
 * List users with their current withdrawal-eligibility state — used by
 * the admin Pending Withdrawals page.
 */
export async function listUsersForAdmin(): Promise<AppUser[]> {
  // Same data as readUsers — paginated so it isn't capped at 1000 rows.
  return readUsers()
}

/**
 * Deduct a bet stake from the user's balance.
 */
export async function debitBalance(
  userId: string,
  amount: number,
): Promise<{ user: AppUser } | { error: 'not-found' | 'insufficient-funds' }> {
  const current = await findUserById(userId)
  if (!current) return { error: 'not-found' }
  const currentBalance = current.balance ?? 0
  if (amount > currentBalance) return { error: 'insufficient-funds' }
  const { data, error } = await supabaseServer()
    .from('users')
    .update({ balance: +(currentBalance - amount).toFixed(2) })
    .eq('id', userId)
    .select('*')
    .single()
  if (error) throw new Error(`users.debit: ${error.message}`)
  return { user: rowToUser(data) }
}

/**
 * Credit a payout (won bet) back to the user's balance.
 */
export async function creditBalance(
  userId: string,
  amount: number,
): Promise<AppUser | null> {
  const current = await findUserById(userId)
  if (!current) return null
  const currentBalance = current.balance ?? 0
  const { data, error } = await supabaseServer()
    .from('users')
    .update({ balance: +(currentBalance + amount).toFixed(2) })
    .eq('id', userId)
    .select('*')
    .single()
  if (error) throw new Error(`users.credit: ${error.message}`)
  return rowToUser(data)
}

export async function listUsersReferredBy(subAdminId: string): Promise<AppUser[]> {
  const { data, error } = await supabaseServer()
    .from('users')
    .select('*')
    .eq('referred_by_sub_admin_id', subAdminId)
    .order('created_at', { ascending: false })
  if (error) throw new Error(`users.listReferredBy: ${error.message}`)
  return (data ?? []).map(rowToUser)
}

// ─── Commissions ────────────────────────────────────────────────────────────

function rowToCommission(row: CommissionRow): Commission {
  return {
    id: row.id,
    subAdminId: row.sub_admin_id,
    userId: row.user_id,
    depositAmount: Number(row.deposit_amount),
    commission: Number(row.commission_amount),
    rate: Number(row.rate),
    currency: isCurrencyCode(row.currency) ? row.currency : DEFAULT_CURRENCY,
    createdAt: row.created_at,
  }
}

export async function readCommissions(): Promise<Commission[]> {
  const { data, error } = await supabaseServer()
    .from('commissions')
    .select('*')
    .order('created_at', { ascending: false })
  if (error) throw new Error(`commissions.readAll: ${error.message}`)
  return (data ?? []).map(rowToCommission)
}

export async function addCommission(
  c: Omit<Commission, 'id' | 'createdAt'>,
): Promise<Commission> {
  const { data, error } = await supabaseServer()
    .from('commissions')
    .insert({
      id: randomUUID(),
      sub_admin_id: c.subAdminId,
      user_id: c.userId,
      deposit_amount: c.depositAmount,
      commission_amount: c.commission,
      rate: c.rate,
      currency: c.currency,
    })
    .select('*')
    .single()
  if (error) throw new Error(`commissions.add: ${error.message}`)
  return rowToCommission(data)
}

/**
 * Delete every commission row across all sub-admins. Used by the admin
 * "Clear all deposits" action so the partner dashboards' earnings / deposit
 * counts reset alongside the deposit records. Sub-admins' lifetime commission
 * *balances* live on the sub_admins table and are NOT touched here — this only
 * clears the per-deposit commission ledger the dashboards read from. Returns
 * the number of rows removed.
 */
export async function deleteAllCommissions(): Promise<number> {
  const { error, count } = await supabaseServer()
    .from('commissions')
    .delete({ count: 'exact' })
    // A delete needs a filter; "id is not null" matches every row.
    .not('id', 'is', null)
  if (error) throw new Error(`commissions.deleteAll: ${error.message}`)
  return count ?? 0
}

export async function listCommissionsForSubAdmin(
  subAdminId: string,
): Promise<Commission[]> {
  const { data, error } = await supabaseServer()
    .from('commissions')
    .select('*')
    .eq('sub_admin_id', subAdminId)
    .order('created_at', { ascending: false })
  if (error) throw new Error(`commissions.listForSubAdmin: ${error.message}`)
  return (data ?? []).map(rowToCommission)
}


/**
 * The partner's own betting wallet, created on first use.
 *
 * A sub_admins row can sign into the partner dashboard but cannot place a bet —
 * bets, balances and deposits all hang off users. This returns the users row
 * linked to that partner, making one if needed from the same email and password
 * hash they already sign in with, so /login works for them with no second set
 * of credentials and deposits credit this wallet through the existing rails.
 *
 * Three cases, in order:
 *   already linked   -> return it
 *   email is a player already -> adopt that row rather than colliding with the
 *                        unique email, so a partner who was a punter first keeps
 *                        the wallet and history they already had
 *   neither          -> create one
 *
 * Never merges or moves money: linking only sets the pointer.
 */
export async function ensureBettingAccountForSubAdmin(sa: {
  id: string
  name: string
  email: string
  passwordHash: string
}): Promise<AppUser> {
  const db = supabaseServer()

  const { data: linked, error: linkedErr } = await db
    .from('users')
    .select('*')
    .eq('linked_sub_admin_id', sa.id)
    .maybeSingle()
  if (linkedErr) throw new Error(`users.linkedForSubAdmin: ${linkedErr.message}`)
  if (linked) return rowToUser(linked)

  const existing = await findUserByEmail(sa.email)
  if (existing) {
    const { data: adopted, error: adoptErr } = await db
      .from('users')
      .update({ linked_sub_admin_id: sa.id })
      .eq('id', existing.id)
      .select('*')
      .single()
    if (adoptErr) throw new Error(`users.adoptForSubAdmin: ${adoptErr.message}`)
    return rowToUser(adopted)
  }

  const created = await addUser({
    name: sa.name,
    email: sa.email,
    passwordHash: sa.passwordHash,
    country: DEFAULT_COUNTRY,
  })
  const { data: tagged, error: tagErr } = await db
    .from('users')
    .update({ linked_sub_admin_id: sa.id })
    .eq('id', created.id)
    .select('*')
    .single()
  if (tagErr) throw new Error(`users.tagForSubAdmin: ${tagErr.message}`)
  return rowToUser(tagged)
}
