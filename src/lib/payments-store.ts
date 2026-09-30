import { supabaseServer } from '@/lib/supabase'

export type PaymentType = 'deposit' | 'withdrawal'
export type PaymentStatus = 'pending' | 'success' | 'failed' | 'cancelled'

export interface PaymentRecord {
  id: string
  userId: string | null
  reference: string
  amount: number
  currency: string
  provider: string
  status: PaymentStatus
  type: PaymentType
  metadata: Record<string, unknown>
  createdAt: string
  verifiedAt: string | null
}

interface PaymentRow {
  id: string
  user_id: string | null
  reference: string
  amount: string | number
  currency: string
  provider: string
  status: PaymentStatus
  metadata: Record<string, unknown> | null
  created_at: string
  verified_at: string | null
}

function rowToRecord(row: PaymentRow): PaymentRecord {
  const meta = row.metadata ?? {}
  const rawType = typeof meta.type === 'string' ? meta.type : 'deposit'
  const type: PaymentType = rawType === 'withdrawal' ? 'withdrawal' : 'deposit'
  return {
    id: row.id,
    userId: row.user_id,
    reference: row.reference,
    amount: Number(row.amount),
    currency: row.currency,
    provider: row.provider,
    status: row.status,
    type,
    metadata: meta,
    createdAt: row.created_at,
    verifiedAt: row.verified_at,
  }
}

export interface RecordPaymentInput {
  userId: string
  reference: string
  amount: number
  type: PaymentType
  status?: PaymentStatus
  provider?: string
  currency?: string
  metadata?: Record<string, unknown>
  verifiedAt?: string | null
}

/**
 * Insert a payment row. Idempotent on `reference` — if the same reference is
 * submitted twice we silently ignore the duplicate and return the existing row,
 * so callers don't have to wrap this in their own try/catch for double-credit.
 */
export async function recordPayment(input: RecordPaymentInput): Promise<PaymentRecord | null> {
  const supabase = supabaseServer()
  const metadata = { ...(input.metadata ?? {}), type: input.type }
  const row = {
    user_id: input.userId,
    reference: input.reference,
    amount: input.amount,
    currency: input.currency ?? 'GHS',
    provider: input.provider ?? 'moolre',
    status: input.status ?? 'success',
    metadata,
    verified_at: input.verifiedAt ?? new Date().toISOString(),
  }

  const { data, error } = await supabase
    .from('payments')
    .insert(row)
    .select('*')
    .single()

  if (error) {
    // 23505 = unique_violation on `reference` — return the existing row.
    if (error.code === '23505') {
      const { data: existing } = await supabase
        .from('payments')
        .select('*')
        .eq('reference', input.reference)
        .maybeSingle()
      return existing ? rowToRecord(existing as PaymentRow) : null
    }
    throw new Error(`payments.record: ${error.message}`)
  }
  return rowToRecord(data as PaymentRow)
}

export async function listPaymentsForUser(userId: string): Promise<PaymentRecord[]> {
  const { data, error } = await supabaseServer()
    .from('payments')
    .select('*')
    .eq('user_id', userId)
    .order('created_at', { ascending: false })
  if (error) throw new Error(`payments.listForUser: ${error.message}`)
  return ((data ?? []) as PaymentRow[]).map(rowToRecord)
}

/**
 * Admin list — every payment row, newest first. Filter by type client-side
 * (the JSONB->>'type' filter isn't typed in supabase-js without escapes, so we
 * just fetch and filter since this table stays small in the demo deployment).
 */
export async function listAllPayments(opts?: {
  type?: PaymentType
  /** Safety ceiling on how many rows to pull (paginated). Defaults to 50k. */
  max?: number
}): Promise<PaymentRecord[]> {
  // PostgREST caps a single select at 1000 rows; page through so the admin
  // sees every transaction once the platform has more than 1000 payments.
  const sb = supabaseServer()
  const PAGE = 1000
  const max = opts?.max ?? 50000
  const all: PaymentRecord[] = []
  for (let from = 0; from < max; from += PAGE) {
    const { data, error } = await sb
      .from('payments')
      .select('*')
      .order('created_at', { ascending: false })
      .range(from, Math.min(from + PAGE, max) - 1)
    if (error) throw new Error(`payments.listAll: ${error.message}`)
    const rows = (data ?? []) as PaymentRow[]
    all.push(...rows.map(rowToRecord))
    if (rows.length < PAGE) break
  }
  return opts?.type ? all.filter((p) => p.type === opts.type) : all
}

export async function findPaymentById(id: string): Promise<PaymentRecord | null> {
  const { data, error } = await supabaseServer()
    .from('payments')
    .select('*')
    .eq('id', id)
    .maybeSingle()
  if (error) throw new Error(`payments.findById: ${error.message}`)
  return data ? rowToRecord(data as PaymentRow) : null
}

/**
 * Hard-delete a payment row. Used by the admin "delete deposit" action after
 * any wallet reversal has been applied. Returns true if a row was removed.
 */
export async function deletePayment(id: string): Promise<boolean> {
  const { error, count } = await supabaseServer()
    .from('payments')
    .delete({ count: 'exact' })
    .eq('id', id)
  if (error) throw new Error(`payments.delete: ${error.message}`)
  return (count ?? 0) > 0
}

/**
 * Bulk-delete every DEPOSIT payment row (leaves withdrawals untouched). Used by
 * the admin "Clear all deposits" action. This does NOT reverse any wallet
 * credit — balances are left exactly as they are; only the records go. We pull
 * the deposit ids through listAllPayments (which correctly treats a row with no
 * metadata.type as a deposit) and delete them in chunks by id, so the JSONB
 * type filter never has to be expressed in a single fragile query. Returns the
 * number of rows removed.
 */
export async function deleteAllDeposits(): Promise<number> {
  const deposits = await listAllPayments({ type: 'deposit' })
  if (deposits.length === 0) return 0
  const sb = supabaseServer()
  const CHUNK = 500
  let removed = 0
  for (let i = 0; i < deposits.length; i += CHUNK) {
    const ids = deposits.slice(i, i + CHUNK).map((p) => p.id)
    const { error, count } = await sb
      .from('payments')
      .delete({ count: 'exact' })
      .in('id', ids)
    if (error) throw new Error(`payments.deleteAllDeposits: ${error.message}`)
    removed += count ?? 0
  }
  return removed
}

export async function findPaymentByReference(
  reference: string,
): Promise<PaymentRecord | null> {
  const { data, error } = await supabaseServer()
    .from('payments')
    .select('*')
    .eq('reference', reference)
    .maybeSingle()
  if (error) throw new Error(`payments.findByReference: ${error.message}`)
  return data ? rowToRecord(data as PaymentRow) : null
}

/**
 * Shallow-merge extra keys into a payment row's metadata (keyed by reference).
 * Used to stash gateway state mid-flow — e.g. Flutterwave's flw_ref so the OTP
 * step can recover it. Returns the updated record, or null if not found.
 */
export async function mergePaymentMetadata(
  reference: string,
  patch: Record<string, unknown>,
): Promise<PaymentRecord | null> {
  const existing = await findPaymentByReference(reference)
  if (!existing) return null
  const { data, error } = await supabaseServer()
    .from('payments')
    .update({ metadata: { ...existing.metadata, ...patch } })
    .eq('reference', reference)
    .select('*')
    .maybeSingle()
  if (error) throw new Error(`payments.mergeMetadata: ${error.message}`)
  return data ? rowToRecord(data as PaymentRow) : null
}

/**
 * Atomically flip a non-success payment row to success and stamp who
 * resolved it. The `.in('status', …)` filter means only ONE concurrent
 * caller wins — if the row is already success, no rows are updated and
 * we return null. Callers MUST treat a null return as "another path
 * already credited this payment, do nothing more" so two callers can't
 * both run applyDepositCredit on the same row.
 *
 * Returns the updated record on success, null if the row was already
 * resolved OR doesn't exist.
 */
/**
 * Close a payment the rail has terminally refused.
 *
 * Without this a refused deposit stays 'pending' for ever: the row is swept
 * again on every account-page load, counts against the operator's pending
 * queue, and is indistinguishable from a prompt the player has yet to
 * approve. Only a row still pending is touched, so this can never walk back
 * a credit that has already been made.
 */
export async function markPaymentFailed(
  id: string,
  note?: string,
): Promise<PaymentRecord | null> {
  const { data, error } = await supabaseServer()
    .from('payments')
    .update({ status: 'failed' })
    .eq('id', id)
    .eq('status', 'pending')
    .select('*')
    .maybeSingle()
  if (error) throw new Error(`payments.markFailed: ${error.message}`)
  if (!data) return null
  if (note) {
    try {
      await mergePaymentMetadata(id, { failureNote: note, failedAt: new Date().toISOString() })
    } catch {
      /* the status is what matters; the note is a convenience */
    }
  }
  return rowToRecord(data as PaymentRow)
}

export async function markPaymentResolved(
  id: string,
  note?: string,
): Promise<PaymentRecord | null> {
  const existing = await findPaymentById(id)
  if (!existing) return null
  if (existing.status === 'success') return null
  const mergedMeta = {
    ...existing.metadata,
    type: existing.type,
    adminResolved: true,
    resolvedAt: new Date().toISOString(),
    resolutionNote: note || undefined,
  }
  const { data, error } = await supabaseServer()
    .from('payments')
    .update({
      status: 'success',
      verified_at: new Date().toISOString(),
      metadata: mergedMeta,
    })
    .eq('id', id)
    // Postgres-level guard against concurrent double-credits — the row
    // is only updated if it was still in one of these states.
    .in('status', ['pending', 'failed', 'cancelled'])
    .select('*')
    .maybeSingle()
  if (error) throw new Error(`payments.markResolved: ${error.message}`)
  return data ? rowToRecord(data as PaymentRow) : null
}
