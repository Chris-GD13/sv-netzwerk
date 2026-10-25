import assert from 'node:assert/strict';
import {readSourceOrRecordLegacyConflict} from '../browser-extension/claimsforce-bridge/source-read-isolation.mjs';

const events = [], complete = new Set(), unavailable = new Set();
for (const id of ['legacy', 'current']) {
  const state = await readSourceOrRecordLegacyConflict(async () => {
    if (id === 'legacy') throw Error('LEGACY_SOURCE_BINDING_REQUIRED: original unchanged');
    return {kind:'EXISTING_SOURCE', id};
  }, async error => { unavailable.add(id); events.push(error.message); });
  if (state === null) continue;
  complete.add(id);
}
// The native message remains readable, but its failed attachment is not complete.
events.push({messageId:'task-message', attachmentAvailable:!unavailable.has('legacy')});
assert.deepEqual([...complete], ['current']);
assert.equal(events[1].messageId, 'task-message');
assert.equal(events[1].attachmentAvailable, false);
for (const code of ['SOURCE_CASE_ACCESS_DENIED', 'SOURCE_CASE_PROFILE_MISMATCH', 'SOURCE_CONTEXT_REQUIRED', 'network failed', 'PREFIX LEGACY_SOURCE_BINDING_REQUIRED']) {
  await assert.rejects(() => readSourceOrRecordLegacyConflict(async()=>{throw Error(code);}, ()=>{throw Error('must not swallow');}), e=>e.message===code);
}
await assert.rejects(()=>readSourceOrRecordLegacyConflict(async()=>{throw Error('LEGACY_SOURCE_BINDING_REQUIRED');},async()=>{throw Error('record failed');}),/record failed/);
console.log('PASS: isolated legacy file, later source and task message retained, attachment incomplete; access/context/recording failures stop');
