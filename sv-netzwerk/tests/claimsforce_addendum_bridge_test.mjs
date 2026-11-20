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
report.evidence.push({type:'Calculation',id:'ALL'});
assert.deepEqual((await runNativeForm(request,io)).report.attachments[0],{id:'ALL',name:'Claimsforce-Kalkulation',version:null});
await assert.rejects(runNativeForm({...request,claimNumber:'FALSCH'},io),/Schadennummer/);
report.isLocked=true;
await assert.rejects(runNativeForm(request,io),/kein editierbarer Entwurf/);

report.isLocked=false;
const formIds={SE:'55555555-5555-4555-8555-555555555555',ZB:'66666666-6666-4666-8666-666666666666'};
const opened=[];
let ownedTabs=0;
let route='/claims/'+caseId+'/investigations';
const assigned=await runNativeForm({...request,forms:formIds},{
  ...io,
  createTab:async()=>{ownedTabs++;return {id:9};},
  waitTab:async()=>{},
  getTab:async()=>({url:'https://web.claimsforce.com'+route}),
  assertVisibleAccount:async()=>{},
  pause:async()=>{},
  closeTab:async id=>assert.equal(id,9),
  send:async(id,message)=>{
    assert.equal(id,9);
    if(message.type==='OPEN_EXISTING_NATIVE_FORM'){
      opened.push(message.formId);route='/claims/'+caseId+'/forms/'+message.formId;return {ok:true};
    }
    if(message.type==='READ_FORM_READINESS')return {ok:true,observation:{
      outerLoaded:true,viewerInitialized:true,identityVerified:true,fieldsReadable:true,fieldsEditable:true}};
    if(message.type==='NATIVE_FORM_SNAPSHOT')return {ok:true,result:{
      caseId,formId:message.formId,profile:request.profile,claimNumber:request.claimNumber,dirty:false,fields:[]}};
    if(message.type==='NAVIGATE_NATIVE_FORM_LIST'){route='/claims/'+caseId+'/investigations';return {ok:true};}
    if(message.type==='NATIVE_FORM_INVENTORY')return {ok:true,result:[
      {formId:formIds.SE,status:'draft',templateName:'SV Schlusserklärung'},
      {formId:formIds.ZB,status:'draft',templateName:'SV Zahlungsbefürwortung'}
    ]};
    assert.fail('Unexpected form operation '+message.type);
  }
});
assert.equal(ownedTabs,1);
assert.deepEqual(opened,Object.values(formIds));
assert.equal(assigned.forms.SE.formId,formIds.SE);
assert.equal(assigned.forms.ZB.formId,formIds.ZB);

const startmask=readFileSync(new URL('../public/intern/claimsforce-startmask.js',import.meta.url),'utf8');
const submit=startmask.slice(startmask.indexOf('const addendumRequest='),startmask.indexOf('const drawAddendum='));
assert.ok(!submit.includes('ensureCentralRunner'), 'A targeted readback does not launch the scheduled import page');
const workflow=readFileSync(new URL('../public/intern/api/claimsforce-workflow.php',import.meta.url),'utf8');
assert.ok(workflow.indexOf('return cfaWorkflowView($row)')<workflow.indexOf('FROM gf_ai_jobs'));
assert.ok(workflow.indexOf('Nachträge werden ausschließlich')<workflow.indexOf("if($action==='phase'"));
assert.ok(workflow.indexOf('Nachträge werden ausschließlich')<workflow.indexOf("if($action==='advance'"));
assert.ok(workflow.indexOf("apiJson(['ok'=>true,'job'=>cfwView($row)],201)")<workflow.indexOf("'single',:claim"));
