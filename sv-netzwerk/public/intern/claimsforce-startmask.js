(() => {
  const STANDARD_INSTRUCTIONS = `Arbeite den Fall vollständig und selbstständig ab. Lies vor jeder Erstellung alle Claimsforce-Bereiche und Unterordner: Beauftragung, Brief, Schadenmeldung, Nachrichten, Bilder, Formulare, Angebote, Rechnungen, Kalkulationen, Weitere Dokumente, Aufgaben und Nacharbeiten. Lies den vollständigen Outlook-Kalendertermin einschließlich Beschreibung, Teilnehmern, Notizen und Anhängen. Gleiche alles mit Prüfportal, Google Drive und den Originalvorlagen ab. Verwende ausschließlich die passende Originalvorlage. Fülle Erstbericht und passendes Schadenprotokoll vollständig aus. Schreibe den konkreten Schadenhergang direkt in den Abschnitt „Schadenhergang/-ursache“. Übernimm alle Claims-Daten, Kontaktdaten, Schadenort, Termine, Teilnehmer, Gebäudedaten, KVA, Zahlungen und Reserve. Ordne fünf richtige Fallbilder eindeutig diesem Fall zu. Erstelle SE und ZB nativ in Claimsforce, fülle sie vollständig, speichere, öffne sie erneut und lies jedes relevante Feld sichtbar zurück. Rendere und prüfe jede Word-Seite sichtbar. Lege die geprüften Word-Dateien unter Claimsforce → Dateien → Berichte ab. Bei einem Fehler die Originalvorlage neu öffnen und den Arbeitsschritt wiederholen, bis alles passt. Nichts versenden, keine Zahlung und keine QS-Übergabe ohne ausdrücklichen Auftrag.`;
  const REQUIRED_OPTIONS = new Set(['Erstbericht', 'Schadenprotokoll', 'SE nativ', 'ZB nativ', 'Anhänge vollständig prüfen', 'Outlook-Kalender auslesen', 'Alle Claimsforce-Ordner lesen', 'Richtige Fotos zuordnen', 'Word-Ablage', 'Offene Nacharbeiten']);
  const normalizeClaimNumber = (value) => String(value || '').match(/\b\d{2,3}(?:-\d{1,7}){2,3}\b/)?.[0] || '';
  const mount = () => {
    const target = document.querySelector('#claimsforce-assistant-mount, #vf-assistant-panel');
    if (document.getElementById('sv-claims-startmask') || !target) return;
    const box = document.createElement('section');
    box.id = 'sv-claims-startmask';
    box.className = 'svcm-card';
    box.innerHTML = `
      <style>
        #sv-claims-startmask { max-width: 900px; margin: 18px 0 24px; padding: 22px 20px 18px; border: 1px solid #d7e0ea; border-radius: 18px; background: #fff; box-shadow: 0 8px 24px rgba(24, 55, 82, .11); color: #0f2742; }
        #sv-claims-startmask h2 { margin: 0; font-size: 1.15rem; line-height: 1.25; }
        #sv-claims-startmask .svcm-intro { margin: 4px 0 18px; color: #526d88; font-size: .86rem; }
        #sv-claims-startmask .svcm-layout { display: grid; grid-template-columns: minmax(0, 1.1fr) minmax(280px, .9fr); gap: 22px; align-items: start; }
        #sv-claims-startmask .svcm-fields { display: grid; gap: 13px; }
        #sv-claims-startmask label { display: grid; gap: 6px; font-size: .78rem; font-weight: 700; }
        #sv-claims-startmask input, #sv-claims-startmask select, #sv-claims-startmask textarea { box-sizing: border-box; width: 100%; min-height: 38px; padding: 9px 10px; border: 1px solid #b9c9da; border-radius: 8px; background: #fff; color: #102b46; font: inherit; font-weight: 400; }
        #sv-claims-startmask textarea { min-height: 94px; resize: vertical; }
        #sv-claims-startmask input::placeholder, #sv-claims-startmask textarea::placeholder { color: #8395a8; }
        #sv-claims-startmask .svcm-checks { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px 18px; margin: 18px 0 16px; padding: 13px 10px 12px; border: 1px solid #aebdca; }
        #sv-claims-startmask .svcm-checks legend { padding: 0 5px; color: #173b60; font-size: .86rem; font-weight: 700; }
        #sv-claims-startmask .svcm-checks label { display: flex; align-items: flex-start; gap: 8px; font-size: .8rem; font-weight: 500; }
        #sv-claims-startmask .svcm-checks input { width: 16px; min-height: 16px; margin: 1px 0 0; accent-color: #1976d2; }
        #sv-claims-startmask .svcm-actions, #sv-claims-startmask .svcm-output-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 9px; }
        #sv-claims-startmask button { min-height: 38px; padding: 9px 14px; border: 0; border-radius: 7px; cursor: pointer; font: inherit; font-weight: 700; }
        #sv-claims-startmask #svcm-start, #sv-claims-startmask #svcm-copy { background: #1976d2; color: #fff; }
        #sv-claims-startmask #svcm-clear, #sv-claims-startmask #svcm-open { background: #e8eef5; color: #173b60; }
        #sv-claims-startmask .svcm-note { flex-basis: 100%; margin: 2px 0 0; color: #647b92; font-size: .7rem; }
        #sv-claims-startmask #svcm-status { margin: 10px 0 0; color: #42627f; font-size: .76rem; }
        #sv-claims-startmask .svcm-output { padding: 14px; border: 1px solid #c7d5e2; border-radius: 12px; background: #f4f8fb; }
        #sv-claims-startmask .svcm-output label { color: #173b60; }
        #sv-claims-startmask #svcm-prompt { min-height: 360px; line-height: 1.45; background: #fff; }
        #sv-claims-startmask .svcm-output-help { margin: 6px 0 12px; color: #647b92; font-size: .74rem; line-height: 1.4; }
        @media (max-width: 760px) { #sv-claims-startmask { margin-inline: 0; padding: 18px 14px 15px; } #sv-claims-startmask .svcm-layout { grid-template-columns: 1fr; gap: 16px; } #sv-claims-startmask .svcm-checks { grid-template-columns: 1fr; } #sv-claims-startmask #svcm-prompt { min-height: 260px; } }
      </style>
      <h2>Claimsforce Schadenagent</h2>
      <p class="svcm-intro">Fall auswählen, Auftrag beschreiben und die benötigten Claims-Arbeiten anhaken.</p>
      <div class="svcm-layout">
        <div class="svcm-form">
          <div class="svcm-fields">
            <label for="svcm-profile">Bearbeiter / Claimsforce-Konto
              <select id="svcm-profile" name="claimsforce_profile" autocomplete="off"><option value="Christian · Standardfälle">Christian · Standardfälle</option><option value="Christian · Maurer-Fälle">Christian · Maurer-Fälle</option><option value="Marc Schütt">Marc Schütt</option><option value="Holger Roth">Holger Roth</option><option value="Susanne Wächter">Susanne Wächter</option><option value="Katja Schäfer">Katja Schäfer</option></select>
            </label>
            <label for="svcm-insurer">Versicherer
              <input id="svcm-insurer" name="insurer_name" type="text" autocomplete="off" data-lpignore="true" placeholder="z. B. Sparkassenversicherung, Württembergische, R+V">
            </label>
            <label for="svcm-kind">Auftragstyp
              <select id="svcm-kind" name="order_type" autocomplete="off"><option>Normaler Versicherungsauftrag</option><option>GF / TaskForce / Großschaden</option><option>Hausrat</option><option>Haftpflicht</option><option>Elementar</option><option>Sonstiger Auftrag</option></select>
            </label>
            <label for="svcm-claim-number">Schadennummer
              <input id="svcm-claim-number" name="damage_reference" type="search" autocomplete="off" data-lpignore="true" spellcheck="false" readonly placeholder="z. B. 26-222980-5">
            </label>
            <label for="svcm-task">Was soll in diesem Schaden bearbeitet werden?
              <textarea id="svcm-task" name="claim_task" rows="9" autocomplete="off" data-lpignore="true"></textarea>
            </label>
          </div>
          <fieldset class="svcm-checks"><legend>Benötigte Bearbeitung</legend>
            <label><input type="checkbox" value="Erstbericht">Erstbericht</label><label><input type="checkbox" value="Zwischenbericht">Zwischenbericht</label>
            <label><input type="checkbox" value="Schlussbericht">Schlussbericht</label><label><input type="checkbox" value="Schadenprotokoll">Schadenprotokoll</label>
            <label><input type="checkbox" value="SE nativ">SE nativ</label><label><input type="checkbox" value="ZB nativ">ZB nativ</label>
            <label><input type="checkbox" value="Claims-Bericht">Claims-Bericht</label><label><input type="checkbox" value="Anhänge vollständig prüfen">Anhänge vollständig prüfen</label>
            <label><input type="checkbox" value="Outlook-Kalender auslesen">Outlook-Kalender auslesen</label><label><input type="checkbox" value="Alle Claimsforce-Ordner lesen">Alle Claimsforce-Ordner lesen</label>
            <label><input type="checkbox" value="Richtige Fotos zuordnen">Richtige Fotos zuordnen</label><label><input type="checkbox" value="Word-Ablage">Word-Ablage</label>
            <label><input type="checkbox" value="Offene Nacharbeiten">Offene Nacharbeiten</label><label><input type="checkbox" value="KVA, Rechnungen, Zahlungen und Reserve prüfen">KVA, Rechnungen, Zahlungen und Reserve prüfen</label>
          </fieldset>
          <div class="svcm-actions"><button id="svcm-start" type="button">Arbeitsauftrag erstellen</button><button id="svcm-clear" type="button">Leeren</button><small class="svcm-note">Der Text rechts bleibt editierbar. Versand, Zahlung und QS-Übergabe bleiben gesperrt, solange sie nicht ausdrücklich beauftragt werden.</small></div>
        </div>
        <aside class="svcm-output">
          <label for="svcm-prompt">Ausgabe für den Claimsforce-Agenten</label>
          <p class="svcm-output-help">Nach „Arbeitsauftrag erstellen“ erscheint hier der vollständige Text. Du kannst ihn ändern, kopieren und im Agenten einfügen.</p>
          <textarea id="svcm-prompt" autocomplete="off" spellcheck="false" placeholder="Der erzeugte Arbeitsauftrag erscheint hier …"></textarea>
          <div class="svcm-output-actions"><button id="svcm-copy" type="button">Text kopieren</button><button id="svcm-open" type="button">Agent öffnen</button></div>
          <p id="svcm-status" role="status"></p>
        </aside>
      </div>`;
    if (target.id === 'vf-assistant-panel') target.parentElement.insertBefore(box, target); else target.appendChild(box);
    const caseInput = box.querySelector('#svcm-claim-number'), taskInput = box.querySelector('#svcm-task'), prompt = box.querySelector('#svcm-prompt');
    taskInput.value = STANDARD_INSTRUCTIONS;
    caseInput.addEventListener('focus', () => caseInput.removeAttribute('readonly'), { once: true });
    box.querySelectorAll('input[type="checkbox"]').forEach((el) => { el.checked = REQUIRED_OPTIONS.has(el.value); if (el.checked) el.dataset.required = 'true'; el.addEventListener('change', () => { if (el.dataset.required === 'true') el.checked = true; }); });
    box.querySelector('#svcm-clear').addEventListener('click', () => {
      box.querySelector('#svcm-insurer').value = ''; caseInput.value = ''; taskInput.value = STANDARD_INSTRUCTIONS; prompt.value = ''; box.querySelector('#svcm-status').textContent = '';
      box.querySelectorAll('input[type="checkbox"]').forEach((el) => { el.checked = REQUIRED_OPTIONS.has(el.value); });
    });
    const buildPrompt = () => {
      const profile = box.querySelector('#svcm-profile').value, insurer = box.querySelector('#svcm-insurer').value.trim(), kind = box.querySelector('#svcm-kind').value, caseNo = normalizeClaimNumber(caseInput.value), enteredTask = taskInput.value.trim();
      if (caseNo) caseInput.value = caseNo;
      if (!caseNo) { box.querySelector('#svcm-status').textContent = 'Eine echte Schadennummer im Format 26-222980-5 eingeben.'; return ''; }
      const extra = enteredTask === STANDARD_INSTRUCTIONS ? '' : enteredTask.replace(STANDARD_INSTRUCTIONS, '').trim();
      const instructions = [STANDARD_INSTRUCTIONS, extra ? `Zusätzliche fallbezogene Hinweise:\n${extra}` : ''].filter(Boolean).join('\n\n');
      const choices = [...box.querySelectorAll('input[type="checkbox"]')].map(x => `${x.checked ? '☒' : '☐'} ${x.value}`).join('\n');
      return `ARBEITSAUFTRAG FÜR DEN CLAIMSFORCE-AGENTEN\n\nBearbeiter / Claimsforce-Konto: ${profile}\nVersicherer: ${insurer || 'nicht angegeben'}\nAuftragstyp: ${kind}\nSchadennummer: ${caseNo}\n\nVERBINDLICHE STANDARDARBEITSANWEISUNG UND HINWEISE:\n${instructions}\n\nAUSGEWÄHLTE BEARBEITUNG:\n${choices}\n\nDiesen Arbeitsauftrag vollständig ausführen und die Ergebnisse als Entwurf zurückgeben. Nichts versenden, keine Zahlung und keine QS-Übergabe ohne ausdrücklichen Auftrag.`;
    };    box.querySelector('#svcm-start').addEventListener('click', () => {
      const text = buildPrompt();
      if (!text) return;
      prompt.value = text;
      box.querySelector('#svcm-status').textContent = 'Arbeitsauftrag erstellt. Rechts prüfen, bearbeiten oder kopieren.';
      prompt.focus();
    });
    box.querySelector('#svcm-copy').addEventListener('click', async () => {
      if (!prompt.value.trim()) { box.querySelector('#svcm-status').textContent = 'Bitte zuerst den Arbeitsauftrag erstellen.'; return; }
      try { await navigator.clipboard.writeText(prompt.value); box.querySelector('#svcm-status').textContent = 'Text kopiert. Jetzt im Agenten einfügen.'; } catch { box.querySelector('#svcm-status').textContent = 'Kopieren wurde vom Browser blockiert. Text bitte manuell markieren.'; }
    });
    box.querySelector('#svcm-open').addEventListener('click', () => {
      if (!prompt.value.trim()) { box.querySelector('#svcm-status').textContent = 'Bitte zuerst den Arbeitsauftrag erstellen.'; return; }
      window.open('https://chatgpt.com/plugins/Plugin_f4c7232b44888191bf3092c5a8057fa2', '_blank', 'noopener');
      box.querySelector('#svcm-status').textContent = 'Agent geöffnet. Den kopierten Text dort einfügen.';
    });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount); else mount();
})();
