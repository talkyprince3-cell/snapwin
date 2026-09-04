/**
 * Regenerates supabase/setup-all.sql from schema.sql + every migration.
 *
 * setup-all.sql is the one-paste bootstrap for a fresh Supabase project. It was
 * previously hand-maintained and silently drifted: migrations 0013-0018 were
 * never added, so 0020 tried to ALTER a `bookings` table that 0015 had never
 * created and the whole script aborted there. Generating it removes that class
 * of bug — run this after adding a migration.
 *
 *   node scripts/gen-setup-all.mjs
 */
import { readFileSync, writeFileSync, readdirSync } from 'node:fs'
import { join, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

// fileURLToPath, not URL.pathname - the repo path contains a space and
// pathname would hand us a percent-encoded 'SNAP%20WIN'.
const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const SUPA = join(ROOT, 'supabase')
const MIGRATIONS = join(SUPA, 'migrations')

const header = `-- ============================================================================
-- SnapWin - full database setup (schema + ALL migrations)
-- ----------------------------------------------------------------------------
-- Paste this whole file into the Supabase SQL Editor and run once.
--
-- Idempotent: every statement uses IF NOT EXISTS / ON CONFLICT / guarded DO
-- blocks, so it is safe to run against a fresh project OR one that already has
-- schema.sql (or some migrations) applied.
--
-- GENERATED FILE - do not hand-edit. Regenerate with scripts/gen-setup-all.mjs
-- after adding a migration, so this file can never drift out of sync again.
-- ============================================================================
`

const section = (title, path) =>
  `\n-- ===== ${title} =====\n${readFileSync(path, 'utf8').trimEnd()}\n`

const migrations = readdirSync(MIGRATIONS).filter((f) => f.endsWith('.sql')).sort()

const out = [
  header,
  section('schema.sql', join(SUPA, 'schema.sql')),
  ...migrations.map((m) => section(`migrations/${m}`, join(MIGRATIONS, m))),
].join('\n') + '\n'

writeFileSync(join(SUPA, 'setup-all.sql'), out)
console.log(`setup-all.sql regenerated: schema + ${migrations.length} migrations, ${out.length} bytes`)
