// Local synthetic bridge + persistence; actual notes module, assistant and keep/show.
const cases={a:{_loaded:true,folder_id:'fixture-a',meta:{schaden_nr:'TEST-A-001',claimsforce_claim_id:'11111111-1111-4111-8111-111111111111',claimsforce_profile:'christian',claimsforce_notizen:[{id:'cached-a',text:'Gespeicherte synthetische Notiz A'}]}},b:{_loaded:true,folder_id:'fixture-b',meta:{schaden_nr:'TEST-B-002',claimsforce_claim_id:'22222222-2222-4222-8222-222222222222',claimsforce_profile:'jens',claimsforce_notizen:[]}}};
let reads=0,saves=0,delay=false,pending=[];
const counts=()=>$('fixture-counts').textContent=`Notizabrufe ${reads} · Speicherungen ${saves} · ausstehend ${pending.length}`;
window.fetch=async(url,options)=>{
 if(url==='/intern/api/google-drive-sync.php?action=merge_claimsforce_notes'){
  if(options.signal?.aborted)throw Error('NOTES_ABORTED');
  const input=JSON.parse(options.body),c=Object.values(cases).find(c=>c.folder_id===input.folder_id);
  if(active?.folder_id!==input.folder_id)throw Error('CASE_CHANGED');
  saves++;c.meta.claimsforce_notizen=mergeNotes(c.meta.claimsforce_notizen,input.notes);counts();
  return new Response(JSON.stringify({ok:true,identity:input.identity,notes:c.meta.claimsforce_notizen}));
 }
 if(!url.startsWith('/intern/api/case-work.php?assistant=1'))throw Error('LOCAL_ONLY');
 return realFetch(url,options);
};
window.addEventListener('message',event=>{
 if(event.source!==window||event.origin!==location.origin||event.data?.type!=='SVNET_CLAIMS_NOTES_READ')return;
 reads++;const req=event.data;
 const reply=()=>window.postMessage({type:'SVNET_CLAIMS_NOTES_RESULT',requestId:req.requestId,ok:true,result:{identity:req.identity,notes:[{id:'manual-'+req.identity.folderId,text:'Manuell aktualisierte synthetische Notiz '+req.identity.folderId,updatedAt:'2026-10-17T10:00:00Z'}]}},location.origin);
 delay?pending.push(reply):reply();counts();
});
for(const key of ['a','b'])$('fixture-'+key).onclick=()=>{invalidateOpenedCase();keep(structuredClone(cases[key]));};
$('fixture-clear').onclick=()=>{invalidateOpenedCase();show();};
$('fixture-delay').onclick=()=>{delay=!delay;$('fixture-delay').textContent=delay?'Verzögerung aktiv':'Notizantwort verzögern';};
$('fixture-release').onclick=()=>{pending.splice(0).forEach(reply=>reply());counts();};
show();counts();
