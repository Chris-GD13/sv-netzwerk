(() => {
  const button = document.getElementById('vf-kuss-scan');
  const state = document.getElementById('vf-kuss-state');
  if (!button || !state) return;
  const panel = document.createElement('div');
  panel.hidden = true;
  const search = document.createElement('input');
  search.type = 'search'; search.placeholder = 'KUSS: Schadennummer oder Suchtext';
  search.setAttribute('aria-label', 'KUSS-Aufträge durchsuchen'); search.maxLength = 120;
  const searchButton = document.createElement('button');
  searchButton.type = 'button'; searchButton.textContent = 'KUSS durchsuchen'; searchButton.className = 'vf-secondary';
  const list = document.createElement('ul');
  const previous = document.createElement('button'), next = document.createElement('button');
  for (const control of [previous, next]) { control.type = 'button'; control.className = 'vf-secondary'; }
  previous.textContent = 'Vorige Seite'; next.textContent = 'Nächste Seite';
  panel.append(search, searchButton, list, previous, next); state.after(panel);
  const settingsButton = document.createElement('button');
  settingsButton.type = 'button'; settingsButton.className = 'vf-secondary'; settingsButton.textContent = 'KUSS-Verbindung verwalten';
  const settings = document.createElement('div'); settings.hidden = true;
  const tokenLabel = document.createElement('label'); tokenLabel.textContent = 'KUSS-API-Token';
  const tokenInput = document.createElement('input'); tokenInput.type = 'password'; tokenInput.autocomplete = 'off'; tokenInput.maxLength = 12000;
  tokenLabel.append(tokenInput);
  const save = document.createElement('button'); save.type = 'button'; save.className = 'vf-secondary'; save.textContent = 'Verbindung speichern';
  const detail = document.createElement('p'); detail.className = 'vf-meta'; detail.setAttribute('aria-live', 'polite');
  settings.append(tokenLabel, save, detail); state.after(settingsButton, settings);
  let csrf = '';
  async function openSettings() {
    settings.hidden = false; detail.textContent = 'Verbindung wird geprüft …';
    try {
      const response = await fetch('/intern/api/kuss.php?action=status', { credentials: 'same-origin', cache: 'no-store' });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || 'Die Verbindung konnte nicht geprüft werden.');
      csrf = data.csrf;
      detail.textContent = data.connected ? `Verbunden. Token gültig bis ${data.expires_at} UTC. Zum Erneuern einen neuen Token eintragen.` : 'Für deine Anmeldung einen KUSS-API-Token eintragen.';
    } catch (error) { detail.textContent = error.message; }
  }
  settingsButton.addEventListener('click', openSettings);
  save.addEventListener('click', async () => {
    if (!tokenInput.value.trim()) { detail.textContent = 'Bitte einen KUSS-API-Token eintragen.'; return; }
    save.disabled = true;
    try {
      if (!csrf) await openSettings();
      if (!csrf) return;
      const response = await fetch('/intern/api/kuss.php?action=connect', { method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: { 'Content-Type': 'application/json', 'X-Kuss-CSRF': csrf }, body: JSON.stringify({ token: tokenInput.value.trim() }) });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || 'Die Verbindung konnte nicht gespeichert werden.');
      tokenInput.value = ''; detail.textContent = `Verbunden. Token gültig bis ${data.expires_at} UTC.`;
      await read(1, '');
    } catch (error) { detail.textContent = error.message; }
    finally { save.disabled = false; }
  });
  let page = 1, activeSearch = '', busy = false;
  async function read(target = 1, query = activeSearch) {
    if (busy) return;
    busy = true; button.disabled = previous.disabled = next.disabled = searchButton.disabled = true;
    list.replaceChildren(); state.textContent = 'KUSS-Aufträge werden über die API gelesen …';
    try {
      const params = new URLSearchParams({ action: 'jobs', page: String(target), search: query });
      const response = await fetch(`/intern/api/kuss.php?${params}`, { credentials: 'same-origin', cache: 'no-store' });
      const data = await response.json();
      if (response.status === 409) await openSettings();
      if (!response.ok || !data.ok) throw new Error(data.error || 'KUSS konnte nicht gelesen werden.');
      page = data.page.current; activeSearch = query;
      state.textContent = `KUSS-API verbunden. ${data.page.total} Aufträge${query ? ' zur Suche' : ' im freigegebenen Firmenbestand'}. Seite ${page} von ${data.page.last}. Es wurden keine Fälle importiert.`;
      for (const job of data.jobs) {
        const item = document.createElement('li');
        item.textContent = [job.case, job.reference, job.subject, job.state].filter(Boolean).join(' · '); list.append(item);
      }
      panel.hidden = false; previous.disabled = page <= 1; next.disabled = page >= data.page.last;
    } catch (error) { panel.hidden = true; state.textContent = error.message || 'KUSS konnte nicht gelesen werden.';
    } finally { busy = false; button.disabled = searchButton.disabled = false; }
  }
  button.addEventListener('click', () => read(1, ''));
  searchButton.addEventListener('click', () => read(1, search.value.trim()));
  search.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); read(1, search.value.trim()); } });
  previous.addEventListener('click', () => read(page - 1)); next.addEventListener('click', () => read(page + 1));
})();
