import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const source=fs.readFileSync(new URL('../src/pages/intern/versicherungsfaelle/index.astro',import.meta.url),'utf8');
const scripts=[...source.matchAll(/<script is:inline>([\s\S]*?)<\/script>/g)].map(m=>m[1]).filter(s=>s.trim().startsWith("(()=>{const button=document.getElementById('vf-rekon-import')"));
assert.equal(scripts.length,2);
async function scenario(message,profile='marc'){
 const listeners=[],clicks=[],sent=[],nodes={};
 for(const id of ['vf-rekon-import','vf-rekon-state','vf-rekon-progress','vf-rekon-target'])nodes[id]={dataset:{},style:{},classList:{add(){},remove(){}},querySelector(){return this},addEventListener(type,fn,capture){clicks.push({fn,capture})}};
 const window={svnetDriveStatus:()=>Promise.resolve({claims_profile:profile,folders:{cases:{name:'Schadenfälle Marc Schütt'}}}),addEventListener(type,fn){listeners.push(fn)},postMessage(data){sent.push(data)},dispatchEvent(){}};
 const context=vm.createContext({window,document:{getElementById:id=>nodes[id]},location:{origin:'https://www.sv-netzwerk.eu'},setTimeout(){},CustomEvent:class{},console});
 for(const script of scripts)vm.runInContext(script,context);
 await new Promise(resolve=>setImmediate(resolve));
 for(const fn of listeners)fn({source:window,origin:context.location.origin,data:message});
 let stopped=false;
 for(const click of clicks.sort((a,b)=>Number(!!b.capture)-Number(!!a.capture))){if(stopped)break;click.fn({preventDefault(){},stopImmediatePropagation(){stopped=true}})}
 return {sent,nodes};
}
const valid={type:'SVNET_CLAIMS_BRIDGE_READY_V2',version:'1.4.62',workerVersion:'1.4.62',protocol:2};
const good=await scenario(valid);
assert(good.sent.some(x=>x.type==='SVNET_CLAIMS_BRIDGE_PING'));
assert(good.sent.some(x=>x.type==='SVNET_REKON_IMPORT_START'&&x.profile==='marc'));
for(const bad of [{...valid,type:'SVNET_CLAIMS_BRIDGE_READY'},{...valid,workerVersion:'1.4.61'},{...valid,protocol:1}])assert(!(await scenario(bad)).sent.some(x=>x.type==='SVNET_REKON_IMPORT_START'));
assert(!(await scenario(valid,'christian')).sent.some(x=>x.type==='SVNET_REKON_IMPORT_START'));
console.log('Rekon: Marc startet mit echter V2-Bereitschaft; alte Signale, falscher Worker, falsches Protokoll und fremdes Profil bleiben gesperrt.');

