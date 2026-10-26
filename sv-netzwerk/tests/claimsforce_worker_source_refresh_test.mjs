import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import { webcrypto } from 'node:crypto';
import { readSourceOrRecordLegacyConflict } from '../browser-extension/claimsforce-bridge/source-read-isolation.mjs';
const code=fs.readFileSync(new URL('../browser-extension/claimsforce-bridge/service-worker.js',import.meta.url),'utf8');
const helper=code.slice(code.indexOf('async function prepareSourceUpload('),code.indexOf('async function uploadBuffer('));
const worker=code.slice(code.indexOf('async function runImport('),code.indexOf('async function startImport('));
const claimId='11111111-1111-4111-8111-111111111111',sourceFileId='22222222-2222-4222-8222-222222222222';
const file={id:sourceFileId,name:'SE.pdf',updatedAt:'2026-10-17T10:00:00Z',size:4};
const version=[file.id,file.updatedAt,file.size,file.name].join('|');
async function execute(mode,contents){
  const events=[],uploads=[];let entry;const prior={claimsforce_file_versions:[version],claimsforce_sync_signature:'same-signature',claimsforce_list_version:'same-list'};
  const context={console,URL,Map,Set,Date,JSON,String,Number,Array,Error,Promise,TextEncoder,Uint8Array,AbortController,setTimeout,clearTimeout,crypto:webcrypto,btoa:s=>Buffer.from(s,'binary').toString('base64'),readSourceOrRecordLegacyConflict,
    BRIDGE_VERSION:'test',credentialDiagnostic:'',lastTaskDebug:{},profileKey:p=>p,credentialsFor:async()=>({value:{},source:'fixture'}),credentialMatchesProfile:()=>true,
    resetClaimsSession:async()=>{},claimsTab:async()=>({tab:{id:2},token:'fixture'}),readOpenTasks:async()=>0,ensureInvoicedListTab:async()=>{},openSelectedClaimTab:async()=>{},
    diagnostic:async()=>{},progress:async()=>{},safeRoute:x=>x,safeFileName:x=>x,assertSingleClaim:()=>{},claimFolderPath:()=>'',fileVersion:()=>version,messageVersion:()=>'',
    mergeClaimFiles:a=>a,mapClaim:d=>({claimsforce_claim_id:d.id,schaden_nr:'26-TEST'}),unwrap:(v,key)=>v?.[key]??v,fingerprint:async()=> 'same-signature',
    uploadBuffer:async()=>({result:{duplicate:false}}),portalOperation:async()=>({folderId:'case-folder'}),
    chrome:{storage:{session:{set:async()=>{}},local:{set:async()=>{}}},tabs:{get:async()=>({url:'https://web.claimsforce.com/invoiced'}),sendMessage:async()=>({ok:true,route:'/invoiced',claims:[{id:claimId,label:'26-TEST',listVersion:'same-list'}]})}},
    requestJson:async url=>url.endsWith('/client-files')?[]:url.endsWith('/files')?[file]:url.endsWith('/messages')||url.endsWith('/appointments')||url.endsWith('/stakeholders')||url.endsWith('/tasks')||url.endsWith('/folders')?[]:{id:claimId},
    fetch:async url=>{if(url.endsWith('/config'))return {json:async()=>({DISPOSITION_API_ENDPOINT:'https://d.fixture',COMMUNICATION_API_ENDPOINT:'https://c.fixture',FILES_API_ENDPOINT:'https://f.fixture'})};events.push('download');return {ok:true,headers:{get:()=> 'application/pdf'},arrayBuffer:async()=>new TextEncoder().encode(contents).buffer};},
    portal:async(_tab,message)=>{
      if(message.type==='PORTAL_SYNC_STATE')return {result:{existed:true,folderId:'case-folder',meta:prior}};
      if(message.type==='PORTAL_SOURCE_STATE'){events.push('original-read');return {result:{source_state:{kind:'EXISTING_SOURCE',context:{caseId:claimId,sourceFileId,profile:'christian'},portalFileId:'stable-id',originalPrecondition:{sha256:'prior-hash'}}}};}
      if(message.type==='PORTAL_SOURCE_UPLOAD_START'){entry={...message,chunks:[]};return {ok:true};}
      if(message.type==='PORTAL_UPLOAD_CHUNK'){entry.chunks.push(message.chunk);return {ok:true};}
      if(message.type==='PORTAL_SOURCE_UPLOAD_FINISH'){uploads.push(entry);return {result:{duplicate:false,file:{id:'stable-id'}}};}
      return {result:{}};
    }};
  vm.createContext(context);vm.runInContext(helper+worker+';globalThis.executeImport=runImport;',context);
  const result=await context.executeImport({mode,profile:'christian',portalTabId:1,claimNumber:mode==='single'?'26-TEST':''});
  return {events,uploads,result};
}
for(const mode of ['full','single']){
  const a=await execute(mode,'AAAA'),b=await execute(mode,'BBBB');
  assert.equal(a.result.updated,1);assert.equal(a.result.skipped,0);assert.equal(b.uploads.length,1);
  assert.deepEqual(a.events,['original-read','download']);
  assert.equal(a.uploads[0].sourceIdentity.sourceFileId,sourceFileId);
  assert.equal(a.uploads[0].sourceIdentity.claimId,claimId);
  assert.equal(a.uploads[0].sourceIdentity.profile,'christian');
  assert.equal(a.uploads[0].originalReadState.portalFileId,'stable-id');
  assert.notEqual(a.uploads[0].sourceSha256,b.uploads[0].sourceSha256);
  assert.equal(Buffer.concat(b.uploads[0].chunks.map(c=>Buffer.from(c,'base64'))).toString(),'BBBB');
}
console.log('Worker actual full/single import loop: same file ID/mtime/size and same case/list signature still reads bytes, preserves original CAS snapshot and sends changed SHA256 via source upload. Synthetic transports only.');
