import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {randomUUID} from 'node:crypto';
class Element {
  constructor(){this.children=[];this.value='';this.textContent='';this.disabled=false;this.dataset={};}
  replaceChildren(...c){this.children=c;} append(...c){this.children.push(...c);}
}
const names=['vf-assistant','vf-assistant-sync','vf-assistant-demo','vf-assistant-open','vf-assistant-connections','vf-assistant-connections-state','vf-assistant-state','vf-assistant-results','vf-assistant-order','vf-assistant-context','vf-assistant-budget','vf-assistant-refresh','vf-assistant-resume','vf-assistant-history','vf-assistant-cancel','vf-assistant-route'];
const ids=Object.fromEntries(names.map(n=>[n,new Element()]));ids['vf-assistant-route'].value='assistant';
const buttons=['check','invoices','report','tasks','custom'].map(task=>Object.assign(new Element(),{dataset:{assistantTask:task}}));
const handlers={},requests=[],opens=[];let delayedStep=null,delay=false,invalidStatus=false;
const response=data=>({ok:true,json:async()=>({ok:true,data})});
const window={addEventListener:(event,fn)=>{handlers[event]=fn;}};
const context={window,document:{getElementById:n=>ids[n],querySelectorAll:selector=>selector==='[data-assistant-task]'?buttons:[],createElement:()=>new Element()},crypto:{randomUUID},AbortController,navigator:{clipboard:{writeText:async()=>{}}},
  setTimeout:()=>{throw Error('Unexpected polling');},
  fetch:async(url,opt)=>{const body=JSON.parse(opt.body);requests.push({url,body});
    if(body.action==='assistant_open')return new Promise(resolve=>opens.push({body,resolve}));
    if(body.action==='assistant_start')return response({job_id:7});
    if(body.action==='assistant_close')return response({closed:true});
    if(body.action==='assistant_status'&&invalidStatus)return {ok:false,json:async()=>({ok:false,error:'CASE_CHANGED'})};
    if(body.action==='assistant_step'){
      if(delay)return new Promise(resolve=>{delayedStep=resolve;});
      return response({id:7,status:'done',result:{action:'check',notice:'CASE-B ONLY',findings:[],open_points:[],total_cents:1500}});
    }
    return response({id:7,status:'done'});
  }
};
Object.defineProperty(context,'sessionStorage',{get(){throw Error('Storage used as identity');}});
Object.defineProperty(context,'localStorage',{get(){throw Error('Storage used as identity');}});
vm.runInNewContext(fs.readFileSync(new URL('../public/intern/case-work-assistant.js',import.meta.url),'utf8'),context);
window.svnetSetupAssistant();assert.equal(requests.length,0,'startup must not query');assert(buttons.every(b=>b.disabled));
const open=(folder,number)=>{handlers['svnet:case-opened']({detail:{_loaded:true,folder_id:folder,meta:{schaden_nr:number}}});return ids['vf-assistant-open'].onclick();};
const a=open('a','A');const b=open('b','B');
opens[1].resolve(response({token:'token-b',claim_number:'B',profile:'profile-b',release:'r1'}));await b;
opens[0].resolve(response({token:'token-a',claim_number:'A',profile:'profile-a',release:'r1'}));await a;
assert.match(ids['vf-assistant-context'].textContent,/Schaden B/);assert(!buttons[0].disabled);
await buttons[0].onclick();assert.equal(requests.find(r=>r.body.action==='assistant_start').body.token,'token-b');
assert.match(ids['vf-assistant-results'].children[0].textContent,/CASE-B ONLY/);
invalidStatus=true;await ids['vf-assistant-refresh'].onclick();
assert.equal(ids['vf-assistant-results'].children.length,0,'changed source must clear stale result');assert(buttons.every(b=>b.disabled));
invalidStatus=false;const reopened=open('b','B');opens[2].resolve(response({token:'token-b2',claim_number:'B',profile:'profile-b',release:'r1'}));await reopened;
delay=true;const pending=buttons[2].onclick();await new Promise(setImmediate);
handlers['svnet:case-cleared']();delayedStep(response({id:7,status:'done',result:{draft:'STALE B REPORT'}}));await pending;
assert.equal(ids['vf-assistant-results'].children.length,0,'late result must not appear');assert(buttons.every(b=>b.disabled));
assert(requests.some(r=>r.body.action==='assistant_close'),'closing revokes server context');
assert(requests.every(r=>r.url==='/intern/api/case-work.php?assistant=1'),'no notes, imports, model, mail or alternate endpoint');
assert(!requests.some(r=>r.body.action==='assistant_history'),'history only after click');
// Phone workflow: the user taps the task directly, without a separate bind button.
delay=false;let before=requests.length;
handlers['svnet:case-opened']({detail:{_loaded:true,folder_id:'mobile-c',meta:{schaden_nr:'TEST-C'}}});
assert(buttons.every(b=>!b.disabled),'Opened case must offer tasks immediately');
assert.equal(requests.length,before,'Offering tasks must not make any background request');
ids['vf-assistant-order'].value='Synthetic mobile instruction';
const direct=buttons[4].onclick();
assert.equal(requests.at(-1).body.action,'assistant_open','Server binding must precede the task');
opens.at(-1).resolve(response({token:'token-c',claim_number:'TEST-C',profile:'profile-c',release:'r1'}));await direct;
const started=requests.filter(r=>r.body.action==='assistant_start').at(-1).body;
assert.equal(started.token,'token-c');assert.equal(started.instruction,'Synthetic mobile instruction');
assert.equal(started.task,'custom');
// A case switch while server binding is pending must never start the old task.
handlers['svnet:case-opened']({detail:{_loaded:true,folder_id:'mobile-d',meta:{schaden_nr:'TEST-D'}}});
const stale=buttons[0].onclick(),staleOpen=opens.at(-1);before=requests.filter(r=>r.body.action==='assistant_start').length;
handlers['svnet:case-opened']({detail:{_loaded:true,folder_id:'mobile-e',meta:{schaden_nr:'TEST-E'}}});
staleOpen.resolve(response({token:'token-d',claim_number:'TEST-D',profile:'profile-d',release:'r1'}));await stale;
assert.equal(requests.filter(r=>r.body.action==='assistant_start').length,before);
assert.match(ids['vf-assistant-context'].textContent,/TEST-E/);
// Explicit denial at the fresh bind cannot reach the paid start at all.
const denied=buttons[0].onclick();
opens.at(-1).resolve({ok:false,status:403,json:async()=>({ok:false,error:'CASE_FORBIDDEN'})});await denied;
assert.equal(requests.filter(r=>r.body.action==='assistant_start').length,before);assert(buttons.every(b=>b.disabled));
console.log('assistant-ui-test: startup/no-polling, no storage identity, stale open, late result, server close, original endpoint passed');
