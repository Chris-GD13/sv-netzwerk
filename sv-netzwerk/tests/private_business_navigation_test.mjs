import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../public/intern/private-business-navigation.js', import.meta.url), 'utf8');
const layout = readFileSync(new URL('../src/layouts/InternalLayout.astro', import.meta.url), 'utf8');
assert.match(layout, /href: '\/intern\/privatauftraege\/angebot\/', label: 'Angebote erstellen'/);
assert.match(layout, /id="intern-offer-create-link"[^>]* hidden>/);
assert.equal((layout.match(/id="intern-offer-create-link"/g) || []).length, 1);
assert.match(layout, /src="\/intern\/private-business-navigation\.js\?v=/);

async function check(body, ok = true, failure = false) {
  const link = { hidden: false };
  let calls = 0;
  vm.runInNewContext(source, {
    document: { getElementById: id => { assert.equal(id, 'intern-offer-create-link'); return link; } },
    fetch: async (url, options) => {
      calls++;
      assert.equal(url, '/intern/api/private-business.php?action=access');
      assert.equal(options.credentials, 'same-origin');
      assert.equal(options.cache, 'no-store');
      if (failure) throw Error('network');
      return { ok, json: async () => body };
    },
  });
  assert.equal(link.hidden, true, 'link starts hidden until the server decision');
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(calls, 1);
  return link.hidden;
}
assert.equal(await check({ok:true, can_create:true}), false);
for (const body of [{ok:true,can_create:false}, {ok:false,can_create:true}, {ok:true,can_create:'true'}, {}]) {
  assert.equal(await check(body), true);
}
assert.equal(await check({ok:true,can_create:true}, false), true, 'HTTP failures never grant access');
assert.equal(await check({}, false, true), true);
vm.runInNewContext(source, {document:{getElementById:()=>null}, fetch:()=>{throw Error('should not load');}});
console.log('private_business_navigation_test: ok (direct link; server confirmation; fail closed; no browser profile grants)');
