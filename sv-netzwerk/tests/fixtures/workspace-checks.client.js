/* Run from the local fixture button. Real DOM/controllers, real PHP boundary,
 * synthetic DB/model only. No browser automation dependency or remote service. */
document.addEventListener('DOMContentLoaded', () => {
  const test = document.createElement('button'); test.id = 'fixture-check'; test.textContent = 'Synthetische Oberflächenprüfung';
  const output = document.createElement('pre'); output.id = 'fixture-checks'; output.setAttribute('role', 'status');
  document.body.prepend(test, output);
  const el = id => document.getElementById(id), logs = [];
  const check = (condition, label) => { if (!condition) throw Error(label); logs.push('PASS ' + label); output.textContent = logs.join('\n'); };
  const wait = async predicate => { for(let i=0;i<100;i++){ if(predicate())return; await new Promise(r=>setTimeout(r,40)); }throw Error('Timeout'); };
  const journal = () => el('fixture-requests').textContent;
  test.onclick = async () => {
    test.disabled = true; logs.length = 0;
    try {
      check(!/assistant_start|assistant_step|notes|document-review/.test(journal()), 'Öffnen der Seite startet weder Modell noch Notiz- oder Belegabruf');
      check(document.querySelectorAll('[data-case-action]').length===4,'Genau vier Hauptaktionen');
      check(!!el('vf-case-documents')&&!!document.querySelector('nav[aria-label="Fallbearbeitung"] a[href="#vf-case-documents"]'),'Unterlagen als eigener klarer Navigationsschritt');
      check(!el('vf-more-functions').open,'Weitere Funktionen anfangs geschlossen');
      check(el('vf-workspace-start').disabled,'Ohne Fall kein Auftrag');
      el('fixture-a').click(); await wait(()=>el('vf-assistant-context').textContent.includes('serverseitig bestätigt'));
      check(/TEST-A-001.*DEMO-SV.*Gebäude.*Christian Wächter.*4 vorhandene/.test(el('vf-assistant-context').textContent),'Identität, Profil, Sparte, Versicherer und Dokumentzahl kommen vom Server');
      check(el('vf-assistant-context').textContent.includes('synthetic-r1'),'Freigegebener Regelstand sichtbar');
      const calls = journal();
      for(const b of document.querySelectorAll('[data-case-action]'))b.click();
      check(journal()===calls,'Aktionswechsel erzeugt keine Ausführung oder Kosten');
      document.querySelector('[data-case-action="invoices"]').click();
      el('vf-workspace-flow').value='supplement';el('vf-workspace-flow').dispatchEvent(new Event('change'));
      check(el('vf-workspace-preview').textContent.includes('Neue Belege'),'Vorauswahl erklärt den Nachtragsablauf');
      el('vf-assistant-order').value='Synthetische Zusatzanweisung';el('vf-assistant-order').dispatchEvent(new Event('input'));
      check(el('vf-instructions').value===el('vf-assistant-order').value && el('dr-offer-comment').value===el('vf-assistant-order').value,'Ein Auftragstext erreicht Beleg- und Dokumentwerkzeuge');
      el('vf-workspace-start').click(); await wait(()=>el('vf-assistant-state').textContent.startsWith('Auswertung abgeschlossen'));
      check(journal().split('assistant_start').length-1===1,'Ein expliziter Start erzeugt genau einen Auftrag');
      check(el('vf-assistant-results').textContent.includes('1200.00 EUR'),'Ersetzter KVA und Dublette werden nicht doppelt berechnet');
      check(el('vf-assistant-results').textContent.includes('Offene Fragen') && el('vf-assistant-results').textContent.includes('Vorgeschlagene Maßnahmen'),'Ergebnis, Fragen und Maßnahmen getrennt sichtbar');
      check(!/Auftrag \d+|input_tokens|source_hash|token-/.test(el('vf-assistant-state').textContent+el('vf-assistant-results').textContent),'Keine technischen Auftrags- oder Verbrauchsdaten im normalen Ergebnis');
      check(el('vf-assistant-order').value==='Synthetische Zusatzanweisung','Ablaufhinweis überschreibt nicht die Benutzereingabe');
      await wait(()=>el('vf-assistant-results').querySelectorAll('[data-output-action]').length===4);
      check(true,'Einheitliche E-Mail-, Druck-, Speicher- und Teilenaktionen am Ergebnis');
      el('vf-assistant-results').querySelector('[data-output-action="email"]').click();
      await wait(()=>el('vf-mail-files').files.length===1);
      const exported=await el('vf-mail-files').files[0].text();
      check(exported.includes('data:image/jpeg;base64,')&&exported.includes('1200.00 EUR'),'Offlinefähige Ausgabe mit echtem Logo und vollständigem Ergebnis');
      check(!exported.includes('data-output-action')&&!exported.includes('<script'),'Keine Bedienfelder oder Skripte in der Ausgabe');
      check(el('vf-mail-subject').value.includes('TEST-A-001')&&!/send/.test(journal()),'E-Mail mit Anhang vorbereitet, ohne Versand');
      el('vf-mail-files').value='';el('vf-mail-subject').value='';el('vf-mail-body').value='';
      el('vf-workspace-history').open=true;const stepsBefore=journal().split('assistant_step').length;
      el('vf-assistant-history').click();await wait(()=>el('vf-workspace-history-list').children.length>0&&!el('vf-assistant-history').disabled);
      check(el('vf-workspace-history-list').children.length>0,'Gespeicherte Bearbeitungen bleiben einzeln erreichbar');
      check(journal().split('assistant_step').length===stepsBefore,'Verlauflesen startet keine erneute Auswertung');
      el('vf-workspace-history').open=false;
      document.querySelector('[data-case-action="report"]').click();el('vf-workspace-report-open').click();
      check(document.querySelector('.vf-processing').closest('#vf-workspace-report-host'),'Originale Dokumentauswahl im zentralen Berichtskontext');
      check(el('vf-instructions').getClientRects().length===0,'Keine zweite sichtbare Berichtsanweisung');
      check(el('vf-workspace-start').hidden,'Kein konkurrierender Start neben dem konkreten Dokumentauftrag');
      document.querySelector('[data-case-action="tasks"]').click();
      check(el('vf-workspace-report-tools').hidden && !el('vf-workspace-start').hidden,'Aufgaben blenden Dokumentdetails aus');
      el('vf-assistant-route').value='work';el('vf-assistant-route').dispatchEvent(new Event('change'));
      check(el('vf-workspace-preview').textContent.includes('ausschließlich eine manuelle Übergabe'),'Work-Vorschau kündigt ausschließlich manuelle Übergabe an');
      el('vf-workspace-start').click();await wait(()=>el('vf-assistant-state').textContent==='Manuelle Übergabe vorbereitet.');
      check(el('vf-assistant-results').textContent.includes('Übergabe für Work kopieren')&&!el('vf-assistant-results').textContent.includes('return_guard'),'Work-Übergabe ohne technische Rohdaten in der Fallansicht');
      el('vf-assistant-cancel').click();await wait(()=>el('vf-assistant-state').textContent==='Auftrag beendet.');
      check(!el('vf-assistant-history').disabled,'Work-Übergabe lässt sich kontrolliert beenden');
      el('fixture-b').click();await wait(()=>el('vf-assistant-context').textContent.includes('TEST-B-002')&&el('vf-assistant-context').textContent.includes('serverseitig bestätigt'));
      check(el('vf-assistant-results').children.length===0 && el('vf-assistant-order').value==='','Fallwechsel löscht alte Ergebnisse und Anweisung');
      check(el('vf-assistant-context').textContent.includes('Jens Maurer'),'Serverprofil ersetzt keinen Fall durch das Browserprofil');
      check(!/notes|claimsforce|import|send/.test(journal()),'Keine Notizen, Claimsforce-Importe oder Nachrichten durch die Prüfung');
      check(el('vf-diagnostic-view').hidden,'Technik nur in separater Diagnoseansicht');
      check(document.documentElement.scrollWidth<=document.documentElement.clientWidth,'Kein horizontaler Seitenüberlauf bei '+document.documentElement.clientWidth+'px');
      el('fixture-a').click();el('fixture-b').click();await wait(()=>el('vfdb-list').textContent.includes('Testbeleg-B.pdf'));
      await new Promise(r=>setTimeout(r,350));
      check(!el('vfdb-list').textContent.includes('Testbeleg-A.pdf'),'Verspätete Dokumentenliste überschreibt keinen anderen Schaden');
      el('fixture-clear').click();check(el('vf-workspace-start').disabled,'Fallschließen sperrt den Auftrag sofort');
      check(el('vf-doc-browser').hidden&&el('vfdb-list').children.length===0,'Dokumentenliste wird bei Fallschließen geleert und gesperrt');
      check(el('vf-assistant-state').textContent==='Bitte zuerst einen Fall öffnen.','Kein alter Erfolgsstatus nach dem Fallschließen');
      output.textContent = logs.join('\n')+'\nTOTAL '+logs.length+' Prüfungen; ausschließlich synthetisch, externe KI-Kosten 0 EUR';
    } catch(error) { output.textContent=logs.join('\n')+'\nFAIL '+error.message; }
    finally {test.disabled=false;}
  };
});
