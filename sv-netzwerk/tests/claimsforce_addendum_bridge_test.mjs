import assert from 'node:assert/strict';
import {performance} from 'node:perf_hooks';
import {runNativeForm} from '../browser-extension/claimsforce-bridge/native-form-worker.mjs';
import {readFileSync} from 'node:fs';

const caseId='11111111-1111-4111-8111-111111111111';
const reportId='22222222-2222-4222-8222-222222222222';
const operationId='33333333-3333-4333-8333-333333333333';
const fileId='44444444-4444-4444-8444-444444444444';
const request={action:'check_addendum',caseId,reportId,operationId,profile:'christian',claimNumber:'SYNTHETISCH-01',forms:{}};
const calls=[];
const report={id:reportId,claimId:caseId,isLocked:false,status:'IN_PROGRESS',evidence:[],
  content:{nachtrag:{text:'Gespeicherter Testnachtrag'}},
  template:{definition:{properties:{nachtrag:{properties:{text:{title:'Nachtragstext'}}}}}}};
const io={
  assertAccount:async profile=>assert.equal(profile,request.profile),
  config:async()=>({DISPOSITION_API_ENDPOINT:'https://disposition.claimsforce.com',
    ASSESSMENT_API_ENDPOINT:'https://assessment.claimsforce.com',FILES_API_ENDPOINT:'https://files.claimsforce.com'}),
  json:async url=>{
    calls.push(url);
    if(url.endsWith('/investigations'))return {investigations:[report]};
    if(url.endsWith('/files'))return {files:[{id:fileId,fileName:'Rechnung.pdf',updatedDate:'2026-11-20',size:111}]};
    return {claim:{id:caseId,insurerClaimId:request.claimNumber}};
  },
  createTab:async()=>assert.fail('Small addendum opened a form tab'),
  send:async()=>assert.fail('Small addendum sent a form message')
};
const started=performance.now();
for(let i=0;i<1000;i++){
  const result=await runNativeForm(request,io);
  assert.equal(result.report.text,'Gespeicherter Testnachtrag');
  assert.deepEqual(result.forms,{});
  assert.deepEqual(result.report.attachments,[]);
}
const average=(performance.now()-started)/1000;
assert.deepEqual([...new Set(calls)],[
  'https://disposition.claimsforce.com/claims/'+caseId,
  'https://assessment.claimsforce.com/claims/'+caseId+'/investigations'
]);
assert.equal(calls.length,2000);
console.log('Kleiner Browserauftrag: '+average.toFixed(3)+' ms im Mittel (1000 Durchläufe, simulierte API), 2 gezielte GETs, 0 Formularaufrufe.');

report.evidence=[{type:'Document',id:fileId}];
const withFile=await runNativeForm(request,io);
assert.deepEqual(withFile.report.attachments,[{id:fileId,name:'Rechnung.pdf',version:fileId+'|2026-11-20|111|Rechnung.pdf'}]);
await assert.rejects(runNativeForm({...request,claimNumber:'FALSCH'},io),/Schadennummer/);
report.isLocked=true;
await assert.rejects(runNativeForm(request,io),/kein editierbarer Entwurf/);

const workflow=readFileSync(new URL('../public/intern/api/claimsforce-workflow.php',import.meta.url),'utf8');
assert.ok(workflow.indexOf('return cfaWorkflowView($row)')<workflow.indexOf('FROM gf_ai_jobs'));
assert.ok(workflow.indexOf('Nachträge werden ausschließlich')<workflow.indexOf("if($action==='phase'"));
assert.ok(workflow.indexOf('Nachträge werden ausschließlich')<workflow.indexOf("if($action==='advance'"));
assert.ok(workflow.indexOf("apiJson(['ok'=>true,'job'=>cfwView($row)],201)")<workflow.indexOf("'single',:claim"));
