import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const page = fs.readFileSync(new URL('../src/pages/intern/privatauftraege/index.astro', import.meta.url), 'utf8');
const functions = ['openFolder', 'sendFiles'].map(name => page.match(new RegExp(`async function ${name}\\([^\\n]+`))?.[0]).join('\n');
function setup() {
  const calls = [];
  const ctx = {active:{id:'case-a'},activeFolder:{id:'folder-a',name:'Fotos'},breadcrumbs:[{id:'case-a'},{id:'folder-a'}],openEpoch:1,state:{textContent:''},upload:{disabled:false},input:{value:'selected'},renderFolders(){},renderFiles(){},encodeURIComponent,Error,FormData:class {values={};append(k,v){this.values[k]=v;}},request:async(url,options)=>{calls.push({url,options});return url.includes('upload')?{ok:true,item:{id:'saved'}}:{ok:true,item:{id:'folder-a',name:'Fotos',children:[]}};}};
  vm.createContext(ctx);vm.runInContext(functions,ctx);return {ctx,calls};
}
{
  const {ctx,calls}=setup();await ctx.openFolder(undefined);assert.equal(calls.length,0);assert.match(ctx.state.textContent,/erneut öffnen/);
}
{
  const {ctx,calls}=setup();await ctx.sendFiles([{name:'Foto.jpg'}]);assert.equal(calls.length,2);assert.equal(calls[0].options.body.values.folder_id,'folder-a');assert.equal(calls[1].url,'/intern/api/private-orders.php?action=folder&id=folder-a');assert.match(ctx.state.textContent,/Unterlagen abgelegt/);assert.equal(ctx.breadcrumbs.length,2);
}
{
  const {ctx,calls}=setup();let resolve;ctx.request=(url,options)=>{calls.push({url,options});return new Promise(r=>{resolve=r;});};const pending=ctx.sendFiles([{name:'Foto.jpg'}]);ctx.active=null;ctx.activeFolder=null;ctx.openEpoch++;resolve({ok:true,item:{id:'saved'}});await pending;assert.equal(calls.length,1);assert.equal(ctx.active,null);assert.equal(ctx.upload.disabled,true);assert.equal(ctx.input.value,'');
}
{
  const {ctx,calls}=setup();let resolve;ctx.request=(url,options)=>{calls.push({url,options});if(calls.length===1)return new Promise(r=>{resolve=r;});return Promise.resolve({ok:true,item:{id:'saved'}});};const pending=ctx.sendFiles([{name:'1.jpg'},{name:'2.jpg'}]);ctx.active={id:'case-b'};ctx.activeFolder={id:'folder-b'};ctx.openEpoch++;resolve({ok:true,item:{id:'saved'}});await pending;assert.equal(calls.length,2);assert.deepEqual(calls.map(c=>c.options.body.values.folder_id),['folder-a','folder-a']);assert.equal(ctx.active.id,'case-b');
}
console.log('private_orders_upload_test: ok (upload, folder refresh, search and case switch)');
