import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {requireReadOnlySession, capturedTokenProfile} from '../browser-extension/claimsforce-bridge/read-only-session.mjs';

const now = 1800000000000;
const jwt = exp => `header.${Buffer.from(JSON.stringify({exp})).toString('base64url')}.signature`;
const token = jwt(now / 1000 + 600);
const base = {profile:'test', expectedEmail:'test@example.test', emailOf:()=> 'test@example.test', now:()=>now,
  readSession:async()=>({claimsToken:token,claimsTokenProfile:'test'})};
test('capture after extension reload derives account from token, without stale activeProfile',()=>assert.equal(capturedTokenProfile(token,{test:'test@example.test'},base.emailOf),'test'));
test('capture does not relabel another account as selected profile',()=>assert.equal(capturedTokenProfile(token,{test:'test@example.test',other:'other@example.test'},()=> 'other@example.test'),'other'));
test('unknown captured account is rejected',()=>assert.throws(()=>capturedTokenProfile(token,{test:'test@example.test'},()=> 'unknown@example.test'),/CF-TOKEN-ACCOUNT/));
test('same-profile live token is reused without mutation hooks', async()=>assert.equal(await requireReadOnlySession(base),token));
test('other profile fails closed', async()=>assert.rejects(requireReadOnlySession({...base,profile:'other'}),/CF-READ-SESSION:/));
test('wrong account fails closed', async()=>assert.rejects(requireReadOnlySession({...base,emailOf:()=> 'other@example.test'}),/CF-READ-SESSION:/));
test('missing token fails closed', async()=>assert.rejects(requireReadOnlySession({...base,readSession:async()=>({})}),/CF-READ-SESSION:/));
test('expired token fails without logout/login fallback', async()=>assert.rejects(requireReadOnlySession({...base,readSession:async()=>({claimsToken:jwt(now/1000-1),claimsTokenProfile:'test'})}),/EXPIRED/));
test('unknown expiry fails closed', async()=>assert.rejects(requireReadOnlySession({...base,readSession:async()=>({claimsToken:jwt(undefined),claimsTokenProfile:'test'})}),/EXPIRED/));
test('source/tasks dispatch before legacy session reset and their handler has no browser mutation',()=>{
  const source=readFileSync(new URL('../browser-extension/claimsforce-bridge/service-worker.js',import.meta.url),'utf8');
  const handler=source.slice(source.indexOf('async function runReadOnlyImport'),source.indexOf('async function runImport'));
  assert.doesNotMatch(handler,/resetClaimsSession|claimsTab\(|chrome\.tabs|browsingData|credentialsFor|storage\.(local|session)\.(set|remove)/);
  assert.match(source,/async function runImport\(run\) \{\s*if \(run.mode === 'source' \|\| run.mode === 'tasks'\) return runReadOnlyImport\(run\);/);
});
