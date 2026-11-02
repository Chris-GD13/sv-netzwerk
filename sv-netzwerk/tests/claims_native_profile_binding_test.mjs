import assert from 'node:assert/strict';
import fs from 'node:fs';
const worker=fs.readFileSync(new URL('../browser-extension/claimsforce-bridge/service-worker.js',import.meta.url),'utf8');
const nativeWorker=fs.readFileSync(new URL('../browser-extension/claimsforce-bridge/native-form-worker.mjs',import.meta.url),'utf8');
const navigation=fs.readFileSync(new URL('../browser-extension/claimsforce-bridge/native-form-navigation.mjs',import.meta.url),'utf8');
for(const profile of ['christian','holger','marc','jens']) {
  assert.match(worker,new RegExp(profile),profile+' muss im Service Worker bekannt sein');
}
assert.match(nativeWorker,/NATIVE_PROFILES/);
assert.match(navigation,/NATIVE_PROFILES/);
assert.match(worker,/assertVisibleAccount/);
assert.match(worker,/READ_ACCOUNT_IDENTITY/);
console.log('Native profile binding contract passed; no live account accessed.');
