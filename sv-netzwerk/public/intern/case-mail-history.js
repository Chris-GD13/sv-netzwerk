(() => {
  const API = '/intern/api/google-drive-sync.php';
  const BROWSER = '/intern/api/case-file-browser.php';
  const input = document.getElementById('vf-case-mail-files');
  const drop = document.getElementById('vf-case-mail-drop');
  const refresh = document.getElementById('vf-case-mail-refresh');
  const state = document.getElementById('vf-case-mail-state');
  const list = document.getElementById('vf-case-mail-list');
  const activePanel = document.getElementById('vf-active');
  if (!input || !drop || !refresh || !state || !list || !activePanel) return;

  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  })[char]);
  const activeCase = () => {
    for (const storage of [sessionStorage, localStorage]) {
      try {
        const value = JSON.parse(storage.getItem('svnet-case') || 'null');
        if (value?.folder_id) return value;
      } catch {}
    }
    return null;
  };
  const request = async (url, options = {}) => {
    const response = await fetch(url, { credentials: 'same-origin', ...options });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw Error(data.error || `HTTP ${response.status}`);
    return data;
  };
  const isMessage = item => /\.(?:msg|eml)$/i.test(item.name || '') || ['message/rfc822', 'application/vnd.ms-outlook'].includes(item.mimeType || '');
  const flatten = (items, inCorrespondence = false, result = []) => {
    for (const item of items || []) {
      const normalizedName = String(item.name || '').toLowerCase().replace(/ä/g, 'ae');
      const correspondence = inCorrespondence || (item.folder && /^07[_\s-]*korrespondenz$/.test(normalizedName));
      if (item.folder) flatten(item.children, correspondence, result);
      else if (correspondence || isMessage(item)) result.push(item);
    }
    return result;
  };
  const formatDate = value => {
    if (!value) return '';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '' : new Intl.DateTimeFormat('de-DE', { dateStyle: 'medium', timeStyle: 'short' }).format(date);
  };
  const formatSize = bytes => {
    const size = Number(bytes || 0);
    if (!size) return '';
    return size < 1024 * 1024 ? `${Math.max(1, Math.round(size / 1024))} KB` : `${(size / 1024 / 1024).toFixed(1).replace('.', ',')} MB`;
  };
  const setEnabled = enabled => {
    refresh.disabled = !enabled;
    input.disabled = !enabled;
    drop.classList.toggle('disabled', !enabled);
    drop.setAttribute('aria-disabled', String(!enabled));
  };
  const render = items => {
    if (!items.length) {
      list.innerHTML = '<p class="vf-mail-history-empty">In diesem Fall sind noch keine Mails oder Korrespondenzen gespeichert.</p>';
      return;
    }
    list.innerHTML = items.map(item => {
      const params = `folder_id=${encodeURIComponent(activeCase()?.folder_id || '')}&file_id=${encodeURIComponent(item.id)}`;
      const meta = [formatDate(item.modifiedTime), formatSize(item.size)].filter(Boolean).join(' · ');
      return `<article class="vf-mail-history-item"><strong>${escapeHtml(item.name)}</strong><small>${escapeHtml(meta)}</small><div class="vf-mail-history-links"><a href="${BROWSER}?action=file&${params}" target="_blank" rel="noopener">Öffnen</a><a href="${BROWSER}?action=download&${params}">Speichern</a></div></article>`;
    }).join('');
  };
  const load = async () => {
    const active = activeCase();
    setEnabled(Boolean(active));
    if (!active) {
      state.textContent = 'Bitte zuerst einen Fall öffnen.';
      list.innerHTML = '';
      return;
    }
    state.textContent = 'Gespeicherter Mailverlauf wird geladen …';
    try {
      const data = await request(`${BROWSER}?folder_id=${encodeURIComponent(active.folder_id)}`);
      const items = flatten(data.items).sort((a, b) => String(b.modifiedTime || '').localeCompare(String(a.modifiedTime || '')));
      render(items);
      state.textContent = items.length === 1 ? '1 gespeicherte Mail/Korrespondenz.' : `${items.length} gespeicherte Mails/Korrespondenzen.`;
    } catch (error) {
      list.innerHTML = '';
      state.textContent = `Mailverlauf konnte nicht geladen werden: ${error.message}`;
    }
  };
  const receive = async fileList => {
    const active = activeCase();
    if (!active) {
      state.textContent = 'Bitte zuerst einen Fall öffnen.';
      return;
    }
    const files = [...fileList];
    if (!files.length) return;
    const invalid = files.filter(file => !/\.(?:msg|eml)$/i.test(file.name));
    if (invalid.length) {
      state.textContent = 'Bitte Outlook-Mails als .msg oder .eml ablegen.';
      return;
    }
    setEnabled(false);
    let uploaded = 0;
    let duplicates = 0;
    try {
      for (const [index, file] of files.entries()) {
        state.textContent = `Mail ${index + 1} von ${files.length} wird in der Fallakte gespeichert …`;
        const body = new FormData();
        body.append('folder_id', active.folder_id);
        body.append('last_modified', String(file.lastModified || 0));
        body.append('file', file);
        const result = await request(`${API}?action=upload_case_document`, { method: 'POST', body });
        result.duplicate ? duplicates++ : uploaded++;
      }
      input.value = '';
      await load();
      state.textContent = `${uploaded} Mail${uploaded === 1 ? '' : 's'} gespeichert${duplicates ? ` · ${duplicates} bereits vorhanden` : ''}.`;
    } catch (error) {
      state.textContent = `Mail konnte nicht gespeichert werden: ${error.message}`;
    } finally {
      setEnabled(Boolean(activeCase()));
    }
  };

  drop.addEventListener('click', () => { if (activeCase()) input.click(); });
  drop.addEventListener('keydown', event => {
    if ((event.key === 'Enter' || event.key === ' ') && activeCase()) {
      event.preventDefault();
      input.click();
    }
  });
  input.addEventListener('change', () => receive(input.files));
  ['dragenter', 'dragover'].forEach(type => drop.addEventListener(type, event => {
    event.preventDefault();
    if (activeCase()) drop.classList.add('drag');
  }));
  ['dragleave', 'drop'].forEach(type => drop.addEventListener(type, event => {
    event.preventDefault();
    drop.classList.remove('drag');
  }));
  drop.addEventListener('drop', event => receive(event.dataTransfer?.files || []));
  refresh.addEventListener('click', load);
  new MutationObserver(load).observe(activePanel, { attributes: true, childList: true, subtree: true });
  window.addEventListener('svnet:cases-changed', load);
  load();
})();
