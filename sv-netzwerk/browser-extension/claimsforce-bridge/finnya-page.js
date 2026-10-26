(()=>{
  if(location.origin!=='https://factoringplus.dv-portal.de')return;
  const visible=e=>!!e&&!!e.getClientRects().length;
  const norm=s=>String(s||'').normalize('NFKC').toLocaleLowerCase('de').replace(/\s+/g,' ').trim();
  const buttons=(root,label)=>[...root.querySelectorAll('button')].filter(e=>visible(e)&&norm(e.innerText)===norm(label));
  const labelInput=(root,text)=>{const label=[...root.querySelectorAll('label')].find(e=>norm(e.innerText)===norm(text));return label?document.getElementById(label.htmlFor):null;};
  const fill=(input,value)=>{if(!input||!visible(input))throw Error('Finnya-Eingabefeld fehlt.');Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value').set.call(input,value);input.dispatchEvent(new Event('input',{bubbles:true}));input.dispatchEvent(new Event('change',{bubbles:true}));input.blur();};
  const cents=s=>{const match=String(s).match(/(?:\d{1,3}(?:\.\d{3})+|\d+),\d{2}/);return match?Number(match[0].replaceAll('.','').replace(',','')):null;};
  async function wait(fn,ms=30000){const end=Date.now()+ms;while(Date.now()<end){const result=fn();if(result)return result;await new Promise(r=>setTimeout(r,200));}throw Error('Finnya hat den erwarteten Schritt noch nicht angezeigt. Bitte im geöffneten Tab prüfen; kein erneuter Anlageversuch gestartet.');}
  async function run(){
    const response=await chrome.runtime.sendMessage({type:'FINNYA_JOB'});if(!response?.ok)return;
    const job=response.job,d=job.data;
    const report=result=>chrome.runtime.sendMessage({type:'FINNYA_RESULT',result:{...result,source_url:location.href}});
    const banner=document.createElement('div');banner.style.cssText='position:fixed;bottom:12px;left:120px;right:25px;z-index:99999;background:#17394c;color:white;padding:12px;border-radius:8px';banner.textContent='SV-Netzwerk: Kundenabgleich für den geöffneten Angebotsvorgang läuft.';document.body.append(banner);
    try{
      const dialog=await wait(()=>[...document.querySelectorAll('[role="dialog"],.v-dialog--active')].find(e=>visible(e)&&e.innerText.includes('Kunde anlegen & Limit prüfen')),60000);
      const business=d.customer_type==='business';const radio=labelInput(dialog,business?'Firma':'Privatperson');
      if(radio&&!radio.checked)radio.click();
      await wait(()=>visible(document.getElementById(business?'company':'firstName')));
      if(business){fill(document.getElementById('company'),d.company);fill(document.getElementById('contactPerson'),[d.first_name,d.last_name].filter(Boolean).join(' '));}
      else {fill(document.getElementById('firstName'),d.first_name);fill(document.getElementById('lastName'),d.last_name);}
      fill(document.getElementById('streetAndHouse'),d.billing_street);fill(document.getElementById('zipCode'),d.billing_postcode);fill(document.getElementById('city'),d.billing_city);
      fill(labelInput(dialog,'Telefonnummer (optional)'),d.phone||d.mobile);fill(labelInput(dialog,'E-Mail (optional)'),d.email);
      const submit=await wait(()=>buttons(dialog,'Jetzt Limit prüfen').find(e=>!e.disabled));
      // This vendor workflow first detects existing customers; never bypass its duplicate check.
      submit.click();
      const identity=root=>{const text=norm(root.innerText);return [business?d.company:[d.first_name,d.last_name].join(' '),d.billing_street,d.billing_postcode,d.billing_city].every(value=>value&&text.includes(norm(value)));};
      let clickedCreate=false;
      const outcome=await wait(()=>{
        const active=[...document.querySelectorAll('[role="dialog"],.v-dialog--active')].find(visible);
        if(!active)return null;
        if(!identity(active))return null;
        const existing=active.innerText.includes('Bestehenden Kunden gefunden');
        const limit=active.innerText.match(/Limit:\s*([\d.,]+)\s*€\s*\(([\d.,]+)\s*€\)/);
        if(limit&&(existing||/Kunde.*erfolgreich.*angelegt/i.test(active.innerText)))return {root:active,limit_cents:cents(limit[1]),available_cents:cents(limit[2]),status:'confirmed',existing};
        if(buttons(active,'Zum Kunden').length===1&&/Limit.*(?:in Prüfung|wird geprüft|angefragt)/i.test(active.innerText))return {root:active,status:'pending',limit_cents:null,available_cents:null};
        if(buttons(active,'Zum Kunden').length===1&&/(?:Limit abgelehnt|kein Limit gewährt)/i.test(active.innerText))return {root:active,status:'declined',limit_cents:null,available_cents:null};
        // Only the dedicated creation action, never an acceptance, payment or purchase action.
        const create=['Kunden anlegen','Kunde anlegen'].flatMap(label=>buttons(active,label));
        if(!existing&&!clickedCreate&&create.length===1&&!create[0].disabled){clickedCreate=true;create[0].click();}
        return null;
      },60000);
      const {root,...result}=outcome;delete result.existing;
      const customer=buttons(root,'Zum Kunden');if(customer.length!==1)throw Error('Finnya hat noch keinen eindeutig angelegten Kunden bestätigt.');customer[0].click();await wait(()=>location.pathname.startsWith('/debtors/')&&identity(document.body));
      const saved=await report(result);if(!saved?.ok)throw Error(saved?.error||'Das interne Speichern wurde nicht bestätigt.');
      banner.textContent='Finnya-Ergebnis wurde an den Angebotsvorgang zurückgemeldet. Es erscheint nicht im Ausdruck.';
    }catch(error){banner.textContent=error.message;await report({error:error.message});}
  }
  run().catch(()=>{});
})();
