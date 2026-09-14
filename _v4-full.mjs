import { readFileSync } from 'node:fs'
import { randomUUID } from 'node:crypto'
const env = {}
for (const line of readFileSync('.env.local', 'utf8').split(/\r?\n/)) {
  const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*?)\s*$/)
  if (m) env[m[1]] = m[2].replace(/^["']|["']$/g, '').trim()
}
const BASE = 'https://f4bexperience.flutterwave.com'
const tr = await fetch('https://idp.flutterwave.com/realms/flutterwave/protocol/openid-connect/token',
  { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ client_id: env.FLUTTERWAVE_V4_CLIENT_ID, client_secret: env.FLUTTERWAVE_V4_CLIENT_SECRET, grant_type: 'client_credentials' }) })
const TOK = (await tr.json()).access_token
async function post(path, body) {
  const r = await fetch(`${BASE}${path}`, { method: 'POST', headers: { Authorization: `Bearer ${TOK}`, 'Content-Type': 'application/json', 'X-Trace-Id': `pluse-${randomUUID()}` }, body: JSON.stringify(body) })
  const j = await r.json().catch(() => ({})); console.log(`POST ${path} -> HTTP ${r.status} status=${j.status}`); return j
}
const c = await post('/customers', { name: { first: 'Diag', last: 'Test' }, phone: { country_code: '233', number: '509182654' }, email: 'diagnostic@pluse.app' })
const pm = await post('/payment-methods', { type: 'mobile_money', mobile_money: { country_code: '233', network: 'MTN', phone_number: '509182654' } })
const chg = await post('/charges', { currency: 'GHS', amount: 200, reference: `V4-DIAG-${Date.now()}`, customer_id: c.data?.id, payment_method_id: pm.data?.id })
console.log('\n=== CHARGE RESULT ===')
console.log('charge id:', chg.data?.id, '| status:', chg.data?.status)
console.log('next_action:', JSON.stringify(chg.data?.next_action))
