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
  let page = 1, activeSearch = '', busy = false;
  async function read(target = 1, query = activeSearch) {
    if (busy) return;
    busy = true; button.disabled = previous.disabled = next.disabled = searchButton.disabled = true;
    list.replaceChildren(); state.textContent = 'KUSS-Aufträge werden über die API gelesen …';
    try {
      const params = new URLSearchParams({ action: 'jobs', page: String(target), search: query });
      const response = await fetch(`/intern/api/kuss.php?${params}`, { credentials: 'same-origin', cache: 'no-store' });
      const data = await response.json();
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
