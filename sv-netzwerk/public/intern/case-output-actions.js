(() => {
  'use strict';
  const logo = '/intern/assets/private-business/sv-netzwerk-eu-logo.jpg';
  const escape = text => String(text).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const caseKey = () => {
    const c=window.svnetOpenedClaimsCase;
    return c?._loaded===true ? JSON.stringify([c.folder_id,c.meta?.schaden_nr]) : '';
  };
  const expand = node => { for(let p=node;p;p=p.parentElement){if(p.tagName==='DETAILS')p.open=true;p.classList.remove('is-collapsed');p.querySelector(':scope > .vf-collapse-trigger')?.setAttribute('aria-expanded','true');} };
  function toolbar(source) {
    const bar=document.createElement('div');bar.className='vf-output-actions';bar.setAttribute('role','group');bar.setAttribute('aria-label','Speichern und weitergeben');
    const status=document.createElement('p');status.className='vf-meta';status.setAttribute('role','status');
    for(const [action,label] of [['email','Per E-Mail'],['print','Drucken / PDF'],['save','Auf Gerät speichern'],['share','Teilen · WhatsApp']]){
      const button=document.createElement('button');button.type='button';button.className='vf-secondary';button.textContent=label;button.dataset.outputAction=action;
      button.onclick=async()=>{
        // Open the print window synchronously so mobile popup protection cannot lose it.
        const preview=action==='print'?window.open('about:blank','_blank'):null;
        const identity=caseKey();button.disabled=true;status.textContent='Ausgabe wird vorbereitet …';
        try{
          const item=await source();if(identity!==caseKey())throw Error('Der Schaden wurde gewechselt. Ausgabe bitte erneut öffnen.');
          if(!(item.file instanceof File)||!item.file.size)throw Error('Keine fertige Ausgabe vorhanden.');
          if(action==='save'){
            const url=URL.createObjectURL(item.file),a=document.createElement('a');a.href=url;a.download=item.file.name;a.click();setTimeout(()=>URL.revokeObjectURL(url),60000);
            status.textContent='Download an den Browser übergeben. Den Speicherort wählen Sie am Gerät.';
          }else if(action==='share'){
            if(!navigator.share||!navigator.canShare?.({files:[item.file]}))throw Error('Dieses Gerät kann Dateien hier nicht direkt teilen. Bitte zuerst speichern und die Datei anschließend in WhatsApp anhängen.');
            await navigator.share({title:item.title,files:[item.file]});status.textContent='An die ausgewählte App übergeben. Ein Versand wird dort bestätigt.';
          }else if(action==='email'){
            const field=document.getElementById('vf-mail-files'),body=document.getElementById('vf-mail-body'),subject=document.getElementById('vf-mail-subject');
            if(!field||!body||!subject)throw Error('E-Mail-Bearbeitung ist hier nicht verfügbar.');
            if(field.files.length||body.value.trim()||subject.value.trim())throw Error('Im E-Mail-Bereich liegt bereits ein Entwurf. Bitte diesen zuerst abschließen oder leeren.');
            if(!caseKey())throw Error('Für den Portalversand bitte einen berechtigten Schaden öffnen. Alternativ die Datei über „Teilen“ an die Mail-App übergeben.');
            const transfer=new DataTransfer();transfer.items.add(item.file);field.files=transfer.files;field.dispatchEvent(new Event('change',{bubbles:true}));
            subject.value=item.title;body.value='Guten Tag,\n\nanbei erhalten Sie die Unterlage.\n\nFreundliche Grüße';expand(field);field.closest('.vf-mail')?.scrollIntoView({block:'start',behavior:'smooth'});
            status.textContent='E-Mail mit Anhang vorbereitet. Empfänger und Text prüfen; noch nicht versendet.';
          }else{
            if(!preview)throw Error('Bitte das Druckfenster im Browser zulassen.');
            const url=URL.createObjectURL(item.file);preview.location.replace(url);setTimeout(()=>URL.revokeObjectURL(url),60000);
            status.textContent='Druckansicht geöffnet. Dort Drucken oder Als PDF speichern wählen.';
          }
        }catch(error){preview?.close();status.textContent=error.name==='AbortError'?'Teilen abgebrochen.':error.message;}
        finally{button.disabled=false;}
      };
      bar.append(button);
    }
    const box=document.createElement('div');box.className='vf-output-box';box.append(bar,status);return box;
  }
  async function resultFile(node,title,providedText) {
    // Text only: no internal controls, IDs, raw HTML, external assets or executable markup.
    const clone=node.cloneNode(true);clone.querySelectorAll('.vf-output-box,button,input,textarea,select,script,style,[hidden],details').forEach(n=>n.remove());
    const text=providedText??clone.textContent.trim();if(!text)throw Error('Bitte zuerst ein Ergebnis erstellen oder öffnen.');
    const response=await fetch(logo,{credentials:'same-origin'});if(!response.ok)throw Error('Logo konnte nicht geladen werden.');
    const bytes=new Uint8Array(await response.arrayBuffer());let binary='';for(const b of bytes)binary+=String.fromCharCode(b);
    const html='<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'+escape(title)+'</title><style>body{font:16px/1.6 Arial;color:#17324a;margin:35px;max-width:900px}img{max-width:100%;height:auto}pre{font:inherit;white-space:pre-wrap;overflow-wrap:anywhere}@media print{body{margin:0}}</style><img width="300" alt="SV-Netzwerk" src="data:image/jpeg;base64,'+btoa(binary)+'"><h1>'+escape(title)+'</h1><pre>'+escape(text)+'</pre></html>';
    return {title,file:new File([html],title.replace(/[^\p{L}\p{N} ._-]/gu,'_')+'.html',{type:'text/html'})};
  }
  function enhance() {
    for(const id of ['vf-assistant-results','vf-analysis-result','vf-job','vf-task-body','vf-claims-notes-list']){
      const node=document.getElementById(id);if(!node||!node.textContent.trim()||node.querySelector(':scope > .vf-output-box'))continue;
      node.append(toolbar(()=>resultFile(node,(window.svnetOpenedClaimsCase?.meta?.schaden_nr||'Freie Bearbeitung')+' · '+(id==='vf-claims-notes-list'?'Notizen':'Arbeitsergebnis'))));
    }
    for(const kind of ['offer','invoice']){
      const node=document.getElementById('dr-'+kind+'-edit');if(!node||node.querySelector(':scope > .vf-output-box'))continue;
      node.append(toolbar(()=>{
        const prefix='dr-'+kind+'-',lines=['Prüfentwurf – vor Verwendung fachlich kontrollieren.'];
        for(const [key,label] of [['company','Firma'],['number','Belegnummer'],['date','Datum'],['net','Netto EUR'],['vat','Umsatzsteuer EUR'],['gross','Originalbetrag brutto EUR'],['decision','Entscheidung'],['release_amount','Freigabebetrag brutto EUR'],['reason','Fachliche Stellungnahme']]){
          const value=document.getElementById(prefix+key)?.value;if(value)lines.push(label+': '+({'approved':'freigegeben','rejected':'nicht freigegeben'}[value]||value));
        }
        if(lines.length===1)throw Error('Bitte zuerst einen Beleg vorbereiten.');
        const number=document.getElementById(prefix+'standalone')?.checked?document.getElementById(prefix+'case_no')?.value:window.svnetOpenedClaimsCase?.meta?.schaden_nr;
        return resultFile(node,(number||'Freie Prüfung')+' · '+(kind==='invoice'?'Rechnungsprüfung':'Angebotsprüfung'),lines.join('\n\n'));
      }));
    }
    for(const anchor of document.querySelectorAll('#vfdb-list a[href],#vf-file-list a[href],#vf-job a[href],#vf-task-attachments a[href],#vf-excel-links a[href],.dr-history a[href],.dr-actions a[href]')){
      if(anchor.dataset.outputEnhanced||anchor.hidden)continue;
      const url=new URL(anchor.getAttribute('href'),location.href);
      // Fetch authenticated original files only from known same-origin download handlers.
      if(url.origin!==location.origin||!url.pathname.startsWith('/intern/api/')||!/(download|attachment|file)/i.test(url.search))continue;
      anchor.dataset.outputEnhanced='true';anchor.after(toolbar(async()=>{
        if(!anchor.isConnected||(url.pathname.endsWith('/case-file-browser.php')&&url.searchParams.get('folder_id')!==window.svnetOpenedClaimsCase?.folder_id))throw Error('Die Unterlage gehört nicht zur aktuell geöffneten Ansicht. Bitte erneut öffnen.');
        const r=await fetch(url.href,{credentials:'same-origin',cache:'no-store'});if(!r.ok)throw Error('Original kann nicht geladen werden.');
        const blob=await r.blob();if(/json|text\/html/i.test(blob.type))throw Error('Die Antwort ist keine herunterladbare Originaldatei.');
        const disposition=r.headers.get('content-disposition')||'';
        const name=disposition.match(/filename="([^"]+)"/i)?.[1]||anchor.textContent.trim()||'Unterlage';
        return {title:name,file:new File([blob],name.replace(/[\\/]/g,'_'),{type:blob.type})};
      }));
    }
  }
  let queued=false;
  const schedule=()=>{if(queued)return;queued=true;requestAnimationFrame(()=>{queued=false;enhance();});};
  const setup=()=>{new MutationObserver(records=>{if(records.some(r=>[...r.addedNodes].some(n=>n.nodeType===1&&!n.classList.contains('vf-output-box'))))schedule();}).observe(document.querySelector('.vf-app')||document.body,{childList:true,subtree:true});enhance();};
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',setup,{once:true});else setup();
})();
