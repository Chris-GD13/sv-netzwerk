import assert from 'node:assert/strict';import {performance} from 'node:perf_hooks';
import {inspectApryse} from '../browser-extension/claimsforce-bridge/apryse-readiness.mjs';
const expected={caseId:'synthetic-case',formId:'native-SE',profile:'christian',requiredFields:['insurerClaimId','Neuwert_Schaden']};
function setup(){let doc={},resolve;const listeners=new Set();let loaded=Promise.resolve();const fields=expected.requiredFields.map(name=>({name,type:'Tx',flags:{get:()=>false},getValue:()=>''}));const dv={getDocument:()=>doc,getAnnotationsLoadedPromise:()=>loaded,addEventListener:(_,f)=>listeners.add(f),removeEventListener:(_,f)=>listeners.delete(f)};return {fields,listeners,instance:{Core:{documentViewer:dv,annotationManager:{getFieldManager:()=>({getFields:()=>fields})}}},identity:async()=>({...expected,mode:'FORM'}),delay(){doc=null;loaded=new Promise(r=>resolve=r);setTimeout(()=>{doc={};listeners.forEach(f=>f());setTimeout(()=>resolve(),15);},15);},never(){doc=null;},annotationsNever(){loaded=new Promise(()=>{});}};}
const tests=[];
async function test(name,fn){const start=performance.now();await fn();tests.push({name,ok:true,ms:performance.now()-start});}
await test('delayed-document-plus-annotations',async()=>{const s=setup();s.delay();const r=await inspectApryse(s.instance,expected,s.identity,300);assert.equal(r.ok,true);assert.equal(s.listeners.size,0);assert.equal('values' in r,false);});
for(const [name,mutate,state] of [
 ['missing-SDK',s=>{s.instance={};},'sdk-unavailable'],
 ['document-timeout',s=>s.never(),'timeout'],
 ['annotations-timeout',s=>s.annotationsNever(),'timeout'],
 ['wrong-case',s=>{s.identity=async()=>({...expected,caseId:'foreign',mode:'FORM'});},'identity-mismatch'],
 ['locked-original',s=>{s.identity=async()=>({...expected,mode:'FORM',locked:true});},'readonly'],
 ['field-readonly',s=>{s.fields[0].flags.get=()=>true;},'readonly'],
 ['field-missing',s=>{s.fields.pop();},'field-missing-or-duplicate'],
 ['field-duplicate',s=>{s.fields.push(s.fields[0]);},'field-missing-or-duplicate'],
 ['unreadable',s=>{s.fields[0].getValue=()=>{throw Error('blocked');};},'observation-blocked']
])await test(name,async()=>{const s=setup();mutate(s);const r=await inspectApryse(s.instance,expected,s.identity,35);assert.equal(r.state,state);assert.equal(s.listeners.size,0);});
const result={passed:tests.length,total:tests.length,tests,limitations:'SDK interfaces mocked from observed Claims source and public Apryse docs. No real viewer-instance binding or widget/permission proof; no field writes.'};console.log(JSON.stringify(result));
