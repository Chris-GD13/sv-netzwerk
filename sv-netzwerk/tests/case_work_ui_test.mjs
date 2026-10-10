import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {randomUUID} from 'node:crypto';
class Element {
  constructor(){this.children=[];this.style={};this.value='';this.files=[];this.disabled=false;this.checked=true;this.dataset={};this.classList={remove(){}};}
  append(...children){this.children.push(...children);}
  replaceChildren(...children){this.children=children;}
  addEventListener(){} querySelector(){return null;} closest(){return null;} focus(){} scrollIntoView(){}
}
const ids=Object.fromEntries(['vf-analysis-start','vf-analysis-refresh','vf-analysis-state','vf-work-history','vf-analysis-order','vf-analysis-files','vf-work-reload','vf-work-import-retry','vf-work-local','vf-active'].map(id=>[id,new Element()]));
const store=new Map(),observers=[],requests=[];let importState='queued';const histories=new Map();
store.set('svnet-case',JSON.stringify({folder_id:'case-a',meta:{schaden_nr:'26-123456-1'}}));
const storage={getItem:k=>store.get(k)||null,setItem:(k,v)=>store.set(k,v),removeItem:k=>store.delete(k)};
const context={document:{readyState:'complete',getElementById:id=>ids[id],createElement:()=>new Element(),querySelectorAll:()=>[]},
  sessionStorage:storage,localStorage:{getItem:()=> 'user-scope'},location:{search:''},URLSearchParams,AbortController,FormData,
  crypto:{randomUUID},navigator:{clipboard:{writeText:async()=>{}}},confirm:()=>true,
  setTimeout:()=>1,clearTimeout:()=>{},MutationObserver:class{constructor(fn){observers.push(fn);}observe(){}disconnect(){}},
  window:{addEventListener(){},svnetClaimsReloadCase:async number=>{assert.equal(number,'26-123456-1');return{id:11};}},
  fetch:async(url,options)=>{
    const body=options.body?JSON.parse(options.body):{};requests.push({url,body});let data;
    if(url.includes('claimsforce-queue'))data={ok:true,job:{id:11,status:importState,message:'Importstatus'}};
    else if(body.action==='start'){
      const rows=histories.get(body.folder_id)||[];
      if(!rows.some(r=>r.request_key===body.request_key))rows.push({id:rows.length+1,request_key:body.request_key,instructions:body.instructions,created_at:'2026-10-10',status:'done',result:{summary:'Ergebnis',reply_draft:'Vollständiger Entwurf',document_requests:[]}});
      histories.set(body.folder_id,rows);data={ok:true,job_id:1};
    }else data={ok:true,turns:histories.get(body.folder_id)||[]};
    return{ok:true,json:async()=>data};
  }};
vm.runInNewContext(fs.readFileSync(new URL('../public/intern/case-work.js',import.meta.url),'utf8'),context);
const flush=()=>new Promise(resolve=>setImmediate(resolve));await flush();
assert.equal(ids['vf-analysis-start'].disabled,false);
ids['vf-analysis-order'].value='Bitte abarbeiten';await ids['vf-analysis-start'].onclick();
assert.equal(requests.filter(r=>r.body.action==='start').length,0,'Analysis must wait for import');
assert.equal(ids['vf-analysis-start'].disabled,true);
importState='done';ids['vf-analysis-refresh'].onclick();await flush();await flush();
assert.equal(requests.filter(r=>r.body.action==='start').length,1);
assert.equal(requests.find(r=>r.body.action==='start').body.import_id,11);
assert.equal(histories.get('case-a').length,1);
ids['vf-analysis-order'].value='Korrektur: Strompreis 0,40 EUR';await ids['vf-analysis-start'].onclick();
assert.equal(histories.get('case-a').length,2);
assert.equal(requests.filter(r=>r.body.action==='start').at(-1).body.instructions,'Korrektur: Strompreis 0,40 EUR');
store.set('svnet-case',JSON.stringify({folder_id:'case-b',meta:{schaden_nr:'26-999999-1'}}));observers[0]();await flush();
assert.equal(ids['vf-work-history'].children.length,0,'Previous case response leaked into new case');
assert.equal(ids['vf-analysis-order'].value,'');
assert.equal(requests.filter(r=>r.body.action==='start'&&r.body.folder_id==='case-b').length,0);
assert.equal(requests.some(r=>/send|complete/.test(r.url)),false,'No external mail or completion action');
console.log('case_work_ui_test: import gate, follow-up, persistence and case isolation passed');
