import assert from 'node:assert/strict';
import {webcrypto} from 'node:crypto';
if (!globalThis.crypto) Object.defineProperty(globalThis, 'crypto', {value:webcrypto});
import {uploadReportGuarded, sha256} from '../browser-extension/claimsforce-bridge/claims-report-upload-guard.mjs';
// Synthetic bytes and fake storage only. A ZIP signature is not DOCX validation.
const bytes = new Uint8Array([0x50,0x4b,3,4,1,2,3]);
const c = {profile:'christian',operationId:'11111111-1111-4111-8111-111111111111',caseId:'22222222-2222-4222-8222-222222222222',claimNumber:'SYNTHETIC-1',folderId:'folder',fileName:'SYNTHETIC.docx',mime:'application/vnd.openxmlformats-officedocument.wordprocessingml.document',sha256:await sha256(bytes)};
function fixture() {
  let files=[], writes=0, tail=Promise.resolve(), data=new Map(), journal=new Map(), snapshot={profile:c.profile,caseId:c.caseId,claimNumber:c.claimNumber,folderId:c.folderId,folderLabel:'Berichte',path:'Dateien/Berichte',complete:true};
  const io={withCaseLock:async(p,id,fn)=>{const last=tail;let release;tail=new Promise(r=>release=r);await last;try{return await fn()}finally{release()}},assertAccount:async()=>{},inspect:async()=>({...snapshot,files:structuredClone(files)}),uploadNew:async(cmd,b)=>{writes++;const id='new-id';files.push({id,name:cmd.fileName});data.set(id,b.slice());return id},readBack:async(cmd,id)=>data.get(id),journal:{get:async id=>journal.get(id),set:async(id,x)=>journal.set(id,structuredClone(x))}};
  return {io, get writes(){return writes}, set snapshot(x){snapshot=x}, get snapshot(){return snapshot}, add(f){files.push(f)}, data};
}
let f=fixture();let r=await Promise.all([uploadReportGuarded(c,bytes,f.io),uploadReportGuarded(c,bytes,f.io)]);assert.equal(f.writes,1);assert.equal(r[1].reconciled,true);
await assert.rejects(()=>uploadReportGuarded({...c,fileName:'other.docx'},bytes,f.io),/OPERATION_ID_CONFLICT/);
f=fixture();f.add({id:'existing',name:'synthetic.docx'});await assert.rejects(()=>uploadReportGuarded(c,bytes,f.io),/FILENAME_CONFLICT/);assert.equal(f.writes,0);
f=fixture();f.snapshot={...f.snapshot,claimNumber:'WRONG'};await assert.rejects(()=>uploadReportGuarded(c,bytes,f.io),/IDENTITY_OR_LIST/);assert.equal(f.writes,0);
f=fixture();f.snapshot={...f.snapshot,complete:false};await assert.rejects(()=>uploadReportGuarded(c,bytes,f.io),/IDENTITY_OR_LIST/);assert.equal(f.writes,0);
f=fixture();await assert.rejects(()=>uploadReportGuarded({...c,profile:'other'},bytes,f.io),/INVALID_REQUEST/);assert.equal(f.writes,0);
f=fixture();await assert.rejects(()=>uploadReportGuarded({...c,sha256:'0'.repeat(64)},bytes,f.io),/SOURCE_HASH/);assert.equal(f.writes,0);
f=fixture();const upload=f.io.uploadNew;f.io.uploadNew=async(...args)=>{await upload(...args);throw Error('connection lost')};await assert.rejects(()=>uploadReportGuarded(c,bytes,f.io),/connection lost/);await assert.rejects(()=>uploadReportGuarded(c,bytes,f.io),/UNCERTAIN/);assert.equal(f.writes,1);
f=fixture();f.io.readBack=async()=>new Uint8Array([0]);await assert.rejects(()=>uploadReportGuarded(c,bytes,f.io),/READBACK_MISMATCH/);await assert.rejects(()=>uploadReportGuarded(c,bytes,f.io),/UNCERTAIN/);
console.log('Synthetic guard tests passed; no Claimsforce transport or case data.');
