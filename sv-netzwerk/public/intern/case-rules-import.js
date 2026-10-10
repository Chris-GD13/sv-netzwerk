(() => {
  const panel = document.getElementById('case-rules-import');
  if (!panel) return;
  const element = id => document.getElementById(id);
  const status = element('case-rules-status');
  const form = element('case-rules-form');
  const results = element('case-rules-results');
  const api = '/intern/api/case-rules-import.php';
  async function request(options = {}) {
    const response = await fetch(api, { credentials: 'same-origin', cache: 'no-store', ...options });
    const data = await response.json();
    if (!response.ok || !data.ok) {
      const error = new Error(data.error || 'IONOS-Import konnte nicht geprüft werden.');
      error.status = response.status;
      throw error;
    }
    return data;
  }
  function item(list, text) {
    const row = document.createElement('li');
    row.textContent = text;
    list.append(row);
  }
  async function refresh(initial = false) {
    try {
      const data = await request();
      panel.hidden = false;
      status.textContent = `IONOS-Wissensbasis: ${data.root.name} · ${data.files.length} Originale. ${data.message || 'Master vorhanden; Vollständigkeit der weiteren Vorgaben prüfen.'}`;
      element('case-rules-list').replaceChildren();
      for (const file of data.files) item(element('case-rules-list'), file.path);
    } catch (error) {
      if (initial && error.status === 403) return;
      panel.hidden = false;
      status.textContent = `Bestandsprüfung fehlgeschlagen: ${error.message}`;
    }
  }
  element('case-rules-refresh').onclick = () => refresh();
  form.onsubmit = async event => {
    event.preventDefault();
    const files = [...element('case-rules-files').files];
    if (!files.length) { status.textContent = 'Bitte Originaldateien auswählen.'; return; }
    const target = element('case-rules-target').value;
    const replace = element('case-rules-replace').checked;
    if (replace && !window.confirm('Andere gleichnamige Originalfassungen ersetzen? Vorversionen werden gesichert.')) return;
    results.replaceChildren();
    const controls = [...form.querySelectorAll('input, select, button')];
    controls.forEach(control => { control.disabled = true; });
    let saved = 0;
    try {
      for (const file of files) {
        status.textContent = `IONOS-Import: ${file.name}`;
        if (file.size > 30 * 1024 * 1024) throw new Error(`${file.name}: größer als 30 MB.`);
        const body = new FormData();
        body.append('file', file);
        body.append('target', target);
        body.append('replace', replace ? '1' : '0');
        const data = await request({ method: 'POST', body });
        saved++;
        item(results, `${data.file.name}: ${data.file.duplicate ? 'identisches Original bereits vorhanden' : 'auf IONOS gespeichert und SHA-256 geprüft'}`);
      }
      element('case-rules-files').value = '';
      await refresh();
      item(results, `${saved} Originale bestätigt. Die Fallanalyse kann nach Prüfung des vollständigen Vorgabenbestands neu gestartet werden.`);
    } catch (error) {
      status.textContent = `Import gestoppt: ${error.message} ${saved} vorherige Originale sind bereits bestätigt; verbleibende Dateien wurden nicht importiert.`;
    } finally {
      controls.forEach(control => { control.disabled = false; });
    }
  };
  refresh(true);
})();
