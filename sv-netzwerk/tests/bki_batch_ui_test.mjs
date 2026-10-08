import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const context={window:{},Intl};vm.createContext(context);vm.runInContext(fs.readFileSync(new URL('../public/intern/bki-kva-batch.js',import.meta.url),'utf8'),context);
const batch=context.window.SVNetBkiBatch;
const group={description:'BAU-EXPERT · KVA 0030/2026',kva_number:'0030/2026',kva_company:'BAU-EXPERT'};
const previous=[{description:'Freie Eigenposition',quantity:1,unit_price:50},{type:'section',...group},{description:'Falscher alter Meter-/Flächenansatz'},{type:'section',description:'BKI-Prüfansätze · '+group.description},{description:'Doppelter Ansatz'},{type:'section',description:'Anderer KVA'},{description:'Andere Leistung'}];
const row={source_position:'3',description:'Öffnen',quantity:12,unit:'m',offered_unit_price:165,offered_total:1980};
const preserved=batch.sourceLines(row,{status:'open',reason:'Fläche fehlt'});
assert.equal(preserved[0].quantity,12);assert.equal(preserved[0].unit,'m');assert.equal(preserved[0].unit_price,165);assert.equal(preserved[0].comparison_basis,'kva_open');
const merged=batch.replaceGroup(previous,group,preserved);
assert.equal(merged.length,5);assert.equal(merged[0].description,'Freie Eigenposition');assert.equal(merged[1].description,'Anderer KVA');assert.equal(merged[2].description,'Andere Leistung');assert.equal(merged[4].quantity*merged[4].unit_price,1980);
assert.equal(previous.length,7);assert(!merged.some(x=>/Falscher|Doppelter/.test(x.description)));
const repeat=batch.replaceGroup(merged,group,preserved);assert.equal(repeat.length,merged.length);
console.log('BKI batch draft retention and group replacement tests passed');

const variantGroup={...group,kva_company:'BAU | EXPERT',description:'BAU | EXPERT · KVA 0030/2026'};
assert.equal(batch.replaceGroup(previous,variantGroup,preserved).filter(x=>x.type==='section'&&x.kva_number==='0030/2026').length,1);

const partial=batch.sourceLines(row,{status:'partial',calculated_net:300,priced_components:[{quantity:12,unit:'m',unit_price:25}],reason:'Restleistung offen'});
assert.equal(partial.length,1);assert.equal(partial[0].quantity,12);assert.equal(partial[0].unit,'m');assert.equal(partial[0].unit_price,165);assert.equal(partial[0].comparison_basis,'kva_open');
const complete=batch.sourceLines(row,{status:'ready',calculated_net:1800,priced_components:[{quantity:12,unit:'m',unit_price:150}]});
assert.equal(complete.length,1);assert.equal(complete[0].quantity,12);assert.equal(complete[0].unit,'m');assert.equal(complete[0].unit_price,150);
console.log('Incomplete catalog prices cannot reduce the offered service; original quantities preserved');
