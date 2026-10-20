import fs from 'node:fs';
import assert from 'node:assert/strict';
const js=fs.readFileSync(new URL('../public/intern/partner-contracts.js',import.meta.url),'utf8');
const page=fs.readFileSync(new URL('../src/pages/intern/partner/index.astro',import.meta.url),'utf8');
// The live server enforces X-Frame-Options DENY; fetched escaped HTML must be
// displayed in a script-disabled srcdoc, not by embedding the protected URL.
assert.match(js,/\.srcdoc=await response\.text\(\)/);
assert.doesNotMatch(js,/\.src\s*=.*action=preview/);
assert.match(page,/sandbox="allow-same-origin"/);
assert.doesNotMatch(page,/allow-scripts/);
assert.match(js,/list&archived=/);
assert.match(js,/X-Partner-CSRF/);
console.log('partner_contract_ui_test: OK (DENY-compatible sandbox preview, durable archive, CSRF)');
