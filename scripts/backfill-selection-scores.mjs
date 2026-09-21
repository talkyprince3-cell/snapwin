/**
 * Fill in the final score on bet legs that settled before migration 0026.
 *
 * Those legs were judged against a score that was then thrown away, so an old
 * ticket shows "-" where the result should be. The score is still recoverable
 * for any leg whose match is still in custom_matches, which is what this reads.
 *
 * Only ever fills blanks: rows that already carry a score, and matches with no
 * final score recorded, are left alone. Safe to re-run — a second pass finds
 * nothing to do.
 *
 *   node scripts/backfill-selection-scores.mjs           # report only
 *   node scripts/backfill-selection-scores.mjs --write   # apply
 */
import { readFileSync } from 'node:fs'
import { join, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const WRITE = process.argv.includes('--write')

// Read straight from .env.local; this is a one-off operator script, not app code.
const env = {}
for (const line of readFileSync(join(ROOT, '.env.local'), 'utf8').split(/\r?\n/)) {
  const m = line.match(/^([A-Z0-9_]+)=(.*)$/)
  if (m) env[m[1]] = m[2].trim()
}
const URL_BASE = env.NEXT_PUBLIC_SUPABASE_URL
const KEY = env.SUPABASE_SERVICE_ROLE_KEY
if (!URL_BASE || !KEY) {
  console.error('NEXT_PUBLIC_SUPABASE_URL and SUPABASE_SERVICE_ROLE_KEY must be set in .env.local')
  process.exit(1)
}
const headers = { apikey: KEY, Authorization: `Bearer ${KEY}`, 'Content-Type': 'application/json' }

async function get(path) {
  const res = await fetch(`${URL_BASE}/rest/v1/${path}`, { headers })
  if (!res.ok) throw new Error(`${path} -> HTTP ${res.status} ${await res.text()}`)
  return res.json()
}

/** Page through a table rather than trusting one request to return everything. */
async function all(path, pageSize = 1000) {
  const out = []
  for (let from = 0; ; from += pageSize) {
    const page = await get(`${path}&limit=${pageSize}&offset=${from}`)
    out.push(...page)
    if (page.length < pageSize) return out
  }
}

let legs
try {
  legs = await all('bet_selections?select=id,match_id,home_team,away_team,status,home_score&status=neq.pending')
} catch (e) {
  // The columns this fills come from 0026. Say so rather than dumping a stack.
  if (/home_score/.test(String(e)) && /does not exist|schema cache/i.test(String(e))) {
    console.error('bet_selections.home_score does not exist yet.')
    console.error('Run supabase/migrations/0026_bet_selection_scores.sql first, then re-run this.')
    process.exit(1)
  }
  throw e
}
const matches = await all('custom_matches?select=id,home_score,away_score')
const byId = new Map(matches.map((m) => [m.id, m]))

const todo = legs.filter((l) => {
  if (l.home_score !== null && l.home_score !== undefined) return false
  const m = byId.get(l.match_id)
  return m && m.home_score !== null && m.away_score !== null
})

console.log(`settled legs        : ${legs.length}`)
console.log(`already have a score: ${legs.filter((l) => l.home_score !== null && l.home_score !== undefined).length}`)
console.log(`fillable from matches: ${todo.length}`)

if (!WRITE) {
  for (const l of todo.slice(0, 10)) {
    const m = byId.get(l.match_id)
    console.log(`  ${l.home_team} v ${l.away_team}  ->  ${m.home_score}:${m.away_score}`)
  }
  console.log('\ndry run — pass --write to apply')
  process.exit(0)
}

let done = 0
let failed = 0
for (const l of todo) {
  const m = byId.get(l.match_id)
  const res = await fetch(`${URL_BASE}/rest/v1/bet_selections?id=eq.${l.id}`, {
    method: 'PATCH',
    headers,
    body: JSON.stringify({ home_score: m.home_score, away_score: m.away_score }),
  })
  if (res.ok) done++
  else {
    failed++
    console.error(`  failed ${l.id}: HTTP ${res.status} ${await res.text()}`)
  }
}
console.log(`\nfilled ${done}${failed ? `, ${failed} failed` : ''}`)
