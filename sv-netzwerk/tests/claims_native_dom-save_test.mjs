import vm from 'node:vm';
import fs from 'node:fs';
import assert from 'node:assert/strict';
import {webcrypto} from 'node:crypto';
const source=fs.readFileSync(new URL('../browser-extension/claimsforce-bridge/native-form-dom.js',import.meta.url),'utf8');
function fixture(){
 const events=[];let dirty=false;
 class Input{constructor(id,value){this.id=id;this._value=value;this.tagName='INPUT';this.disabled=false;this.readOnly=false;this.ownerDocument={defaultView:{HTMLInputElement:Input,Event:class{constructor(type){this.type=type}}}}}get value(){return this._value}set value(v){this._value=v}getClientRects(){return[{}]}closest(){return{id:this.id}}focus(){}blur(){}dispatchEvent(e){events.push(e.type);dirty=true}}
 const controls=[new Input('insurerClaimId','SYNTHETIC-001'),new Input('Hinweis1','Old'),new Input('Endergebnis','100,00 €')];
 const expected={caseId:'case',formId:'form',claimNumber:'SYNTHETIC-001',profile:'christian'};
 const path='/claims/case/forms/form';
 const frame={src:'https://web.claimsforce.com/apryse/11.11.0/ui/index.html#basePath='+encodeURIComponent(path),contentDocument:{querySelectorAll:()=>controls}};
 const button={textContent:'Entwurf speichern',getClientRects:()=>[{}],get disabled(){return!dirty},click(){dirty=false;events.push('save')}};
 const window={};const document={querySelectorAll:s=>s==='iframe'?[frame]:s==='button'?[button]:[]};
 const location={href:'https://web.claimsforce.com'+path,origin:'https://web.claimsforce.com',pathname:path};
 vm.runInNewContext(source,{window,document,location,URL,URLSearchParams,crypto:webcrypto,TextEncoder,setTimeout,Date});
 return{api:window.SVNET_NATIVE_FORM_DOM,expected,controls,events,location,button,setDirty:v=>dirty=v};
}
let n=0;async function test(name,fn){await fn();n++;console.log('ok',name)}
await test('reads real wrapper IDs, blocks calculated total',async()=>{const s=fixture(),r=await s.api.snapshot(s.expected);assert.equal(r.fields.find(f=>f.id==='Hinweis1').writable,true);assert.equal(r.fields.find(f=>f.id==='Endergebnis').writable,false);assert.equal(r.hash.length,64)});
await test('native setter dispatches change and enables save',async()=>{const s=fixture(),r=await s.api.snapshot(s.expected);await s.api.apply({...s.expected,expectedHash:r.hash,changes:[{field:'Hinweis1',before:'Old',after:'New'}]});assert.equal(s.controls[1].value,'New');assert.deepEqual(s.events,['input','change']);const ack=await s.api.save(s.expected);assert.equal(ack.acknowledged,true);assert.equal(ack.verified,false);assert.equal((await s.api.snapshot(s.expected)).dirty,false)});
await test('whole patch checked before any mutation',async()=>{const s=fixture(),r=await s.api.snapshot(s.expected);await assert.rejects(s.api.apply({...s.expected,expectedHash:r.hash,changes:[{field:'Hinweis1',before:'Old',after:'New'},{field:'Endergebnis',before:'100,00 €',after:'200,00 €'}]}),/PATCH_REJECTED/);assert.equal(s.controls[1].value,'Old');assert.deepEqual(s.events,[])});
await test('wrong native claim rejected',async()=>{const s=fixture();await assert.rejects(s.api.snapshot({...s.expected,claimNumber:'OTHER'}),/CLAIM_NUMBER_MISMATCH/)});
await test('route changed rejected',async()=>{const s=fixture();s.location.pathname='/claims/other/forms/form';await assert.rejects(s.api.snapshot(s.expected),/ROUTE_MISMATCH/)});
await test('unsaved user edit rejected',async()=>{const s=fixture(),r=await s.api.snapshot(s.expected);s.setDirty(true);await assert.rejects(s.api.apply({...s.expected,expectedHash:r.hash,changes:[{field:'Hinweis1',before:'Old',after:'New'}]}),/STALE_OR_DIRTY/)});
await test('mirrored divergent fields rejected',async()=>{const s=fixture();s.controls.push(Object.assign(Object.create(Object.getPrototypeOf(s.controls[1])),s.controls[1],{_value:'Other'}));await assert.rejects(s.api.snapshot(s.expected),/MIRROR_VALUES_DIFFER/)});
console.log(`${n} DOM adapter tests passed; synthetic DOM, not installed browser transport.`);
