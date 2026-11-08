import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {execFileSync} from 'node:child_process';
import {chromium} from 'playwright-core';

const root=path.join(process.env.APPDATA||os.homedir(), 'SV-Netzwerk', 'LangdockWorker');
const configPath=path.join(root,'config.json');
const statePath=path.join(root,'state.json');
const defaults={portal:'https://www.sv-netzwerk.eu',cdp:'http://127.0.0.1:9222',pollMs:5000,profile:'Christian · Standardfälle'};
fs.mkdirSync(root,{recursive:true});
const readJson=(p,f)=>{try{return JSON.parse(fs.readFileSync(p,'utf8'));}catch{return f;}};
const cfg={...defaults,...readJson(configPath,{})};
fs.writeFileSync(configPath,JSON.stringify(cfg,null,2));
const log=(...v)=>console.log(new Date().toISOString(),...v);
if(process.argv.includes('--install-startup')){
  const cmd=process.execPath+' "'+path.join(import.meta.dirname,'worker.mjs')+'"';
  execFileSync('schtasks.exe',['/Create','/SC','ONLOGON','/TN','SV-Netzwerk Langdock Worker','/TR',cmd,'/F'],{stdio:'inherit'});
  log('Autostart eingerichtet'); process.exit(0);
}
let browser;
async function connect(){
  if(browser) return browser;
  browser=await chromium.connectOverCDP(cfg.cdp);
  log('Chrome/Edge verbunden');
  return browser;
}
async function page(){
  const b=await connect();
  const pages=b.contexts().flatMap(c=>c.pages());
  let p=pages.find(x=>x.url().startsWith(cfg.portal+'/intern/'));
  if(!p) p=await b.contexts()[0].newPage();
  if(!p.url().startsWith(cfg.portal+'/intern/')) await p.goto(cfg.portal+'/intern/langdock/',{waitUntil:'domcontentloaded'});
  return p;
}
async function api(p,action,body){
  return p.evaluate(async ({action,body})=>{
    const r=await fetch('/intern/api/claimsforce-workflow.php?action='+encodeURIComponent(action),{method:body?'POST':'GET',credentials:'same-origin',headers:body?{'Content-Type':'application/json'}:{},body:body?JSON.stringify(body):undefined,cache:'no-store'});
    return {status:r.status,data:await r.json()};
  },{action,body});
}
async function tick(){
  const p=await page();
  const next=await api(p,'next');
  if(next.status===404||!next.data?.job) return;
  const job=next.data.job;
  log('Auftrag',job.workflow_id,job.claim_no);
  await api(p,'phase',{workflow_id:job.workflow_id,status:'running',phase:'aktenaufnahme',message:'Lokaler Worker hat den Auftrag übernommen.'});
  await p.goto('https://web.claimsforce.com/claims',{waitUntil:'domcontentloaded'}).catch(()=>{});
  await api(p,'phase',{workflow_id:job.workflow_id,status:'waiting_question',phase:'claimsforce',message:'Claimsforce ist geöffnet. Die Dokumentbefüllung wird als nächster Worker-Schritt ausgeführt.'});
  fs.writeFileSync(statePath,JSON.stringify({workflow_id:job.workflow_id,last_phase:'claimsforce',updated_at:new Date().toISOString()},null,2));
}
setInterval(()=>tick().catch(e=>log('Fehler',e.message)),cfg.pollMs);
tick().catch(e=>log('Startfehler',e.message));
