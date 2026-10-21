import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const worker = fs.readFileSync(new URL('../public/sw.js', import.meta.url), 'utf8');
const routes = [], events = {}, deleted = [];
const oldCache = 'api-cache-20260929-1', currentCache = 'api-cache-20261021-kuss-1';
vm.runInNewContext(worker.replace(/^import .*;\r?\n/gm, ''), {
  self: { addEventListener(name, handler) { events[name] = handler; }, __WB_MANIFEST: [], clients: { claim() {} } },
  caches: { keys: async () => [oldCache, currentCache, 'unrelated'], delete: async name => { deleted.push(name); return true; } },
  precacheAndRoute() {}, registerRoute: (match, strategy) => routes.push({ match, strategy }),
  NetworkOnly: class NetworkOnly {}, NetworkFirst: class NetworkFirst {}, CacheFirst: class CacheFirst {},
});
let activation; events.activate({ waitUntil(promise) { activation = promise; } });
await activation;
assert.deepEqual(deleted, [oldCache], 'Remove old API caches and preserve current and unrelated caches');
for (const mode of ['cors', 'navigate']) for (const action of ['status', 'jobs', 'connect']) {
  const request = { mode }, url = new URL(`https://www.sv-netzwerk.eu/intern/api/kuss.php?action=${action}`);
  const route = routes.find(item => item.match({ request, url }));
  assert.equal(route.strategy.constructor.name, 'NetworkOnly', `${action} must never fall back to another user's cached data`);
}
const authRoute = routes.find(item => item.match({ request: { mode: 'cors' }, url: new URL('https://www.sv-netzwerk.eu/intern/api/auth.php?action=session') }));
assert.equal(authRoute.strategy.constructor.name, 'NetworkOnly', 'Authentication fetches must not fall back to a cached session');
const api = fs.readFileSync(new URL('../public/intern/api/kuss.php', import.meta.url), 'utf8');
assert.ok(api.indexOf('session_write_close();') < api.indexOf('kussRequestJobs('), 'Release session lock before remote call');
const script = fs.readFileSync(new URL('../public/intern/kuss-central.js', import.meta.url), 'utf8');
const elements = [];
class Element {
  constructor() { this.children=[]; this.handlers={}; this.disabled=false; this.hidden=false; this.value=''; elements.push(this); }
  setAttribute() {}
  append(...items) { this.children.push(...items); }
  after(...items) { this.afterItems=items; }
  replaceChildren(...items) { this.children=items; }
  addEventListener(name, callback) { this.handlers[name]=callback; }
}
const button = new Element(), state = new Element();
const calls = [];
vm.runInNewContext(script, {
  document: { getElementById: id => id==='vf-kuss-scan'?button:state, createElement: () => new Element() },
  URLSearchParams,
  fetch: async (url, options) => {
    calls.push({url,options});
    const data=url.includes('status')?{ok:true,connected:false,csrf:'fixture-csrf'}:url.includes('connect')?{ok:true,expires_at:'2027-01-01'}:{ok:true,jobs:[{case:'26-123'}],page:{current:1,last:1,total:1}};
    return {ok:true,status:200,json:async()=>data};
  },
});
await elements.find(e=>e.textContent==='KUSS-Verbindung verwalten').handlers.click();
const token=elements.find(e=>e.type==='password'); token.value='fixture-token';
await elements.find(e=>e.textContent==='Verbindung speichern').handlers.click();
const saved=calls.find(c=>c.url.includes('connect'));
assert.equal(saved.options.headers['X-Kuss-CSRF'],'fixture-csrf');
assert.equal(JSON.parse(saved.options.body).token,'fixture-token');
assert.equal(token.value,'','Clear token input after saving');
assert.ok(calls.some(c=>c.url.includes('action=jobs')),'Read jobs after connection');
console.log('KUSS cache, session and reconnection tests passed');
