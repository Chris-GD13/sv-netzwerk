import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const dir=new URL('../browser-extension/claimsforce-bridge/',import.meta.url);
let listener;let id=10;const storage={};const tabs=[];const returned=[];
const chrome={runtime:{onMessage:{addListener:f=>listener=f}},storage:{session:{get:async k=>k?{[k]:storage[k]}:{...storage},set:async v=>Object.assign(storage,v),remove:async k=>delete storage[k]}},tabs:{create:async x=>{tabs.push(x);return {id:++id}},update:async (id,x)=>tabs.push({id,...x}),sendMessage:async(id,x)=>{returned.push({id,...x});return {ok:true}},onRemoved:{addListener(){}}}};
vm.runInNewContext(readFileSync(new URL('finnya-worker.mjs',dir),'utf8'),{chrome,URL,Date,Error});
const call=(message,sender)=>new Promise(resolve=>listener(message,sender,resolve));
const portal={url:'https://www.sv-netzwerk.eu/intern/privatauftraege/angebot/',frameId:0,tab:{id:5}};
const job={recordId:'a'.repeat(36),customerKey:'b'.repeat(64),data:{company:'Test GmbH'}};
assert.equal((await call({type:'FINNYA_OPEN',job},{...portal,url:'https://evil.example/'})).ok,false);
assert.equal((await call({type:'FINNYA_OPEN',job},{...portal,frameId:1})).ok,false);
assert.equal((await call({type:'FINNYA_OPEN',job},portal)).ok,true);
assert.equal((await call({type:'FINNYA_OPEN',job},portal)).ok,false,'double clicks do not create two tasks');
assert.equal(tabs.length,2);
const finn={url:'https://factoringplus.dv-portal.de/debtors/test',frameId:0,tab:{id:11}};
assert.equal((await call({type:'FINNYA_JOB'},finn)).job.recordId,job.recordId);
assert.equal((await call({type:'FINNYA_JOB'},{...finn,tab:{id:12}})).ok,false,'unbound tab cannot read customer data');
assert.equal((await call({type:'FINNYA_RESULT',result:{source_url:'https://evil.example/'}},finn)).ok,false);
assert.equal((await call({type:'FINNYA_RESULT',result:{source_url:finn.url,status:'confirmed'}},finn)).ok,true);
assert.equal(returned[0].id,5);assert.equal(Object.keys(storage).length,0);

// Execute the real content script against synthetic vendor dialogs. No live customer is created.
async function pageFixture(existing){
 let phase='form',created=0,result;
 const data={customer_type:'business',company:'Test GmbH',first_name:'Max',last_name:'Test',billing_street:'Testweg 1',billing_postcode:'12345',billing_city:'Testort',phone:'',email:''};
 class Input{constructor(){this.raw='';this.checked=true;}get value(){return this.raw;}set value(v){this.raw=v;}getClientRects(){return [1]}dispatchEvent(){}blur(){}}
 const fields=Object.fromEntries(['company','contactPerson','streetAndHouse','zipCode','city','phone','email','radio'].map(k=>[k,new Input()]));
 const location={origin:'https://factoringplus.dv-portal.de',href:'https://factoringplus.dv-portal.de/debtors?createDebtor=true',pathname:'/debtors'};
 const button=(text,click)=>({innerText:text,disabled:false,getClientRects:()=>[1],click});
 const identity='Test GmbH Testweg 1 12345 Testort';
 const dialog={getClientRects:()=>[1],get innerText(){return phase==='form'?'Kunde anlegen & Limit prüfen':(phase==='found'?(existing?'Bestehenden Kunden gefunden ':'Kunde erfolgreich angelegt ')+identity+' Limit: 25.000,00 € (22.000,00 €)':identity);},querySelectorAll(selector){if(selector==='label')return [['Firma','radio'],['Telefonnummer (optional)','phone'],['E-Mail (optional)','email']].map(([innerText,htmlFor])=>({innerText,htmlFor}));if(selector==='button')return phase==='form'?[button('Jetzt Limit prüfen',()=>{phase=existing?'found':'create'})]:phase==='create'?[button('Kunden anlegen',()=>{created++;phase='found'})]:[button('Zum Kunden',()=>{location.pathname='/debtors/customer';location.href='https://factoringplus.dv-portal.de/debtors/customer'})];return []}};
 const document={body:{innerText:identity,append(){}},createElement:()=>({style:{}}),getElementById:id=>fields[id],querySelectorAll:()=>[dialog]};
 const chrome={runtime:{sendMessage:async m=>m.type==='FINNYA_JOB'?{ok:true,job:{data}}:(result=m.result,{ok:true})}};
 vm.runInNewContext(readFileSync(new URL('finnya-page.js',dir),'utf8'),{chrome,location,document,HTMLInputElement:Input,Event:class{},Date,setTimeout:(fn)=>setImmediate(fn),Promise,Error});
 for(let n=0;n<40&&!result;n++)await new Promise(setImmediate);
 assert.equal(result?.status,'confirmed');assert.equal(result.limit_cents,2500000);assert.equal(result.available_cents,2200000);assert.equal(created,existing?0:1);assert.equal(fields.company.value,data.company);
}
await pageFixture(true);await pageFixture(false);
console.log('finnya bridge: ok (sender and tab isolation, duplicate task guard, reply acknowledgement, synthetic existing/new customer flows)');
