/**
 * Resolves what a partner actually earns on a deposit.
 *
 * Three inputs, in priority order:
 *   1. the partner's own commission_pct, when set
 *   2. the global default in app_settings
 *   3. COMMISSION_RATE, the compiled-in fallback, so a missing or corrupt
 *      setting degrades to the historic behaviour rather than paying zero
 *
 * A global pause stops commission being recorded at all, except for partners
 * flagged commission_pause_exempt.
 *
 * Percentages are stored 0-100 because that is what an operator types; every
 * caller wants a fraction, so the conversion happens here, once.
 */

import { COMMISSION_RATE, type SubAdmin } from '@/lib/domain-types'
import { getSettings, setSetting } from '@/lib/settings-store'

export const DEFAULT_PCT_KEY = 'subadmin_default_commission_pct'
export const PAUSED_KEY = 'subadmin_commission_paused'

export interface CommissionConfig {
  /** Global default as a percentage, 0-100. */
  defaultPct: number
  /** When true, no new commission is recorded except for exempt partners. */
  paused: boolean
}

function clampPct(raw: unknown, fallback: number): number {
  const n = Number(raw)
  if (!Number.isFinite(n) || n < 0 || n > 100) return fallback
  return +n.toFixed(2)
}

export async function readCommissionConfig(): Promise<CommissionConfig> {
  const s = await getSettings([DEFAULT_PCT_KEY, PAUSED_KEY])
  return {
    defaultPct: clampPct(s[DEFAULT_PCT_KEY], COMMISSION_RATE * 100),
    paused: s[PAUSED_KEY] === 'true',
  }
}

export async function writeCommissionConfig(patch: Partial<CommissionConfig>): Promise<void> {
  if (patch.defaultPct !== undefined) {
    await setSetting(DEFAULT_PCT_KEY, String(clampPct(patch.defaultPct, COMMISSION_RATE * 100)))
  }
  if (patch.paused !== undefined) {
    await setSetting(PAUSED_KEY, patch.paused ? 'true' : 'false')
  }
}

export interface RateDecision {
  /** Fraction to multiply the deposit by, e.g. 0.7. Zero when suppressed. */
  rate: number
  /** Percentage actually applied, for display and for the commissions row. */
  pct: number
  /** Set when no commission should be recorded, and why. */
  suppressed?: 'paused'
  source: 'partner' | 'default' | 'fallback'
}

/**
 * Decide the rate for one partner. Pure apart from the config it is handed, so
 * a caller crediting several deposits reads the config once.
 */
export function resolveRate(
  sa: Pick<SubAdmin, 'commissionPct' | 'commissionPauseExempt'>,
  config: CommissionConfig,
): RateDecision {
  if (config.paused && !sa.commissionPauseExempt) {
    return { rate: 0, pct: 0, suppressed: 'paused', source: 'default' }
  }
  if (sa.commissionPct !== undefined && sa.commissionPct !== null) {
    const pct = clampPct(sa.commissionPct, config.defaultPct)
    return { rate: pct / 100, pct, source: 'partner' }
  }
  const pct = clampPct(config.defaultPct, COMMISSION_RATE * 100)
  return {
    rate: pct / 100,
    pct,
    source: config.defaultPct === undefined ? 'fallback' : 'default',
  }
}
