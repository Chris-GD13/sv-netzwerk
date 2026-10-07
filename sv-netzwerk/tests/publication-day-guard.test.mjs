import test from 'node:test';
import assert from 'node:assert/strict';
import { transition, berlinDate } from '../scripts/publication-day-guard.mjs';
const now = new Date('2026-10-07T10:00:00Z');
const empty = () => ({version:1,timezone:'Europe/Berlin',days:{}});
const input = {owner:'test-a',title:'Test',slug:'test',url:'https://www.sv-netzwerk.eu/fachwissen/test/'};
test('Berlin date follows local midnight',()=>assert.equal(berlinDate(new Date('2026-10-06T22:30:00Z')),'2026-10-07'));
test('second writer cannot reserve another article on same date',()=>{
 const first=transition(empty(),'claim-article',input,now);
 assert.equal(transition(first.register,'claim-article',{...input,owner:'test-b',slug:'other',url:'https://www.sv-netzwerk.eu/fachwissen/other/'},now).outcome,'existing');
});
test('LinkedIn requires live proof and cannot publish before seven',()=>{
 const first=transition(empty(),'claim-article',input,now);
 assert.throws(()=>transition(first.register,'claim-linkedin',input,now),/Livegang/);
 const live=transition(first.register,'verify-website',{commit:'a'.repeat(40),run_url:'https://github.com/Chris-GD13/sv-netzwerk/actions/runs/123'},now);
 assert.throws(()=>transition(live.register,'claim-linkedin',input,new Date('2026-10-07T04:59:00Z')),/07:00/);
 const claim=transition(live.register,'claim-linkedin',input,now);
 assert.equal(transition(claim.register,'claim-linkedin',{owner:'test-b'},now).outcome,'existing');
 assert.throws(()=>transition(claim.register,'verify-linkedin',{owner:'test-b',permalink:'https://www.linkedin.com/feed/update/urn:li:activity:123/'},now),/Eigene/);
 const done=transition(claim.register,'verify-linkedin',{owner:'test-a',permalink:'https://www.linkedin.com/feed/update/urn:li:activity:123/'},now);
 assert.throws(()=>transition(done.register,'verify-linkedin',{owner:'test-a',permalink:'https://www.linkedin.com/feed/update/urn:li:activity:456/'},now),/Duplikat/);
});
