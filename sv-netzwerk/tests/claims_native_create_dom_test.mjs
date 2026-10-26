import fs from 'node:fs';import vm from 'node:vm';import assert from 'node:assert/strict';
const source=fs.readFileSync(new URL('../browser-extension/claimsforce-bridge/claims-bridge.js',import.meta.url),'utf8');
function fixture({duplicate=false,disabled=false,foreign=false,wrongRoute=false}={}) {
 let clicks=0,opened=false,handler;const item={textContent:'SV Schlusserklärung',getClientRects:()=>[{}],getAttribute:()=>disabled?'true':null,click:()=>clicks++};
 const menu={getClientRects:()=>[{}],getAttribute:()=> 'menu',querySelectorAll:()=>duplicate?[item,item]:[item]};
 const button={textContent:'Hinzufügen',getClientRects:()=>[{}],disabled:false,click:()=>{opened=true},getAttribute:k=>k==='aria-expanded'?(opened?'true':'false'):'form-menu'};
 const heading={textContent:'Formulare',getClientRects:()=>[{}],parentElement:{querySelectorAll:()=>[button]}};
 const document={querySelectorAll:()=>[heading],getElementById:()=>menu};
 vm.runInNewContext(source,{window:{addEventListener(){}},document,location:{pathname:wrongRoute?'/wrong':'/claims/case/investigations'},chrome:{runtime:{id:'extension',onMessage:{addListener:h=>handler=h}}},Map,Set,Date,setTimeout});
 return{clicks:()=>clicks,send:message=>new Promise(resolve=>handler({caseId:'case',...message},{id:foreign?'other':'extension'},resolve))};
}
let count=0;async function test(name,fn){await fn();count++;console.log('ok',name)}
await test('listing observed templates never creates',async()=>{const s=fixture(),r=await s.send({type:'NATIVE_FORM_TEMPLATES'});assert.equal(r.ok,true);assert.deepEqual(Array.from(r.result.templates),['SV Schlusserklärung']);assert.equal(s.clicks(),0)});
await test('exact original template is clicked once',async()=>{const s=fixture(),r=await s.send({type:'NATIVE_FORM_CREATE',templateName:'SV Schlusserklärung'});assert.equal(r.result.acknowledged,true);assert.equal(r.result.verified,false);assert.equal(s.clicks(),1)});
for(const options of [{duplicate:true},{disabled:true},{foreign:true},{wrongRoute:true}])await test('reject unsafe DOM '+JSON.stringify(options),async()=>{const s=fixture(options);assert.equal((await s.send({type:'NATIVE_FORM_CREATE',templateName:'SV Schlusserklärung'})).ok,false);assert.equal(s.clicks(),0)});
await test('unknown template never falls back to another',async()=>{const s=fixture();assert.equal((await s.send({type:'NATIVE_FORM_CREATE',templateName:'Unknown'})).ok,false);assert.equal(s.clicks(),0)});
console.log(`${count} DOM contract tests passed; synthetic DOM.`);
