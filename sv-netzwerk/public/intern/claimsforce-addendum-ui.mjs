const input = (label, name, value='', type='text') => {
  const wrapper = document.createElement('label');
  const control = document.createElement(type==='textarea'?'textarea':'input');
  if(type!=='textarea')control.type=type;
  control.name=name;
  control.value=value ?? '';
  wrapper.append(label,control);
  return wrapper;
};

const paragraph = value => {
  const p = document.createElement('p');
  p.textContent = value;
  return p;
};

const button = (label, handler) => {
  const node = document.createElement('button');
  node.type = 'button';
  node.className = 'cfw-secondary';
  node.textContent = label;
  node.addEventListener('click',handler);
  return node;
};

function choice(name, value, options) {
  const select = document.createElement('select');
  select.name=name;
  for (const [key,label] of options) select.add(new Option(label,key));
  select.value=value;
  return select;
}

function paymentRow(record, panel) {
  const line = document.createElement('div');
  line.className = 'cfw-grid';
  const category = choice('category',record.category||'', [['','Kategorie klären'],['vn','Versicherungsnehmer'],
    ['partner','Partnerfirma / Direktzahlung'],['internal','Interne SV-Kosten']]);
  category.setAttribute('aria-label','Kategorie');
  const status = choice('status',record.status||'', [['','Zahlungsstatus klären'],['paid','Geleistet'],['open','Offene Schlusszahlung']]);
  status.setAttribute('aria-label','Zahlungsstatus');
  line.append(input('Datum','date',record.date,'date'), input('Empfänger','recipient',record.recipient), category, status,
    input('Betrag in EUR','amount',record.amount),input('Zahlungsbeleg','evidence',record.evidence),
    button('Zahlungsposten entfernen',()=>line.remove()));
  panel.append(line);
}

export function renderAddendum(root, job, request) {
  root.replaceChildren();
  const plan = job.plan || {};
  const messages = [...(job.corrections || []),...(plan.ledger?.issues || [])];
  if (messages.length) {
    const list = document.createElement('ul');
    for (const message of new Set(messages)) {
      const li = document.createElement('li');
      li.textContent = message;
      list.append(li);
    }
    root.append(list);
  }
  if (!plan.rules) return;
  root.append(paragraph('Master '+plan.rules.masterVersion+' · '+plan.rules.route));
  if (job.duration_ms != null) root.append(paragraph('Rücklesen in Claimsforce: '+job.duration_ms+' ms'));
  if (job.status === 'running') {
    root.append(paragraph('Der gezielte Browserauftrag wartet auf die Claimsforce-Brücke oder wird gerade ausgeführt.'));
    return;
  }
  const form = document.createElement('form');
  const fields = document.createElement('div');
  fields.className = 'cfw-grid';
  fields.append(input('Claimsforce-Entwurfs-ID aus dem Nachtragslink','report_id',plan.reportId),
    input('Ansprechpartner (Frau/Herr und Name)','contact',plan.contact),
    input('Aktenbeleg zum Ansprechpartner','contact_evidence',plan.contactEvidence),
    input('Fachtext','body',plan.body,'textarea'));
  form.append(fields);
  const attachments=document.createElement('fieldset');
  const caption=document.createElement('legend');
  caption.textContent='Anlagen für diesen Nachtrag bestätigen';
  attachments.append(caption);
  const files=Object.values(plan.candidates||{});
  if(!files.length) attachments.append(paragraph('Keine neuen einschlägigen Vorschläge im vorhandenen Fallbestand.'));
  const confirmed=new Map((plan.attachments||[]).map(file=>[file.id,file.version]));
  for(const file of files) {
    const label=document.createElement('label');
    const control=document.createElement('input');
    control.type='checkbox';
    control.dataset.fileId=file.id;
    control.checked=plan.decisions?.[file.id]===true;
    const updated=control.checked&&confirmed.get(file.id)!==file.version;
    label.append(control,' '+file.name+(file.date?' · '+file.date.slice(0,10):'')+(updated?' · neue Fassung zur Bestätigung':''));
    const item=document.createElement('div');
    item.append(label);
    attachments.append(item);
  }
  form.append(attachments);
  const payments=document.createElement('details');
  const summary=document.createElement('summary');
  summary.textContent='Zahlungsabgleich';
  payments.append(summary,input('Belegter Abrechnungsbetrag vor Zahlungen in EUR','basis',plan.basisInput));
  const rows=document.createElement('div');
  for(const payment of plan.payments||[])paymentRow(payment,rows);
  payments.append(rows,button('Zahlungsposten erfassen',()=>paymentRow({},rows)));
  const kinds=job.documents.filter(kind=>['SE','ZB'].includes(kind));
  for(const kind of kinds){
    const group=document.createElement('fieldset');
    const legend=document.createElement('legend');
    legend.textContent=kind;
    group.className='cfw-grid';
    group.append(legend,input(kind+' Entwurfs-ID aus dem Formularlink','form_'+kind,plan.forms?.[kind]),
      input('Feldbeschriftung geleistete Zahlungen','paid_'+kind,plan.paymentFields?.[kind]?.paid||(kind==='SE'?'Teilzahlungen':'')),
      input('Feldbeschriftung offene Schlusszahlung','open_'+kind,plan.paymentFields?.[kind]?.open||(kind==='SE'?'Neuwert-Entschädigung':'')));
    if(kind==='SE') group.append(input('Feldbeschriftung Direktzahlungen / Abtretungen','direct_SE',plan.paymentFields?.SE?.direct||'Direktzahlungen / Abtretungen'));
    payments.append(group);
  }
  payments.open=!!(kinds.length||plan.ledger);
  form.append(payments);
  const response=paragraph('');
  response.setAttribute('role','status');
  const actions=document.createElement('div');
  actions.className='cfw-actions';
  let dirty=false;
  const onRequest=async(action,payload)=>{
    for(const control of actions.querySelectorAll('button'))control.disabled=true;
    response.textContent='Auftrag wird geprüft …';
    try {await request(action,payload);}
    catch(error){response.textContent=error.message;}
    finally{for(const control of actions.querySelectorAll('button'))control.disabled=control===check&&dirty;}
  };
  const save=button('Vorlage und Auswahl speichern',()=>{
    const data=new FormData(form), value=key=>String(data.get(key)||'');
    const payload={report_id:value('report_id'),contact:value('contact'),contact_evidence:value('contact_evidence'),body:value('body'),
      choices:Object.fromEntries([...attachments.querySelectorAll('[data-file-id]')].map(node=>[node.dataset.fileId,node.checked])),
      basis:value('basis'),payments:[...rows.children].map(row=>Object.fromEntries([...row.querySelectorAll('input,select')].map(control=>[control.name,control.value]))),
      forms:Object.fromEntries(kinds.map(kind=>[kind,value('form_'+kind)])),
      payment_fields:Object.fromEntries(kinds.map(kind=>[kind,{paid:value('paid_'+kind),open:value('open_'+kind),...(kind==='SE'?{direct:value('direct_SE')}:{})}]))};
    onRequest('prepare_addendum',payload);
  });
  const check=button('Gespeicherte Entwürfe prüfen',()=>onRequest('check_addendum',{}));
  actions.append(save);
  if(job.draft)actions.append(check);
  const markDirty=()=>{dirty=true;check.disabled=true;};
  form.addEventListener('input',markDirty);
  rows.addEventListener('click',event=>{if(event.target.closest('button'))markDirty();});
  payments.addEventListener('click',event=>{if(event.target.closest('button'))markDirty();});
  form.addEventListener('submit',event=>event.preventDefault());
  form.append(actions,response);
  root.append(form);
  if(job.draft){
    const draft=input('Vorlage für den nativen Nachtrag','draft',job.draft,'textarea');
    draft.querySelector('textarea').readOnly=true;
    draft.className='cfw-instructions';
    root.append(draft,paragraph('Vorlage und bestätigte Anlagen im zugehörigen Claimsforce-Entwurf speichern. Anschließend die Abschlussprüfung starten.'));
  }
}
