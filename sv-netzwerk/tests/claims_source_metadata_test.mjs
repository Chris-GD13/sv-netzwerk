import assert from 'node:assert/strict';
import {readClaimsSource} from '../browser-extension/claimsforce-bridge/claims-source-reader.mjs';
const c='11111111-1111-4111-8111-111111111111',m='22222222-2222-4222-8222-222222222222',f='33333333-3333-4333-8333-333333333333';
const config={DISPOSITION_API_ENDPOINT:'https://lrb5f35qpf.execute-api.eu-central-1.amazonaws.com/Prod',COMMUNICATION_API_ENDPOINT:'https://os0q3t0kde.execute-api.eu-central-1.amazonaws.com/Prod',FILES_API_ENDPOINT:'https://files-api.claimsforce.com'};
let files=[{id:f,claimId:c,name:'Rechnung.pdf',mimeType:'application/pdf',size:42,url:'SECRET',token:'SECRET'}],inventoryReads=0;
const attachments=[{id:f,type:'CLAIM_FILE'},{id:m,type:'CLAIM_FILE'}];
const adapter={profile:'christian',config,bytes:async()=>{throw Error('UNEXPECTED_BINARY_READ');},json:async url=>{
  if(url===`${config.DISPOSITION_API_ENDPOINT}/claims/${c}`)return {claim:{id:c}};
  if(url.endsWith('/messages'))return {messages:[{id:m,attachments}]};
  if(url.endsWith(`/messages/${m}`))return {id:m,claimId:c,body:'Mail remains readable',attachments};
  if(url.endsWith('/files')){inventoryReads++;if(files instanceof Error)throw files;return {files};}
  throw Error('UNEXPECTED_ENDPOINT');
}};
for(const kind of ['messages',`message:${m}`]){
  const before=inventoryReads,result=await readClaimsSource(`${c}:${kind}`,adapter);
  assert.equal(inventoryReads-before,1);
  assert.equal(result.attachmentMetadataStatus,'available');
  assert.deepEqual(result.documents,[{id:f,name:'Rechnung.pdf',mimeType:'application/pdf',size:42,contentRead:false}]);
  const actual=result.message?.attachments??result.messages[0].attachments;
  assert.equal(actual[0].name,'Rechnung.pdf');assert.equal(actual[0].contentRead,false);
  assert.deepEqual(actual[1],attachments[1]);
  assert.ok(!JSON.stringify(result).includes('SECRET'));
}
for(const bad of [new Error('denied'),null,{},[{id:'bad'}],[{id:f,claimId:m}],[{id:f},{id:f}]]){
  files=bad;
  const result=await readClaimsSource(`${c}:message:${m}`,adapter);
  assert.equal(result.attachmentMetadataStatus,'unavailable');
  assert.deepEqual(result.documents,[]);assert.equal(result.message.body,'Mail remains readable');
}
const before=inventoryReads;
await assert.rejects(()=>readClaimsSource(`${c}:message:${f}`,adapter),/SOURCE_MESSAGE_NOT_IN_CLAIM/);
assert.equal(inventoryReads,before);
console.log('PASS: metadata selection without binary reads; one bounded inventory; no private URLs; foreign/duplicate/malformed inventory rejected; mail fallback preserved');
