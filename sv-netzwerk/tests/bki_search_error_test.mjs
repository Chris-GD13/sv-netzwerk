import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const page = fs.readFileSync(new URL('../src/pages/intern/kalkulation/index.astro', import.meta.url), 'utf8');
const handler = page.split('\n').find(line => line.includes("$('bk-kva-calc').onclick="));
const existingLines = [{ description: 'Existing offer', unit_price: 495 }];
let calls = 0;
const controls = {
  'bk-kva-calc': {disabled:false}, 'bk-location':{value:'Kuchen'}, 'bk-level':{value:'mid'},
};
const state = {textContent:''};
const context = {
  $: id => controls[id], calcState:state,
  box:{querySelectorAll:()=>[{dataset:{use:'0'}},{dataset:{use:'1'}}], querySelector:selector=>({value:selector.includes('desc')?'Gussfallleitung':selector.includes('qty')?'12':'m'})},
  positions:[{},{}], number:Number, active:()=>({meta:{}}),
  request:async()=>{calls++;throw Error('Files were not found');},
  window:{__bkiCalcBridge:{addSection:()=>existingLines.push({type:'section'}),addLine:line=>existingLines.push(line)}}
};
vm.createContext(context);
vm.runInContext(handler,context);
await controls['bk-kva-calc'].onclick();
assert.equal(calls,1,'A broken search must stop without retrying every position.');
assert.deepEqual(existingLines,[{description:'Existing offer',unit_price:495}]);
assert.match(state.textContent,/unterbrochen: Files were not found/);
assert.equal(controls['bk-kva-calc'].disabled,false);
console.log('BKI search error preservation: OK');
