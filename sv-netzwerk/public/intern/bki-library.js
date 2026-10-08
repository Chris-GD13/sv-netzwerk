(() => {
  const host=document.getElementById('bk-library');if(!host)return;
  const api='/intern/api/bki-library-upload.php';
  const status=host.querySelector('[data-status]'), list=host.querySelector('[data-list]');
  async function request(url,options){const r=await fetch(url,options);const data=await r.json();if(!r.ok)throw Error(data.error||data.message||'Preisablage nicht erreichbar.');return data;}
  const refresh=async()=>{try{const data=await request(api);status.textContent=`IONOS · ${data.positions} Preispositionen · ${data.documents.filter(d=>d.on_ionos).length} Original-PDFs gespeichert`;list.replaceChildren();for(const doc of data.documents){const row=document.createElement('p');const link=document.createElement('a');link.textContent=doc.name+' · '+doc.pages+' Seiten'+(doc.on_ionos?' · Original geprüft':' · Original fehlt');link.href=api+'?action=pdf&id='+encodeURIComponent(doc.id);link.target='_blank';row.append(link);list.append(row);}}catch(e){status.textContent=e.message;}};
  host.querySelector('input').onchange=async event=>{
    const input=event.target;input.disabled=true;
    try{for(const file of input.files){status.textContent=file.name+' · Prüfsumme wird berechnet …';const bytes=await file.arrayBuffer();const sha=Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',bytes)),x=>x.toString(16).padStart(2,'0')).join('');const kind=file.name.endsWith('.json')?'index':'pdf';
      const upload=await request(api+'?action=begin',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name:file.name,size:file.size,sha256:sha,kind})});
      for(let offset=0;offset<file.size;offset+=2097152){status.textContent=file.name+' · '+Math.round(offset/file.size*100)+' % auf IONOS übertragen';await request(api+'?action=chunk&id='+upload.id+'&offset='+offset,{method:'POST',headers:{'Content-Type':'application/octet-stream'},body:file.slice(offset,offset+2097152)});}
      status.textContent=file.name+' · Original und Prüfsumme werden auf IONOS geprüft …';await request(api+'?action=finish&id='+upload.id,{method:'POST'});
    }await refresh();}catch(e){status.textContent='Übertragung fehlgeschlagen: '+e.message;}finally{input.disabled=false;input.value='';}
  };refresh();
})();
