import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
class Element {
 children=[];controls={};textContent='';disabled=false;checked=false;value='';events={};
 appendChild(e){this.children.push(e)} replaceChildren(){this.children=[]}
 set innerHTML(html){this.html=html;this.controls={};for(const key of ['quantity','scope','adopt','message','status']){const e=new Element();if(key==='quantity')e.value=(html.match(/data-quantity[^>]*value="([^"]*)"/)||[])[1]||'';if(key==='adopt')e.disabled=true;this.controls[key]=e}}
 get innerHTML(){return this.html||''}
 querySelector(selector){return this.controls[selector.replace('[data-','').replace(']','')]}
 addEventListener(name,fn){this.events[name]=fn}
}
const context={window:{},document:{createElement:()=>new Element()}};
vm.createContext(context);
vm.runInContext(fs.readFileSync(new URL('../public/intern/bki-kva-review.js',import.meta.url),'utf8'),context);
const {prepare,suggestedQuantity}=context.window.SVNetBkiReview;
assert.equal(suggestedQuantity({unit:'m',quantity:12},{unit:'m²'}),'','Length must not become area.');
assert.equal(suggestedQuantity({unit:'Pausch.',quantity:1},{unit:'t'}),'','A lump sum must not become a tonne.');
assert.equal(suggestedQuantity({unit:'m',quantity:12},{unit:'m Rohrleitung'}),12);
assert.equal(suggestedQuantity({unit:'Stk.',quantity:3},{unit:'St'}),3);
const lines=[{description:'Existing offer',unit_price:495}];
const bridge={addSection:line=>lines.push(line),addLine:line=>lines.push(line)};
let calls=0;
const state=new Element(),host=new Element();
const config={rows:[{description:'Gussfallleitung',unit:'m',quantity:12},{description:'Reinigung',unit:'Pausch.',quantity:1}],bridge,state,host,level:'mid',group:{description:'KVA',kva_net_total:11440},request:async()=>{calls++;throw Error('Files were not found')}};
await prepare(config);
assert.equal(calls,1);
assert.deepEqual(lines,[{description:'Existing offer',unit_price:495}]);
assert.match(state.textContent,/unterbrochen: Files were not found/);
const candidate={position_code:'344.1',description:'Gussrohr demontieren',unit:'t',price_mid:374,source_name:'BKI Altbau 2026',source_page:'529'};
await prepare({...config,rows:[{description:'Abfall und Reinigung',quantity:1,unit:'Pausch.'}],request:async()=>({positions:[candidate]})});
assert.equal(lines.length,1,'Search results must never be automatically priced into the calculation.');
const card=host.children[0].children[0],qty=card.querySelector('[data-quantity]'),scope=card.querySelector('[data-scope]'),adopt=card.querySelector('[data-adopt]');
assert.equal(qty.value,'');scope.checked=true;scope.events.change();assert.equal(adopt.disabled,true);
qty.value='0.4';qty.events.input();assert.equal(adopt.disabled,false);adopt.events.click();
assert.equal(lines[2].quantity,0.4);assert.equal(lines[2].description,candidate.description);
assert.equal(lines[2].source_page,'529');assert.equal(lines[1].kva_net_total,11440);
await prepare({...config,rows:[{description:'Prüfung',quantity:1,unit:'psch'}],request:async()=>({positions:[{...candidate,source_page:''}]})});
const missing=host.children[0].children[0];missing.querySelector('[data-quantity]').value='1';missing.querySelector('[data-scope]').checked=true;missing.querySelector('[data-scope]').events.change();
assert.equal(missing.querySelector('[data-adopt]').disabled,true);
console.log('BKI review: units, scope, sources, no automatic pricing and error preservation OK');