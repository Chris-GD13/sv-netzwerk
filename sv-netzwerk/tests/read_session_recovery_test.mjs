import assert from 'node:assert/strict';
import {recoverReadOnlySession} from '../browser-extension/claimsforce-bridge/read-only-session.mjs';
const token = email => `x.${Buffer.from(JSON.stringify({email,exp:9999999999})).toString('base64url')}.x`;
const row = email => ({claimsToken:token(email),claimsTokenProfile:email==='cw@test.invalid'?'christian':'jens'});
function fixture(initial, recovered) {
  let state=initial, time=0, opened=0, closed=[];
  return {
    options:{profile:'christian',expectedEmail:'cw@test.invalid',readSession:async()=>state,emailOf:t=>JSON.parse(Buffer.from(t.split('.')[1],'base64url')).email,now:()=>time},
    io:{open:async()=>{opened++;return{id:987};},close:async id=>{closed.push(id);},now:()=>time,pause:async ms=>{time+=ms;if(recovered)state=recovered;}},
    stats:()=>({opened,closed,time})
  };
}
let f=fixture(row('cw@test.invalid'));
assert.equal(await recoverReadOnlySession(f.options,f.io),token('cw@test.invalid'));
assert.equal(f.stats().opened,0);
f=fixture({},row('cw@test.invalid'));
assert.equal(await recoverReadOnlySession(f.options,f.io),token('cw@test.invalid'));
assert.deepEqual(f.stats(),{opened:1,closed:[987],time:200});
f=fixture(row('jens@test.invalid'));
await assert.rejects(recoverReadOnlySession(f.options,f.io),/CF-READ-SESSION:/);
assert.equal(f.stats().opened,0);
f=fixture({},row('jens@test.invalid'));
await assert.rejects(recoverReadOnlySession(f.options,f.io),/CF-READ-SESSION:/);
assert.deepEqual(f.stats().closed,[987]);
f=fixture({});
await assert.rejects(recoverReadOnlySession(f.options,f.io),/CF-READ-SESSION-RECOVERY:/);
assert.deepEqual(f.stats(),{opened:1,closed:[987],time:15000});
f=fixture({});f.io.open=async()=>{throw Error('TAB_UNAVAILABLE');};
await assert.rejects(recoverReadOnlySession(f.options,f.io),/TAB_UNAVAILABLE/);
assert.deepEqual(f.stats().closed,[]);
console.log('Read session recovery: six scenarios passed; no shared tab mutation or account switch.');
