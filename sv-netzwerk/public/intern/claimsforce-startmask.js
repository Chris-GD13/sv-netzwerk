(() => {
  const mount = () => {
    const target = document.querySelector('#claimsforce-assistant-mount, #vf-assistant-panel');
    if (document.getElementById('sv-claims-startmask') || !target) return;
    const box = document.createElement('section');
    box.id = 'sv-claims-startmask';
    box.className = 'svcm-card';
    box.innerHTML = `
      <style>
        #sv-claims-startmask { max-width: 650px; margin: 18px 0 24px; padding: 22px 20px 18px; border: 1px solid #d7e0ea; border-radius: 18px; background: #fff; box-shadow: 0 8px 24px rgba(24, 55, 82, .11); color: #0f2742; }
        #sv-claims-startmask h2 { margin: 0; font-size: 1.15rem; line-height: 1.25; }
        #sv-claims-startmask .svcm-intro { margin: 4px 0 18px; color: #526d88; font-size: .86rem; }
        #sv-claims-startmask .svcm-fields { display: grid; gap: 13px; }
        #sv-claims-startmask label { display: grid; gap: 6px; font-size: .78rem; font-weight: 700; }
        #sv-claims-startmask input, #sv-claims-startmask select, #sv-claims-startmask textarea { box-sizing: border-box; width: 100%; min-height: 38px; padding: 9px 10px; border: 1px solid #b9c9da; border-radius: 8px; background: #fff; color: #102b46; font: inherit; font-weight: 400; }
        #sv-claims-startmask textarea { min-height: 94px; resize: vertical; }
        #sv-claims-startmask input::placeholder, #sv-claims-startmask textarea::placeholder { color: #8395a8; }
        #sv-claims-startmask .svcm-checks { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px 18px; margin: 18px 0 16px; padding: 13px 10px 12px; border: 1px solid #aebdca; }
        #sv-claims-startmask .svcm-checks legend { padding: 0 5px; color: #173b60; font-size: .86rem; font-weight: 700; }
        #sv-claims-startmask .svcm-checks label { display: flex; align-items: flex-start; gap: 8px; font-size: .8rem; font-weight: 500; }
        #sv-claims-startmask .svcm-checks input { width: 16px; min-height: 16px; margin: 1px 0 0; accent-color: #1976d2; }
        #sv-claims-startmask .svcm-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 9px; }
        #sv-claims-startmask button { min-height: 38px; padding: 9px 14px; border: 0; border-radius: 7px; cursor: pointer; font: inherit; font-weight: 700; }
        #sv-claims-startmask #svcm-start { background: #1976d2; color: #fff; }
        #sv-claims-startmask #svcm-clear { background: #e8eef5; color: #173b60; }
        #sv-claims-startmask .svcm-note { flex-basis: 100%; margin: 2px 0 0; color: #647b92; font-size: .7rem; }
        #sv-claims-startmask #svcm-status { margin: 10px 0 0; color: #42627f; font-size: .76rem; }
        @media (max-width: 640px) { #sv-claims-startmask { margin-inline: 0; padding: 18px 14px 15px; } #sv-claims-startmask .svcm-checks { grid-template-columns: 1fr; } }
      </style>
      <h2>Claimsforce Schadenagent</h2>
      <p class="svcm-intro">Fall auswählen, Auftrag beschreiben und die benötigten Claims-Arbeiten anhaken.</p>
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
        <label for="svcm-case">Schadennummer
          <input id="svcm-case" name="claim_number" type="text" autocomplete="new-password" data-lpignore="true" spellcheck="false" readonly placeholder="z. B. 26-222980-5">
        </label>
        <label for="svcm-task">Was soll in diesem Schaden bearbeitet werden?
          <textarea id="svcm-task" name="claim_task" rows="3" autocomplete="off" data-lpignore="true" placeholder="Zum Beispiel: Erstbericht ausfüllen, alle Anlagen und Fotos prüfen, Reserve und Schadenumfang abgleichen."></textarea>
        </label>
      </div>
      <fieldset class="svcm-checks"><legend>Benötigte Bearbeitung</legend>
        <label><input type="checkbox" value="Erstbericht">Erstbericht</label><label><input type="checkbox" value="Zwischenbericht">Zwischenbericht</label>
        <label><input type="checkbox" value="Schlussbericht">Schlussbericht</label><label><input type="checkbox" value="Schadenprotokoll">Schadenprotokoll</label>
        <label><input type="checkbox" value="SE nativ">SE nativ</label><label><input type="checkbox" value="ZB nativ">ZB nativ</label>
        <label><input type="checkbox" value="Claims-Bericht">Claims-Bericht</label><label><input type="checkbox" value="Anhänge vollständig prüfen">Anhänge vollständig prüfen</label>
        <label><input type="checkbox" value="Outlook-Kalender auslesen">Outlook-Kalender auslesen</label><label><input type="checkbox" value="Richtige Fotos zuordnen">Richtige Fotos zuordnen</label>
        <label><input type="checkbox" value="KVA, Rechnungen, Zahlungen und Reserve prüfen">KVA, Rechnungen, Zahlungen und Reserve prüfen</label><label><input type="checkbox" value="Word in Claimsforce Dateien → Berichte ablegen">Word in Claimsforce Dateien → Berichte ablegen</label>
      </fieldset>
      <div class="svcm-actions"><button id="svcm-start" type="button">Arbeitsauftrag erstellen</button><button id="svcm-clear" type="button">Leeren</button><small class="svcm-note">Die Maske erstellt nur den Arbeitsauftrag. Versand, Zahlung und QS-Übergabe bleiben gesperrt, solange sie nicht ausdrücklich beauftragt werden.</small></div>
      <p id="svcm-status" role="status"></p>`;
    if (target.id === 'vf-assistant-panel') target.parentElement.insertBefore(box, target); else target.appendChild(box);
    const caseInput = box.querySelector('#svcm-case');
    caseInput.addEventListener('focus', () => caseInput.removeAttribute('readonly'), { once: true });
    box.querySelectorAll('input[type="checkbox"]').forEach((el, i) => { if (i >= 7) el.checked = true; });
    box.querySelector('#svcm-clear').addEventListener('click', () => {
      box.querySelector('#svcm-insurer').value = ''; caseInput.value = ''; box.querySelector('#svcm-task').value = ''; box.querySelector('#svcm-status').textContent = '';
      box.querySelectorAll('input[type="checkbox"]').forEach((el, i) => { el.checked = i >= 7; });
    });
    box.querySelector('#svcm-start').addEventListener('click', async () => {
      const profile = box.querySelector('#svcm-profile').value, insurer = box.querySelector('#svcm-insurer').value.trim(), kind = box.querySelector('#svcm-kind').value, caseNo = caseInput.value.trim(), task = box.querySelector('#svcm-task').value.trim();
      if (!caseNo || !task) { box.querySelector('#svcm-status').textContent = 'Bearbeiter, Schadennummer und Arbeitsauftrag ausfüllen.'; return; }
      const outputs = [...box.querySelectorAll('input[type="checkbox"]:checked')].map(x => x.value);
      const prompt = `Claimsforce Schadenagent\n\nBearbeiter / Claimsforce-Konto: ${profile}\nVersicherer: ${insurer || 'nicht angegeben'}\nAuftragstyp: ${kind}\nSchadennummer: ${caseNo}\nArbeitsauftrag: ${task}\n\nAusgewählte Bearbeitung:\n${outputs.map(x => '☐ ' + x).join('\n')}\n\nBearbeite den Fall vollständig nach dem verbindlichen Master-Arbeitsstandard. Lies Prüfportal, Claimsforce, Google Drive, Outlook-Kalender und alle fallbezogenen Anlagen. Verwende die passende Originalvorlage. Prüfe Word sichtbar und lege geprüfte Dateien unter Claimsforce → Dateien → Berichte ab. Nichts versenden, keine Zahlung und keine QS-Übergabe ohne ausdrücklichen Auftrag.`;
      try { await navigator.clipboard.writeText(prompt); } catch {}
      window.open('https://chatgpt.com/plugins/Plugin_f4c7232b44888191bf3092c5a8057fa2', '_blank', 'noopener');
      box.querySelector('#svcm-status').textContent = 'Auftrag kopiert. Der Claimsforce-Agent wurde geöffnet. Im Agenten einfügen und absenden.';
    });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount); else mount();
})();