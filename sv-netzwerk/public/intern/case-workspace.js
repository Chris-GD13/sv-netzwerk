(() => {
  'use strict';
  const setup = () => {
    const el = id => document.getElementById(id);
    const panel = el('vf-assistant-panel');
    if (!panel || !el('vf-workspace-start')) return;
    document.documentElement.classList.add('vf-unified');
    const diagnostic = new URLSearchParams(location.search).get('ansicht') === 'diagnose';
    const instruction = el('vf-assistant-order'), flow = el('vf-workspace-flow');
    const start = el('vf-workspace-start'), route = el('vf-assistant-route');
    const actions = [...document.querySelectorAll('[data-case-action]')];
    const dispatchers = [...document.querySelectorAll('[data-assistant-task]')];
    let task = 'check', opened = '', lockedInstruction = null;
    const caseSelection = el('vf-case-selection'), caseTrigger = caseSelection?.querySelector(':scope > .vf-step');
    const caseChoice = collapsed => {
      caseSelection?.classList.toggle('is-collapsed', collapsed);
      caseTrigger?.setAttribute('aria-expanded', String(!collapsed));
      const title = caseTrigger?.querySelector('strong'); if (title) title.textContent = collapsed ? 'Anderen Schaden öffnen' : 'Fall auswählen';
    };
    if (caseTrigger) {
      caseSelection.classList.add('vf-collapsible');caseTrigger.classList.add('vf-collapse-trigger');
      caseTrigger.setAttribute('role','button');caseTrigger.tabIndex=0;
      caseTrigger.onclick=()=>caseChoice(!caseSelection.classList.contains('is-collapsed'));
      caseTrigger.onkeydown=event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();caseTrigger.click();}};
      caseChoice(false);
    }
    const flows = {
      new: 'Neuen Schaden anhand der vorhandenen Beauftragung und Unterlagen prüfen; fehlende Angaben und erforderliche Erstunterlagen feststellen.',
      review: 'Bestehende Akte einschließlich Verlauf aufarbeiten. Belegte Fakten, frühere Entscheidungen und Widersprüche abgleichen.',
      supplement: 'Neue Belege und Erkenntnisse mit vorhandenem Bearbeitungsstand abgleichen. Ersetzende Belege und Dubletten trennen; notwendigen Nachtrag vorbereiten.',
      closing: 'Schadenabschluss vorbereiten: fehlende Belege, tatsächliche Zahlungen, Restreserve und Stimmigkeit von Bericht und SE prüfen. Keinen Fall schließen.'
    };
    const descriptions = {
      check: 'Die vorhandene Akte wird geprüft. Das Ergebnis nennt den Schadenstand, Widersprüche und vorgeschlagene nächste Schritte.',
      invoices: 'Rechnungen und KVA werden gegen den vorhandenen Stand geprüft. Doppelansätze, Zahlungen und ungeklärte Beträge werden getrennt dargestellt.',
      report: 'Ein fachlich zu prüfender Berichtsentwurf wird vorbereitet. Für fertige Dateien steht darunter die bestehende Dokumentauswahl bereit.',
      tasks: 'Fehlende Unterlagen, offene Fragen und nächste Arbeitsschritte werden ermittelt. Es werden keine externen Aufgaben versandt.'
    };
    const preview = () => {
      el('vf-workspace-preview').textContent = `${descriptions[task]} ${flows[flow.value]} ${route.value === 'work' ? 'Es wird ausschließlich eine manuelle Übergabe an ChatGPT Work vorbereitet.' : 'Die KI-Ausführung beginnt erst mit dem Auftrag und wird vorab gegen das Monatsbudget von maximal 30 € geprüft.'}`;
      el('vf-workspace-invoice-tools').hidden = task !== 'invoices';
      el('vf-workspace-report-tools').hidden = task !== 'report';
      start.hidden = (task === 'invoices' && !el('vf-workspace-invoice-host').hidden) || (task === 'report' && !el('vf-workspace-report-host').hidden);
      actions.forEach(button => button.setAttribute('aria-pressed', String(button.dataset.caseAction === task)));
      const handoff = document.querySelector('.vf-processing .vf-work-handoff'), workChoice = el('vf-work-enabled');
      if (handoff && workChoice) {
        const manual = route.value === 'work';
        handoff.classList.toggle('vf-workspace-handoff-active', manual);
        workChoice.closest('label').hidden = true;
        if (workChoice.checked !== manual) { workChoice.checked = manual; workChoice.dispatchEvent(new Event('change')); }
        el('vf-work-content').hidden = !manual;
        el('vf-start').hidden = manual;
      }
    };
    const syncInstruction = () => {
      for (const id of ['vf-analysis-order', 'vf-instructions', 'dr-offer-comment', 'dr-invoice-comment']) {
        const target = el(id); if (!target) continue;
        target.value = instruction.value;
        target.dispatchEvent(new Event('input', { bubbles: true }));
      }
    };
    const move = (node, host) => { if (node && host) host.append(node); };
    const expand = node => {
      if (!node) return;
      node.hidden = false;
      node.classList.remove('is-collapsed');
      node.querySelector(':scope > .vf-collapse-trigger')?.setAttribute('aria-expanded', 'true');
      if (node.tagName === 'DETAILS') node.open = true;
      for (const parent of ancestors(node)) {
        if (parent.tagName === 'DETAILS') parent.open = true;
        parent.classList.remove('is-collapsed');
      }
    };
    function* ancestors(node) { for (let p = node.parentElement; p; p = p.parentElement) yield p; }

    // Compose existing nodes rather than duplicating inputs, listeners or API clients.
    const more = document.createElement('details'); more.id = 'vf-more-functions'; more.className = 'vf-card';
    more.innerHTML = '<summary>Weitere Funktionen</summary><div class="vf-more-links" aria-label="Weitere Funktionen"></div><div id="vf-more-content"></div>';
    panel.after(more);
    const moreContent = el('vf-more-content'), moreLinks = more.querySelector('.vf-more-links');
    const specials = [
      ['E-Mail schreiben', '.vf-mail'],
      ['Ortstermin planen', '.vf-calendar'], ['Falldaten ergänzen', '#vf-form'],
      ['Ortstermin einlesen', '.vf-plaud'], ['Aufmaß auswerten', '.vf-polycam'],
      ['Claimsforce-Notizen', '#vf-claims-notes'], ['Claimsforce-Aufträge', '.vf-claims-import'], ['Rekon-Aufträge', '.vf-rekon-import']
    ];
    for (const [title, selector] of specials) {
      const node = document.querySelector(selector); if (!node) continue;
      move(node, moreContent);
      const b = document.createElement('button'); b.type = 'button'; b.className = 'vf-secondary'; b.textContent = title;
      b.onclick = () => { more.open = true; expand(node); node.scrollIntoView({ block: 'start', behavior: 'smooth' }); };
      moreLinks.append(b);
    }
    // Documents are a primary step; specialist tools stay collapsed under Further functions.
    const documents = document.createElement('details');documents.id='vf-case-documents';documents.className='vf-card';
    documents.innerHTML='<summary>Unterlagen und gespeicherte Dokumente</summary><p class="vf-meta">Originale auswählen, hochladen, speichern oder weitergeben. Die Originaldateien bleiben unverändert.</p>';
    panel.before(documents);move(el('vf-doc-browser'),documents);move(document.querySelector('.vf-upload-card'),documents);
    document.addEventListener('click',event=>{
      const link=event.target.closest('a');if(!link)return;const url=new URL(link.href,location.href);if(url.origin!==location.origin||url.pathname!==location.pathname||!url.hash)return;const node=el(url.hash.slice(1));
      if(node?.id==='vf-case-selection')caseChoice(false);else if(node)expand(node);
    });
    // The file browser remains the existing case-owned component beside the header.
    const review = el('vf-review-group'), processing = document.querySelector('.vf-processing');
    move(review, el('vf-workspace-invoice-host'));
    if (review) review.open = false;
    move(processing, el('vf-workspace-report-host'));
    if (processing) processing.open = false;
    for (const id of ['vf-workspace-invoice', 'vf-workspace-report']) {
      el(id + '-open').onclick = () => {
        el(id + '-host').hidden = false; el(id + '-open').hidden = true;
        syncInstruction(); expand(id.endsWith('invoice') ? review : processing);
        start.hidden = true;
        el('vf-workspace-preview').textContent = id.endsWith('invoice') ? 'Der ausgewählte Originalbeleg wird mit ergänzenden Unterlagen geprüft. Anschließend kontrollieren und speichern Sie die Entscheidung. Versand bleibt ein eigener Schritt.' : 'Die angehakten Dokumente werden mit der gemeinsamen Anweisung über die vorhandene Dokumentverarbeitung erstellt. Mit „Auswahl bearbeiten“ starten Sie diesen Auftrag.';
      };
    }
    // One general instruction only; specific letter/draft/decision fields remain editable.
    for (const id of ['dr-offer-comment', 'dr-invoice-comment']) el(id)?.closest('label')?.classList.add('vf-legacy-instruction');
    move(el('vf-assistant-job-controls'), el('vf-workspace-history-host'));
    move(el('vf-analysis'), el('vf-workspace-legacy-host'));
    const legacy = el('vf-analysis'); if (legacy) legacy.classList.remove('is-collapsed');
    if (legacy) {
      const continueButton = document.createElement('button'); continueButton.type = 'button'; continueButton.className = 'vf-secondary';
      continueButton.textContent = 'Frühere Bearbeitung mit obiger Anweisung fortführen';
      continueButton.onclick = async () => { syncInstruction(); const old = el('vf-analysis-start'); if (opened && old && !old.disabled) await old.onclick(); };
      legacy.append(continueButton);
    }
    // Existing financial preset edits the same shared instruction, never a second visible note.
    el('vf-instructions')?.addEventListener('input', () => {
      if (el('vf-instructions').value !== instruction.value) instruction.value = el('vf-instructions').value;
    });
    instruction.addEventListener('input', () => { lockedInstruction = null; syncInstruction(); });
    actions.forEach(button => button.onclick = () => { task = button.dataset.caseAction; lockedInstruction = null; start.hidden = false; preview();
      if(task==='invoices'&&!opened){el('vf-workspace-invoice-open').click();el('dr-offer').open=true;}
    });
    flow.addEventListener('change', () => { lockedInstruction = null; preview(); });
    route.addEventListener('change', () => { lockedInstruction = null; preview(); });
    const update = () => {
      const control = dispatchers.find(b => b.dataset.assistantTask === task);
      const busy = el('vf-assistant-open').disabled && dispatchers.every(b => b.disabled);
      start.disabled = !opened || !control || control.disabled;
      actions.forEach(b => b.disabled = busy && !!opened);
      flow.disabled = busy && !!opened;
      instruction.disabled = busy && !!opened;
      start.textContent = busy && opened ? 'Auftrag wird bearbeitet …' : 'Auftrag ausführen';
    };
    const observer = new MutationObserver(update);
    for (const b of dispatchers) observer.observe(b, { attributes: true, attributeFilter: ['disabled'] });
    start.onclick = async () => {
      const control = dispatchers.find(b => b.dataset.assistantTask === task);
      if (start.disabled || !control) return;
      const original = instruction.value, startingCase = opened;
      // Keep the exact composed payload on a retry with an uncertain start response.
      lockedInstruction ||= `${flows[flow.value]}\n\n${original}`.trim();
      instruction.value = lockedInstruction;
      try { await control.onclick(); }
      finally { if (opened === startingCase) instruction.value = original; syncInstruction(); update(); }
    };
    const clear = () => {
      opened = ''; lockedInstruction = null; task = 'check'; flow.value = 'review';
      caseChoice(false);
      start.hidden = false;
      instruction.value = ''; syncInstruction();
      for (const id of ['vf-workspace-invoice', 'vf-workspace-report']) {
        el(id + '-host').hidden = true; el(id + '-open').hidden = false;
      }
      if (review) review.open = false;
      if (processing) processing.open = false;
      for (const id of ['dr-offer-standalone', 'dr-invoice-standalone']) { const choice=el(id);if(choice){choice.disabled=false;choice.closest('label').hidden=false;} }
      el('vf-workspace-history').open = false; more.open = false;
      preview(); update();
    };
    window.addEventListener('svnet:case-cleared', clear);
    window.addEventListener('storage', event => { if (['svnet-case', 'svnet-case-owner'].includes(event.key)) clear(); });
    window.addEventListener('svnet:case-opened', event => {
      const current = event.detail;
      if (current?._loaded !== true || !current.folder_id || !current.meta?.schaden_nr) { clear(); return; }
      const identity = JSON.stringify([current.folder_id,current.meta.schaden_nr,current.meta.claimsforce_claim_id,current.meta.claimsforce_profile,current.meta.versicherer,current.meta.sparte]);
      if (opened === identity) return;
      clear(); opened = identity; update();
      caseChoice(true);
      // A personal review must survive opening its destination case. Transfer is explicit.
      // Read-only binding: no start, step, import, notes or paid provider call.
      el('vf-assistant-open').onclick();
    });
    // Keep mail/technical-release deep links usable after moving their panels.
    for (const [id, selector] of [['vf-task-reply', '.vf-mail'], ['vf-task-kva', '#vf-review-group']]) {
      el(id)?.addEventListener('click', () => {
        if (id === 'vf-task-kva') { task = 'invoices'; preview(); el('vf-workspace-invoice-host').hidden = false; }
        expand(document.querySelector(selector));
      });
    }
    el('vf-mail-to')?.addEventListener('focus', () => expand(document.querySelector('.vf-mail')));
    el('vf-mail-body')?.addEventListener('focus', () => expand(document.querySelector('.vf-mail')));
    document.querySelector('a[href="#vf-claims-notes"]')?.addEventListener('click', () => { more.open = true; });

    const diagnosticView = document.createElement('section'); diagnosticView.id = 'vf-diagnostic-view'; diagnosticView.className = 'vf-card';
    diagnosticView.innerHTML = '<h1>Diagnose und Administration</h1><p>Technische Verbindungen und Budgetdetails. Tests und Regelabgleich werden nur per Schaltfläche gestartet.</p><pre id="vf-assistant-technical"></pre>';
    panel.after(diagnosticView);
    move(el('vf-assistant-diagnostic-controls'), diagnosticView);
    el('vf-assistant-diagnostic-controls').hidden = false;
    move(el('vf-assistant-budget'), diagnosticView); el('vf-assistant-budget').hidden = false;
    move(el('vf-system-panel'), diagnosticView);
    diagnosticView.hidden = !diagnostic;
    const footer = document.createElement('nav'); footer.className = 'vf-workspace-footer';
    footer.innerHTML = '<a href="?ansicht=diagnose">Diagnose / Administration</a><a href="/intern/versicherungswissen/">Fachliche Richtlinien und Quellen</a>';
    more.after(footer);
    if (diagnostic) {
      panel.hidden = true; more.hidden = true;documents.hidden=true;
      const navigation = document.querySelector('nav[aria-label="Fallbearbeitung"]'); if (navigation) navigation.hidden = true;
      // Use a true separate view; CSS must not force the normal assistant visible here.
      panel.style.setProperty('display', 'none', 'important');
      const back = document.createElement('a'); back.href = location.pathname; back.textContent = 'Zurück zur Schadenbearbeitung'; diagnosticView.prepend(back);
    }
    preview(); update();
    if(new URLSearchParams(location.search).get('aktion')==='beleg')actions.find(b=>b.dataset.caseAction==='invoices')?.click();
    // Page may already have completed its server-side load before composition.
    const current = window.svnetOpenedClaimsCase;
    if (current?._loaded === true) window.dispatchEvent(new CustomEvent('svnet:case-opened', { detail: current }));
  };
  document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', setup, { once: true }) : setup();
})();
