(()=>{
  if(location.pathname!=='/intern/privatauftraege/angebot/')return;
  document.documentElement.dataset.svnetFinnyaBridge='ready';
  const status=(recordId,message,factoring)=>window.postMessage({type:'SVNET_FINNYA_STATUS',recordId,message,factoring},location.origin);
  async function api(action,body){const response=await fetch('/intern/api/private-business.php?action='+action,{credentials:'same-origin',cache:'no-store',method:body?'POST':'GET',headers:body?{'Content-Type':'application/json'}:{},body:body?JSON.stringify(body):undefined});const value=await response.json();if(!response.ok||!value.ok)throw Error(value.error||'Portalzugriff fehlgeschlagen.');return value;}
  let busy=false;
  window.addEventListener('message',async event=>{
    if(event.source!==window||event.origin!==location.origin||event.data?.type!=='SVNET_FINNYA_START'||busy)return;
    const id=event.data.recordId;if(!/^[a-f0-9-]{36}$/i.test(id||''))return;
    busy=true;
    try{
      // Always obtain canonical saved customer data through the authenticated server ACL.
      const {record}=await api('get&id='+encodeURIComponent(id));const d=record.data;
      const data={};for(const name of ['customer_type','company','first_name','last_name','billing_street','billing_postcode','billing_city','phone','mobile','email'])data[name]=String(d[name]||'');
      const response=await chrome.runtime.sendMessage({type:'FINNYA_OPEN',job:{recordId:id,customerKey:record.customer_key,data}});
      if(!response?.ok)throw Error(response?.error||'Browser-Brücke nicht bereit.');
      status(id,'Finnya geöffnet. Kundenabgleich und Limitprüfung laufen.');
    }catch(error){status(id,error.message);}finally{busy=false;}
  });
  chrome.runtime.onMessage.addListener((message,sender,reply)=>{
    if(message?.type!=='FINNYA_PORTAL_RESULT'||sender.id!==chrome.runtime.id)return;
    (async()=>{
      if(message.result.error){status(message.recordId,message.result.error);return;}
      const saved=await api('factoring-result',{id:message.recordId,customer_key:message.customerKey,...message.result});
      status(message.recordId,'Finnya-Ergebnis intern gespeichert.',saved.factoring);
    })().then(()=>reply({ok:true}),error=>{status(message.recordId,error.message);reply({ok:false,error:error.message});});
    return true;
  });
})();
