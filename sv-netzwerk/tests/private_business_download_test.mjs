import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const source=fs.readFileSync(new URL('../public/intern/private-business.js',import.meta.url),'utf8');
const start=source.indexOf('async function openPdf(print=false)'),end=source.indexOf("$('pb-pdf').onclick",start);
const elements={};const element=id=>elements[id]??={hidden:false,textContent:'',removeAttribute(){},showModal(){this.open=true;}};
let resolveSave;const waiting=new Promise(resolve=>resolveSave=resolve);const assigned=[];
const ctx={$:element,save:()=>waiting,encodeURIComponent,location:{assign:url=>assigned.push(url)}};vm.createContext(ctx);vm.runInContext(source.slice(start,end),ctx);
const action=ctx.openPdf(false);assert.equal(element('pb-preview').open,true);assert.match(element('pb-preview-state').textContent,/vorbereitet/);
resolveSave({id:'one',number:'26-12345',revision:2});await action;
assert.match(element('pb-pdf-frame').src,/action=file.*revision=2#toolbar=1/);assert.match(assigned[0],/download=1/);assert.equal(element('pb-download-link').hidden,false);assert.match(element('pb-download-link').textContent,/26-12345/);
ctx.save=async()=>{throw Error('Test: Zugriff begrenzt');};await assert.rejects(ctx.openPdf(false));assert.match(element('pb-preview-state').textContent,/Test: Zugriff begrenzt/);
assert.doesNotMatch(source.slice(start,end),/createObjectURL|response\.blob/);
console.log('private_business_download_test: ok (immediate feedback, saved PDF preview, native download URL, visible errors)');
const bodyStart=source.indexOf('function mailBody(r)'),bodyEnd=source.indexOf("$('pb-mail').onclick",bodyStart);
const mailContext={};vm.createContext(mailContext);vm.runInContext(source.slice(bodyStart,bodyEnd),mailContext);
for(const kind of ['offer','order']){
 const consumer=mailContext.mailBody({kind,data:{customer_type:'consumer'}});
 assert.match(consumer,/unterschrieben zurück/);assert.match(consumer,/gewünschten Erklärungen/);assert.match(consumer,/beiden Erklärungen zum vorzeitigen Leistungsbeginn/);assert.match(consumer,/freiwillig/);assert.match(consumer,/nur im Fall eines Widerrufs/);assert.doesNotMatch(consumer,/alle Kästchen|Verzicht auf/);
 const business=mailContext.mailBody({kind,data:{customer_type:'business'}});assert.match(business,/unterschrieben zurück/);assert.doesNotMatch(business,/vor Ablauf der Widerrufsfrist|beiden Erklärungen/);
}
console.log('private_business_mail_text: ok (signed return, requested checkboxes, voluntary early start for consumers, no blanket waiver)');
