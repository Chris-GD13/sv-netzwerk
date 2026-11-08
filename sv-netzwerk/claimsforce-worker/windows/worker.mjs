import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {execFileSync, spawn} from 'node:child_process';
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
  const startup=path.join(process.env.APPDATA||os.homedir(),'Microsoft','Windows','Start Menu','Programs','Startup');
  fs.mkdirSync(startup,{recursive:true});
  const cmdFile=path.join(startup,'SV-Netzwerk Langdock Worker.cmd');
  const workerPath=path.join(import.meta.dirname,'worker.mjs');
  fs.writeFileSync(cmdFile,'@echo off\\r\\nstart "" "'+process.execPath+'" "'+workerPath+'"\\r\\n','utf8');
  log('Autostart eingerichtet: '+cmdFile); process.exit(0);
}
let browser;
let launched=false;
async function launchWorkerChrome(){
  if(launched)return;
  const exeCandidates=[
    process.env.CHROME_PATH,
    path.join(process.env.ProgramFiles||'C:\\Program Files','Google','Chrome','Application','chrome.exe'),
    path.join(process.env.LOCALAPPDATA||'', 'Google','Chrome','Application','chrome.exe'),
    path.join(process.env.ProgramFiles||'C:\\Program Files','Microsoft','Edge','Application','msedge.exe')
  ].filter(Boolean);
  const exe=exeCandidates.find(p=>fs.existsSync(p));
  if(!exe)throw new Error('Chrome oder Edge wurde nicht gefunden.');
  const profile=path.join(root,'BrowserProfile');
  fs.mkdirSync(profile,{recursive:true});
  spawn(exe,['--remote-debugging-port=9222','--user-data-dir='+profile,'--no-first-run','--no-default-browser-check',cfg.portal+'/intern/langdock/'],{detached:true,stdio:'ignore'}).unref();
  launched=true;
  log('Arbeitsbrowser gestartet. Einmalige Anmeldung in diesem Fenster erforderlich.');
}
async function connect(){
  if(browser)return browser;
  for(let attempt=0;attempt<2;attempt++){
    try{browser=await chromium.connectOverCDP(cfg.cdp);log('Chrome/Edge verbunden');return browser;}
    catch(error){if(attempt===0){await launchWorkerChrome();await new Promise(r=>setTimeout(r,2500));}else throw error;}
  }
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
