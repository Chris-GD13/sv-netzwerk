import fs from 'node:fs';import assert from 'node:assert/strict';import os from 'node:os';import path from 'node:path';
import {DatabaseSync} from 'node:sqlite';
import {planSourceRefresh} from '../browser-extension/claimsforce-bridge/source-refresh-plan.mjs';
const dir=fs.mkdtempSync(path.join(os.tmpdir(),'svnet-refresh-offline-')),db=new DatabaseSync(path.join(dir,'fixture.sqlite'));
db.exec('CREATE TABLE files(k TEXT PRIMARY KEY, id TEXT, rev INTEGER, hash TEXT, metadata TEXT); CREATE TABLE operations(op TEXT PRIMARY KEY, result TEXT); CREATE TABLE history(k TEXT, rev INTEGER, hash TEXT);');
const expected={profile:'fixture-christian',caseId:'fixture-A',sourceFileId:'original-A'},old={...expected,portalFileId:'portal-existing',revision:1,sha256:'a'.repeat(64),originalVerified:true},now={...expected,sha256:'b'.repeat(64),originalVerified:true,readOnly:true};
const metadata=JSON.stringify({folder:'reports',annotations:['retain'],reviewStatus:'reviewed',caseAssignment:'fixture-A'});
const tests=[];function test(name,fn){fn();tests.push({name,ok:true});}
const plan=planSourceRefresh(expected,old,now);db.prepare('INSERT INTO files VALUES(?,?,?,?,?)').run(plan.key,old.portalFileId,1,old.sha256,metadata);
function commit(p,{crash=false}={}){
 db.exec('BEGIN IMMEDIATE');try{
  const prior=db.prepare('SELECT result FROM operations WHERE op=?').get(p.operationKey);
  if(prior){db.exec('COMMIT');return JSON.parse(prior.result);}
  const row=db.prepare('SELECT * FROM files WHERE k=?').get(p.key);
  if(!row||row.id!==p.portalFileId||row.rev!==p.expectedRevision||row.hash!==p.expectedSha256)throw Error('REFRESH_CONFLICT');
  db.prepare('INSERT INTO history VALUES(?,?,?)').run(p.key,row.rev,row.hash);
  const changed=db.prepare('UPDATE files SET rev=rev+1,hash=? WHERE k=? AND rev=? AND hash=?').run(p.nextSha256,p.key,p.expectedRevision,p.expectedSha256);
  assert.equal(changed.changes,1);
  if(crash)throw Error('SIMULATED_INTERRUPTION');
  const result={id:row.id,rev:row.rev+1,hash:p.nextSha256};db.prepare('INSERT INTO operations VALUES(?,?)').run(p.operationKey,JSON.stringify(result));db.exec('COMMIT');return result;
 }catch(e){db.exec('ROLLBACK');throw e;}
}
test('unchanged-content-no-new-file',()=>assert.equal(planSourceRefresh(expected,old,{...now,sha256:old.sha256}).action,'NOOP'));
test('changed-content-plans-exact-existing-record',()=>{assert.equal(plan.action,'REPLACE_EXISTING_CONTENT');assert.equal(plan.portalFileId,old.portalFileId);assert.equal(plan.retainPriorVersion,true);});
test('same-name-is-not-document-identity',()=>assert.throws(()=>planSourceRefresh(expected,old,{...now,name:'SE.pdf',sourceFileId:'foreign'}),/MISMATCH/));
test('foreign-case-blocked',()=>assert.throws(()=>planSourceRefresh(expected,old,{...now,caseId:'fixture-B'}),/MISMATCH/));
test('foreign-profile-blocked',()=>assert.throws(()=>planSourceRefresh(expected,old,{...now,profile:'fixture-marc'}),/MISMATCH/));
test('metadata-only-is-not-content-evidence',()=>assert.throws(()=>planSourceRefresh(expected,old,{...now,originalVerified:false}),/UNVERIFIED/));
test('no-new-record-or-guessed-revision',()=>assert.throws(()=>planSourceRefresh(expected,{...old,revision:undefined},now),/EXISTING/));
test('failure-before-commit-rolls-back-content-and-history',()=>{assert.throws(()=>commit(plan,{crash:true}),/INTERRUPTION/);assert.equal(db.prepare('SELECT rev FROM files').get().rev,1);assert.equal(db.prepare('SELECT count(*) n FROM history').get().n,0);});
let result;
test('atomic-replace-preserves-id-and-all-review-metadata',()=>{result=commit(plan);const row=db.prepare('SELECT * FROM files').get();assert.equal(row.id,old.portalFileId);assert.equal(row.hash,now.sha256);assert.equal(row.metadata,metadata);assert.equal(row.rev,2);});
test('lost-acknowledgment-replay-reconciles-without-second-change',()=>{assert.deepEqual(commit(plan),result);assert.equal(db.prepare('SELECT count(*) n FROM files').get().n,1);assert.equal(db.prepare('SELECT count(*) n FROM history').get().n,1);});
test('competing-stale-plan-rejected',()=>{const competing=planSourceRefresh(expected,old,{...now,sha256:'c'.repeat(64)});assert.throws(()=>commit(competing),/CONFLICT/);assert.equal(db.prepare('SELECT hash FROM files').get().hash,now.sha256);});
test('independent-SQL-readback-verifies-content-and-destination',()=>{const verify=new DatabaseSync(path.join(dir,'fixture.sqlite'),{readOnly:true});const row=verify.prepare('SELECT * FROM files WHERE k=?').get(plan.key);assert.equal(row.hash,now.sha256);assert.equal(row.id,old.portalFileId);assert.equal(row.metadata,metadata);verify.close();});
db.close();
console.log(JSON.stringify({passed:tests.length,total:tests.length}));
