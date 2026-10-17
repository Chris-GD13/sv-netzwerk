import fs from 'node:fs';import vm from 'node:vm';import assert from 'node:assert/strict';import {performance} from 'node:perf_hooks';
import {waitForViewer,viewerState} from '../browser-extension/claimsforce-bridge/draft-agent-core.mjs';
import {moneyCents,validateLinkedEvidence} from '../browser-extension/claimsforce-bridge/case-evidence-adapter.mjs';
const folder=new URL('../browser-extension/claimsforce-bridge/',import.meta.url);
const tests=[];async function test(name,fn){const t=performance.now();try{const detail=await fn();tests.push({name,ok:true,ms:performance.now()-t,detail});}catch(e){tests.push({name,ok:false,error:e.stack});}}
function documentMock({duplicate=false,readonly=false,missing=false,blocked=false}={}) {
 const input={name:'Schaden-Nr.',value:'offline',disabled:false,readOnly:readonly,getAttribute:()=>null,getClientRects:()=>[{}]};
 const inner={body:{},readyState:'complete',querySelectorAll:()=>missing?[]:duplicate?[input,input]:[input]};
 const frame={id:'webviewer-3',getAttribute:()=>'/apryse/11.11.0/./ui/index.html#a=1&basePath=%2Fclaims%2Foffline%2Fforms%2Fform-1',get contentDocument(){if(blocked)throw new Error('cross-origin');return inner;}};
 return {readyState:'complete',querySelectorAll:()=>[frame]};
}
const location={href:'https://web.claimsforce.com/claims/offline/forms/form-1'};
function probe(doc,args={}){
 const window={};vm.runInNewContext(fs.readFileSync(new URL('form-readiness.js',folder),'utf8'),{window,URL,URLSearchParams});
 return window.SVNET_FORM_READINESS.observe(doc,location,{caseId:'offline',formId:'form-1',requiredFields:['Schaden-Nr.'],...args});
}
await test('native-dom-probe-ready-with-exact-fields',()=>assert.equal(viewerState(probe(documentMock()).observation),'ready'));
for(const [name,opts,expected] of [['readonly',{readonly:true},'form-unverified'],['missing',{missing:true},'form-unverified'],['duplicate',{duplicate:true},'form-unverified'],['blocked',{blocked:true},'observation-blocked']])await test(`native-dom-${name}`,()=>assert.equal(viewerState(probe(documentMock(opts)).observation),expected));
await test('native-dom-no-mapping-fails-closed',()=>assert.equal(viewerState(probe(documentMock(),{requiredFields:[]}).observation),'form-unverified'));
await test('native-dom-wrong-case',()=>assert.equal(viewerState(probe(documentMock(),{caseId:'other'}).observation),'identity-unverified'));
await test('native-dom-dynamic-viewer-3-not-fixed-viewer-1',()=>assert.equal(probe(documentMock()).observation.viewerFrameFound,true));
await test('native-dom-rejects-duplicate-matching-iframes',()=>{const doc=documentMock(),frame=doc.querySelectorAll()[0];doc.querySelectorAll=()=>[frame,frame];assert.equal(probe(doc).observation.viewerFrameCount,2);assert.notEqual(viewerState(probe(doc).observation),'ready');});
await test('native-dom-rejects-different-form-basePath',()=>{const doc=documentMock(),frame=doc.querySelectorAll()[0];frame.getAttribute=()=>'/apryse/11.11.0/ui/index.html#a=1&basePath=%2Fclaims%2Fother%2Fforms%2Fform-1';assert.equal(probe(doc).observation.viewerFrameCount,0);});
await test('native-dom-rejects-foreign-frame-origin',()=>{const doc=documentMock(),frame=doc.querySelectorAll()[0];frame.getAttribute=()=> 'https://other.invalid/apryse/11.11.0/ui/index.html#a=1&basePath=%2Fclaims%2Foffline%2Fforms%2Fform-1';assert.equal(probe(doc).observation.viewerFrameCount,0);});
await test('native-dom-matches-double-encoded-observed-basePath',()=>{const doc=documentMock(),frame=doc.querySelectorAll()[0];frame.getAttribute=()=>'/apryse/11.11.0/ui/index.html#a=1&basePath=%252Fclaims%252Foffline%252Fforms%252Fform-1';assert.equal(viewerState(probe(doc).observation),'ready');});
await test('native-dom-parent-isolation-is-unknown-not-a-blank-viewer-proof',()=>{const observed=probe(documentMock({blocked:true})).observation;assert.equal(observed.viewerFrameFound,true);assert.equal(observed.observationBlocked,true);assert.equal(viewerState(observed),'observation-blocked');});
await test('existing-content-script-handler-rejects-foreign-sender',()=>{
 const handlers=[],window={addEventListener(){}};
 window.SVNET_FORM_READINESS={observe:()=>{throw new Error('must not run');}};
 vm.runInNewContext(fs.readFileSync(new URL('claims-bridge.js',folder),'utf8'),{window,document:{},location,chrome:{runtime:{id:'own-extension',onMessage:{addListener:h=>handlers.push(h)}}},Map,Set});
 let response;handlers[0]({type:'READ_FORM_READINESS'},{id:'other-extension'},v=>response=v);assert.equal(response.state,'sender-rejected');
});
await test('native-navigation-preserves-unsaved-user-edits',()=>{
 const handlers=[],window={addEventListener(){}};let clicks=0;
 const caseId='11111111-1111-4111-8111-111111111111',formId='22222222-2222-4222-8222-222222222222';
 const document={querySelectorAll:selector=>selector==='button'?[{textContent:'Entwurf speichern',disabled:false}]:[{textContent:'Zurück',getAttribute:()=>`/claims/${caseId}/investigations`,getClientRects:()=>[{}],click:()=>clicks++}]};
 vm.runInNewContext(fs.readFileSync(new URL('claims-bridge.js',folder),'utf8'),{window,document,location:{pathname:`/claims/${caseId}/forms/${formId}`},chrome:{runtime:{id:'own-extension',onMessage:{addListener:h=>handlers.push(h)}}},Map,Set});
 let response;handlers[0]({type:'NAVIGATE_NATIVE_FORM_LIST',caseId,formId},{id:'own-extension'},v=>response=v);
 assert.equal(response.error,'native-form-unsaved-or-unverified');assert.equal(clicks,0);
});
const worker=fs.readFileSync(new URL('service-worker.js',folder),'utf8');
const waitCode=worker.slice(worker.indexOf('async function waitTab('),worker.indexOf('async function resetClaimsSession('));
await test('actual-worker-form-route-does-not-return-on-tab-complete',async()=>{
 let t=0,probes=0;const context={Date:{now:()=>t},sleep:async ms=>{t+=ms;},waitForViewer,
  chrome:{tabs:{get:async()=>({status:'complete',url:location.href}),sendMessage:async(_id,m)=>{probes++;return probe(documentMock(),m);}}}};
 vm.runInNewContext(waitCode+';this.waitTab=waitTab;',context);
 await assert.rejects(()=>context.waitTab(1,1000,{caseId:'offline',formId:'form-1',requiredFields:[]}),/CF-FORM-NOT-READY/);assert.equal(t,1000);assert.ok(probes>1);return {probes,simulatedMs:t};
});
await test('actual-worker-existing-import-route-unchanged',async()=>{
 let t=0;const context={Date:{now:()=>t},sleep:async ms=>{t+=ms;},waitForViewer,chrome:{tabs:{get:async()=>({status:'complete',url:'https://web.claimsforce.com/planning'}),sendMessage:()=>{throw Error('unexpected');}}}};
 vm.runInNewContext(waitCode+';this.waitTab=waitTab;',context);await context.waitTab(1);assert.equal(t,1200);
});
await test('actual-worker-restored-form-tab-does-not-block-unrelated-import',async()=>{
 let t=0;const context={Date:{now:()=>t},sleep:async ms=>{t+=ms;},waitForViewer,chrome:{tabs:{get:async()=>({status:'complete',url:location.href}),sendMessage:()=>{throw Error('must not inspect fields without explicit form operation');}}}};
 vm.runInNewContext(waitCode+';this.waitTab=waitTab;',context);await context.waitTab(1);assert.equal(t,1200);
});
for(const [value,expected] of [['',null],['1.234,56',123456],['27399.05',2739905],['11215,52',1121552],['0',0]])await test(`money-${value||'blank'}`,()=>assert.equal(moneyCents(value),expected));
await test('ambiguous-value-rejected',()=>assert.throws(()=>moneyCents('1.234'),/AMBIGUOUS/));
await test('linked-evidence-explicit-two-case-numbers',()=>{
 const claims=[{caseNumber:'test-building',profile:'christian',totalCents:1700000},{caseNumber:'test-household',profile:'christian',totalCents:800000}];
 assert.throws(()=>validateLinkedEvidence({profile:'christian',relationVerified:true},claims),/LINK_PROOF/);
 const g={profile:'christian',relationVerified:true,relationSourceId:'letter',relationSourceHash:'hash',explicitCaseNumbers:claims.map(c=>c.caseNumber)};
 const r=validateLinkedEvidence(g,claims);assert.equal(r.reportingExposureCents,2500000);assert.equal(r.seByCase[0].totalCents,1700000);
 assert.throws(()=>validateLinkedEvidence({...g,explicitCaseNumbers:['test-building']},claims),/LINK_NUMBERS/);
});
const result={mode:'synthetic bridge integration; no private reference files',passed:tests.filter(x=>x.ok).length,total:tests.length,tests};

console.log(JSON.stringify({...result,tests:result.tests.filter(r=>!r.ok)}));if(result.passed!==result.total)process.exitCode=1;
