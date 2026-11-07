(() => {
  const mount = () => {
    if (document.getElementById('sv-claims-startmask') || !document.querySelector('#vf-assistant-panel')) return;
    const box = document.createElement('section');
    box.id = 'sv-claims-startmask';
    box.className = 'vf-card';
    box.innerHTML = `
      <header class="vf-step"><b>0</b><div><strong>Claimsforce Auftrag starten</strong><small>Für normale Aufträge und GF-Fälle</small></div></header>
      <div class="vf-startmask-grid">
        <label>Versicherer<input id="svcm-insurer" placeholder="Sparkasse, Württembergische, R+V …"></label>
        <label>Auftragstyp<select id="svcm-kind"><option>Normaler Versicherungsauftrag</option><option>GF / TaskForce / Großschaden</option><option>Hausrat</option><option>Haftpflicht</option><option>Elementar</option><option>Sonstiger Auftrag</option></select></label>
        <label class="wide">Schadennummer<input id="svcm-case" placeholder="z. B. 26-222980-5"></label>
        <label class="wide">Was soll im Schaden bearbeitet werden?<textarea id="svcm-task" rows="3" placeholder="Arbeitsauftrag"></textarea></label>
      </div>
      <fieldset class="svcm-checks"><legend>Benötigte Bearbeitung</legend>
        <label><input type="checkbox" value="Erstbericht">Erstbericht</label>
        <label><input type="checkbox" value="Zwischenbericht">Zwischenbericht</label>
        <label><input type="checkbox" value="Schadenprotokoll">Schadenprotokoll</label>
        <label><input type="checkbox" value="Schlussbericht">Schlussbericht</label>
        <label><input type="checkbox" value="SE nativ">SE nativ</label>
        <label><input type="checkbox" value="ZB nativ">ZB nativ</label>
        <label><input type="checkbox" value="Claims-Bericht">Claims-Bericht</label>
        <label><input type="checkbox" value="Anhänge vollständig prüfen">Anhänge prüfen</label>
        <label><input type="checkbox" value="Outlook-Kalender auslesen">Kalender auslesen</label>
        <label><input type="checkbox" value="KVA, Rechnungen, Zahlungen und Reserve prüfen">Beträge prüfen</label>
        <label><input type="checkbox" value="Richtige Fotos zuordnen">Fotos zuordnen</label>
        <label><input type="checkbox" value="Word in Claimsforce Dateien → Berichte ablegen">In Berichte ablegen</label>
      </fieldset>
      <button id="svcm-start" class="vf-primary" type="button">Auftrag erstellen und Agent öffnen</button>
      <p id="svcm-status" class="vf-meta" role="status"></p>`;
    const panel = document.querySelector('#vf-assistant-panel');
    panel.parentElement.insertBefore(box, panel);
    box.querySelectorAll('input[type="checkbox"]').forEach((el, i) => { if (i >= 7) el.checked = true; });
    box.querySelector('#svcm-start').addEventListener('click', async () => {
      const insurer = box.querySelector('#svcm-insurer').value.trim();
      const kind = box.querySelector('#svcm-kind').value;
      const caseNo = box.querySelector('#svcm-case').value.trim();
      const task = box.querySelector('#svcm-task').value.trim();
      if (!caseNo || !task) { box.querySelector('#svcm-status').textContent = 'Schadennummer und Arbeitsauftrag ausfüllen.'; return; }
      const outputs = [...box.querySelectorAll('input[type="checkbox"]:checked')].map(x => x.value);
      const prompt = `Claimsforce Schadenagent

Versicherer: ${insurer || 'nicht angegeben'}
Auftragstyp: ${kind}
Schadennummer: ${caseNo}
Arbeitsauftrag: ${task}

Ausgewählte Bearbeitung:
${outputs.map(x => '☐ ' + x).join('\n')}

Bearbeite den Fall vollständig nach dem verbindlichen Master-Arbeitsstandard. Lies Prüfportal, Claimsforce, Google Drive, Outlook-Kalender und alle fallbezogenen Anlagen. Verwende die passende Originalvorlage. Prüfe Word sichtbar und lege geprüfte Dateien unter Claimsforce → Dateien → Berichte ab. Nichts versenden, keine Zahlung und keine QS-Übergabe ohne ausdrücklichen Auftrag.`;
      try { await navigator.clipboard.writeText(prompt); } catch {}
      window.open('https://chatgpt.com/plugins/Plugin_f4c7232b44888191bf3092c5a8057fa2', '_blank', 'noopener');
      box.querySelector('#svcm-status').textContent = 'Auftrag kopiert. Der Claimsforce-Agent wurde geöffnet. Im Agenten einfügen und absenden.';
    });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount); else mount();
})();