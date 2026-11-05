(() => {
  const panel = document.getElementById('case-rules-import');
  if (!panel) return;
  const element = id => document.getElementById(id);
  const status = element('case-rules-status');
  const form = element('case-rules-form');
  const results = element('case-rules-results');
  const api = '/intern/api/case-rules-import.php';
  const cleanupPanel = element('case-rules-cleanup');
  const cleanupStatus = element('case-rules-cleanup-status');
  const cleanupResults = element('case-rules-cleanup-results');
  const cleanupApi = '/intern/api/case-rules-cleanup.php';
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
  async function cleanupRequest(options = {}) {
    const { url = cleanupApi, ...fetchOptions } = options;
    const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', ...fetchOptions });
    const data = await response.json();
    if (!response.ok || !data.ok) { const error = new Error(data.error || 'IONOS-Regelbestand konnte nicht geprüft werden.'); error.status = response.status; throw error; }
    return data;
  }
  function item(list, text) {
    const row = document.createElement('li');
    row.textContent = text;
    list.append(row);
  }
  async function refresh() {
    try {
      const data = await request();
      panel.hidden = false;
      status.textContent = `IONOS-Wissensbasis: ${data.root.name} · ${data.files.length} Originale. ${data.message || 'Master vorhanden; Vollständigkeit der weiteren Vorgaben prüfen.'}`;
      element('case-rules-list').replaceChildren();
      for (const file of data.files) item(element('case-rules-list'), file.path);
    } catch (error) {
      if (error.status === 401 || error.status === 403) { panel.hidden = true; return; }
      panel.hidden = false;
      status.textContent = `Bestandsprüfung fehlgeschlagen: ${error.message}`;
    }
  }
  element('case-rules-refresh').onclick = () => refresh();
  async function cleanupDryRun() {
    try {
      const data = await cleanupRequest();
      cleanupPanel.hidden = false;
      cleanupResults.replaceChildren();
      for (const file of data.files) item(cleanupResults, `${file.classification} · ${file.path} · ${file.size} Bytes · ${file.sha256}`);
      cleanupStatus.textContent = `${data.plan.master_count} gleichnamige Masterdatei(en), ${data.plan.canonical_count} kanonisch, ${data.plan.archive_count} zu archivieren.`;
      return data;
    } catch (error) {
      if (error.status === 401 || error.status === 403) { cleanupPanel.hidden = true; return null; }
      cleanupPanel.hidden = false; cleanupStatus.textContent = `Dry-Run fehlgeschlagen: ${error.message}`; return null;
    }
  }
  element('case-rules-cleanup-dry').onclick = () => cleanupDryRun();
  element('case-rules-cleanup-apply').onclick = async () => {
    const file = element('case-rules-master-file').files[0];
    if (!file) { cleanupStatus.textContent = 'Bitte die geprüfte MASTER-ARBEITSSTANDARD.md auswählen.'; return; }
    if (!window.confirm('IONOS-Regelbestand jetzt bereinigen? Alte Fassungen werden nur recoverbar archiviert.')) return;
    const controls = [element('case-rules-cleanup-dry'), element('case-rules-cleanup-apply'), element('case-rules-master-file')];
    controls.forEach(control => { control.disabled = true; });
    try {
      const body = new FormData(); body.append('file', file);
      const data = await cleanupRequest({ url: `${cleanupApi}?action=apply`, method: 'POST', body, });
      cleanupResults.replaceChildren();
      for (const archived of data.archived || []) item(cleanupResults, `Archiviert · ${archived.from} → ${archived.to} · ${archived.sha256}`);
      cleanupStatus.textContent = `Bereinigung bestätigt: genau eine aktive Masterkopie (${data.canonical.sha256}); Release ${data.release.id} rückgelesen.`;
      await cleanupDryRun();
    } catch (error) { cleanupStatus.textContent = `Bereinigung gestoppt: ${error.message}`; }
    finally { controls.forEach(control => { control.disabled = false; }); }
  };
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
  refresh();
  cleanupDryRun();
})();
