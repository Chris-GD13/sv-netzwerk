/** Run the merged page's actual keep/show/load code together with both controllers. */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {randomUUID} from 'node:crypto';
import {mountNotes} from '../public/intern/claims-notes-ui.mjs';
class Element extends EventTarget {
 constructor(){super();this.children=[];this.value='';this.textContent='';this.hidden=false;this.dataset={};this.classList={toggle(){}};}
 replaceChildren(...c){this.children=c;}append(...c){this.children.push(...c);}querySelector(){return null;}
}
const page=fs.readFileSync(new URL('../src/pages/intern/versicherungsfaelle/index.astro',import.meta.url),'utf8');
const assistant=fs.readFileSync(new URL('../public/intern/case-work-assistant.js',import.meta.url),'utf8');
const nodes=new Map();const el=id=>{if(!nodes.has(id))nodes.set(id,new Element());return nodes.get(id);};
const doc={getElementById:el,querySelectorAll:selector=>selector==='[data-assistant-task]'?[el('action')]:[],createElement:()=>new Element()};
el('action').dataset.assistantTask='check';el('vf-assistant-route').value='assistant';
const win=new EventTarget();win.location={origin:'http://localhost'};win.sent=[];win.postMessage=data=>win.sent.push(data);
class Custom extends Event{constructor(type,options){super(type);this.detail=options?.detail;}}
const requests=[];
const fetch=async(_url,opt)=>{const body=JSON.parse(opt.body);requests.push(body);return {ok:true,json:async()=>({ok:true,data:body.action==='assistant_open'?{token:'synthetic-token',claim_number:body.claim_number,profile:'christian',release:'r1'}:{job_id:1,id:1,status:'done',result:{notice:'mock',findings:[],open_points:[]}}})};};
const store={setItem(){},removeItem(){}};
const sandbox={window:win,document:doc,crypto:{randomUUID},AbortController,CustomEvent:Custom,fetch,sessionStorage:store,localStorage:store,structuredClone,console};
vm.createContext(sandbox);vm.runInContext(assistant,sandbox);win.svnetSetupAssistant();
const notes=mountNotes(doc,win);
// Extract function declarations independently, not the whole event setup.
const keepFn=page.slice(page.indexOf('function invalidateOpenedCase()'),page.indexOf("window.addEventListener('svnet:notes-saved'"));
assert(keepFn.includes('function keep(d)'));
// show() extends through local helpers in the minified source: take balanced braces via VM parser boundary known below.
const showEnd=page.indexOf('function fill(',page.indexOf('function show(){'));
const segment=page.slice(page.indexOf('function show(){'),showEnd);
vm.runInContext("let active=null,files=[],caseGeneration=0;const $=id=>document.getElementById(id);const e=x=>String(x??'');function fill(){};function updateMailFields(){};"+keepFn+segment+';this.keep=keep;this.invalidate=invalidateOpenedCase;this.show=show;',sandbox);
const open={_loaded:true,folder_id:'fixture-a',meta:{schaden_nr:'TEST-A-001',claimsforce_claim_id:'11111111-1111-4111-8111-111111111111',claimsforce_profile:'christian',claimsforce_notizen:[{text:'cached synthetic'}]}};
sandbox.keep(open);assert.equal(requests.length,0,'case selection creates no assistant request');await el('vf-assistant-open').onclick();await new Promise(setImmediate);
assert.equal(requests.filter(r=>r.action==='assistant_open').length,1);
assert.equal(win.sent.length,0,'case opening sends no notes bridge request');
assert(notes.state.identity);
sandbox.show();sandbox.keep({...open});await new Promise(setImmediate);
assert.equal(requests.filter(r=>r.action==='assistant_open').length,1,'repaint does not reset assistant');
await el('action').onclick();assert.equal(win.sent.length,0,'assistant action sends no notes bridge request');
sandbox.invalidate();assert.equal(notes.state.identity,null,'case loading clears notes synchronously');
assert(el('action').disabled,'case loading disables assistant');
assert.equal(el('vf-assistant-results').children.length,0);
sandbox.keep({...open,_loaded:false,folder_id:'fixture-b'});await new Promise(setImmediate);
assert.equal(notes.state.identity,null,'unloaded browser data cannot open notes');
assert.equal(requests.filter(r=>r.action==='assistant_open').length,1,'unloaded browser data cannot open assistant');
// Execute the real search-load continuation twice: an older answer cannot replace B.
const begin=page.indexOf('const d=await api(`${API}?action=load_case&id=${encodeURIComponent(r.id)}`),c=d.case||{}');
const end=page.indexOf("b.innerHTML='<p class=\"vf-meta\">Fall wurde geöffnet",begin);
const continuation=page.slice(begin,end);let resolveA;const kept=[];
const load={opening:1,caseGeneration:1,r:{id:'a',name:'synthetic'},API:'/test',api:()=>new Promise(r=>resolveA=r),encodeURIComponent,applyType(){},keep:d=>kept.push(d),Error};
vm.createContext(load);const pending=vm.runInContext('(async()=>{'+continuation+'})()',load);
load.caseGeneration=2;resolveA({case:{id:'a',meta:{schaden_nr:'A'}}});await pending;assert.equal(kept.length,0,'late A load ignored');
load.opening=2;load.r.id='b';load.api=async()=>({case:{id:'a',meta:{}}});await assert.rejects(vm.runInContext('(async()=>{'+continuation+'})()',load),/gültige ID/);
assert(requests.every(r=>r.action.startsWith('assistant_')));
console.log('PASS integrated page + notes + assistant: loaded-only, one context, no automatic notes, clear, delayed load, wrong returned folder');

// A late legacy save may not reopen A while B is active.
const saveCode=page.slice(page.indexOf('async function save(){'),page.indexOf("const ta=$('vf-instructions')",page.indexOf('async function save(){')));
let finishSave;let saved=0;
const legacy={caseGeneration:1,active:{folder_id:'a'},$:el,data:()=>({schaden_nr:'A'}),API:'/test',api:()=>new Promise(r=>finishSave=r),keep:()=>saved++,Error};
vm.createContext(legacy);vm.runInContext(saveCode+';this.save=save;',legacy);
const saving=legacy.save();legacy.caseGeneration=2;legacy.active={folder_id:'b'};finishSave({folder_id:'a'});
await assert.rejects(saving,/CASE_CHANGED/);assert.equal(saved,0);
// File uploads retain the original folder and stop before another file after a switch.
const uploadCode=page.slice(page.indexOf('async function upload(){'),page.indexOf('async function calAvailability()',page.indexOf('async function upload(){')));
const progress={hidden:false,classList:{remove(){},add(){}},querySelector:()=>({style:{},textContent:''})};let finishUpload;const uploads=[];
legacy.caseGeneration=1;legacy.active={folder_id:'a',meta:{}};legacy.files=[{name:'one'},{name:'two'}];legacy.$=()=>progress;
legacy.FormData=class {constructor(){this.fields={};}append(k,v){this.fields[k]=v;}};
legacy.api=async(_url,options)=>{uploads.push(options.body.fields);return new Promise(r=>finishUpload=r);};
vm.runInContext(uploadCode+';this.upload=upload;',legacy);const uploading=legacy.upload();
legacy.caseGeneration=2;legacy.active={folder_id:'b',meta:{}};finishUpload({});await assert.rejects(uploading,/CASE_CHANGED/);
assert.equal(uploads.length,1);assert.equal(uploads[0].folder_id,'a');assert.equal(saved,0);
console.log('PASS actual legacy save/upload: delayed result cannot change current case; next file blocked after switch');
