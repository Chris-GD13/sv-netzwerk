import vm from 'node:vm';
import fs from 'node:fs';
import assert from 'node:assert/strict';
const source=fs.readFileSync(new URL('../browser-extension/claimsforce-bridge/portal-bridge.js',import.meta.url),'utf8');
const start=source.indexOf('function reportRuntime()'),end=source.indexOf("\nwindow.addEventListener('message'",start);
assert.ok(start>=0&&end>start);
for(const mode of ['sync','async','ok']){
 let invalid=0;const attributes={},messages=[];
 const context={Promise,chrome:{runtime:{sendMessage(){if(mode==='sync')throw Error('Extension context invalidated.');if(mode==='async')return Promise.reject(Error('Extension context invalidated.'));return Promise.resolve({workerVersion:'test',protocol:2});}}},
 document:{documentElement:{setAttribute:(k,v)=>attributes[k]=v}},window:{postMessage:m=>messages.push(m)},location:{origin:'https://www.sv-netzwerk.eu'},BRIDGE_VERSION:'test',PORTAL_CODE_VERSION:'test',
 invalidExtensionContext:e=>/Extension context invalidated/.test(e.message),reportInvalidExtensionContext:()=>invalid++};
 vm.createContext(context);vm.runInContext(source.slice(start,end),context);await context.reportRuntime();
 assert.equal(invalid,mode==='ok'?0:1);
 assert.equal(messages.some(m=>m.type==='SVNET_CLAIMS_BRIDGE_READY_V2'),mode==='ok');
}
console.log('3 runtime reload tests passed: synchronous invalidation, async invalidation, live response.');
