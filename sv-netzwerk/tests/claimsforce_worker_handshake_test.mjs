import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const code = fs.readFileSync(new URL('../browser-extension/claimsforce-bridge/portal-bridge.js', import.meta.url), 'utf8');
async function station(workerStatus) {
  const handlers = [], requests = [], messages = [], attributes = {};
  const window = { addEventListener(type, listener) { if (type === 'message') handlers.push(listener); }, postMessage(data) { messages.push(data); } };
  const chrome = { runtime: {
    getManifest: () => ({ version: '1.4.60' }),
    onMessage: { addListener() {} },
    connect: () => ({ onDisconnect: { addListener() {} }, postMessage() {}, disconnect() {} }),
    async sendMessage(request) { requests.push(request); return request.type === 'GET_RUNTIME_STATUS' ? workerStatus : { ok: true, runId: request.runId }; }
  } };
  vm.runInNewContext(code, { window, chrome, location: { origin: 'https://www.sv-netzwerk.eu' },
    document: { documentElement: { setAttribute(key, value) { attributes[key] = value; } } },
    crypto: { randomUUID: () => 'test-run' }, setInterval: () => 1, clearInterval() {}, setTimeout, clearTimeout,
    URL, AbortController, Map, Set });
  await new Promise(resolve => setImmediate(resolve));
  return { requests, messages, attributes, async send(data) { for (const handler of handlers) handler({ source: window, origin: 'https://www.sv-netzwerk.eu', data }); await new Promise(resolve => setImmediate(resolve)); } };
}
// Ein neuer Manifestwert darf einen noch alten Hintergrundprozess nicht bestätigen.
const stale = await station({ ok: true, active: { status: 'idle' } });
const staleReady = stale.messages.find(m => m.type === 'SVNET_CLAIMS_BRIDGE_READY_V2');
assert.equal(staleReady.version, '1.4.60');
assert.equal(staleReady.workerVersion, '');
assert.equal(staleReady.protocol, 0);
assert.equal(stale.attributes['data-svnet-claims-worker-version'], 'nicht geladen');
const current = await station({ ok: true, workerVersion: '1.4.60', protocol: 2, active: { status: 'idle' } });
await current.send({ type: 'SVNET_CLAIMS_IMPORT_START', profile: 'christian', jobId: 9 });
assert.equal(current.requests.filter(r => r.type.startsWith('START_IMPORT')).length, 0, 'Altes Startsignal muss ignoriert werden');
await current.send({ type: 'SVNET_CLAIMS_IMPORT_START_V2', profile: 'christian', jobId: 10, claimNumber: '26-085905-9', mode: 'single', runId: 'single-10' });
const starts = current.requests.filter(r => r.type.startsWith('START_IMPORT'));
assert.equal(starts.length, 1);
assert.deepEqual(JSON.parse(JSON.stringify(starts[0])), { type: 'START_IMPORT_V2', protocol: 2, workerVersion: '1.4.60', profile: 'christian', jobId: 10, runId: 'single-10', mode: 'single', full: false, claimNumber: '26-085905-9', since: '' });
console.log('Alter Worker wird erkannt; altes Startsignal bleibt wirkungslos; Einzelauftrag bleibt vollständig erhalten.');

const centralCode = fs.readFileSync(new URL('../public/intern/claimsforce-central.js', import.meta.url), 'utf8');
async function centralStation() {
  const handlers = [], messages = [], calls = [], timers = [];
  const elements = Object.fromEntries(['vf-claims-import', 'vf-claims-full', 'vf-claims-number', 'vf-claims-state'].map(id => [id, { disabled: false, value: '26-085905-9', addEventListener() {}, replaceWith() {}, closest: () => null, insertAdjacentElement() {} }]));
  elements['vf-claims-import'].cloneNode = () => elements['vf-claims-import'];
  const window = { addEventListener(type, handler) { if (type === 'message') handlers.push(handler); }, postMessage(data) { messages.push(data); }, dispatchEvent() {}, svnetDriveStatus: async () => ({ claims_agent: true, backoffice: true, selected_expert: 'christian' }) };
  const job = { id: 91, profile: 'christian', sync_mode: 'single', claim_number: '26-085905-9', attempt_count: 1 };
  vm.runInNewContext(centralCode, { window,
    document: { getElementById: id => elements[id] || null, querySelector: () => null, createElement: () => ({ dataset: {}, addEventListener() {} }), documentElement: { getAttribute: () => '', setAttribute() {} } },
    async fetch(url, options) { calls.push({ url, body: options?.body ? JSON.parse(options.body) : null }); const result = url.endsWith('action=mine') ? { jobs: [] } : url.endsWith('action=active') ? { job: null, busy: false } : url.endsWith('action=claim') ? { job } : {}; return { ok: true, json: async () => ({ ok: true, ...result }) }; },
    location: { origin: 'https://www.sv-netzwerk.eu' }, crypto: { randomUUID: () => '11111111-1111-4111-8111-111111111111' },
    sessionStorage: { removeItem() {} }, localStorage: { removeItem() {} }, CustomEvent: class {},
    setTimeout: callback => timers.push(callback), setInterval() {}, Map, Set });
  await new Promise(resolve => setImmediate(resolve));
  return { calls, messages, elements,
    async send(data) { for (const handler of handlers) await handler({ source: window, origin: 'https://www.sv-netzwerk.eu', data }); },
    async poll() { assert(timers.length); await timers.shift()(); await new Promise(resolve => setImmediate(resolve)); }
  };
}
const blocked = await centralStation();
await blocked.send({ type: 'SVNET_CLAIMS_BRIDGE_READY', version: '1.4.60' });
await blocked.poll();
assert.equal(blocked.calls.filter(c => c.url.endsWith('action=claim')).length, 0);
await blocked.send({ type: 'SVNET_CLAIMS_BRIDGE_READY_V2', version: '1.4.60', portalVersion: '1.4.60', workerVersion: '', protocol: 0 });
await blocked.poll();
assert.equal(blocked.calls.filter(c => c.url.endsWith('action=claim')).length, 0, 'Manifest allein darf keinen Auftrag übernehmen');
assert.equal(blocked.elements['vf-claims-full'].disabled, true);
const verified = await centralStation();
await verified.send({ type: 'SVNET_CLAIMS_BRIDGE_READY_V2', version: '1.4.60', portalVersion: '1.4.60', workerVersion: '1.4.60', protocol: 2 });
await verified.poll();
const launch = verified.messages.find(m => m.type === 'SVNET_CLAIMS_IMPORT_START_V2');
assert.equal(launch.claimNumber, '26-085905-9');
assert.equal(launch.mode, 'single');
assert.equal(verified.messages.some(m => m.type === 'SVNET_CLAIMS_IMPORT_START'), false, 'Alte Brücken dürfen das Startsignal nicht empfangen');
const claim = verified.calls.find(c => c.url.endsWith('action=claim'));
assert.equal(claim.body.workerVersion, '1.4.60');
assert.equal(claim.body.protocol, 2);
console.log('Portal startet nur nach echter Worker-Prüfung; alte Brücken bleiben gesperrt.');
