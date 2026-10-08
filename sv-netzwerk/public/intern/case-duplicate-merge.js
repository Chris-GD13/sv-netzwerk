(() => {
  const box = document.getElementById('vf-results');
  if (!box) return;
  const key = value => String(value || '').replace(/[\s\u00ad\u2010-\u2015\u2212-]+/gu, '').toUpperCase();
  let busy = false;
  const render = () => {
    if (busy) return;
    const cards = [...box.querySelectorAll('.vf-result')];
    const groups = new Map();
    cards.forEach(card => {
      const number = card.querySelector('strong')?.textContent?.trim();
      if (!number || !/\d/.test(number)) return;
      const k = key(number);
      if (!groups.has(k)) groups.set(k, []);
      groups.get(k).push({ card, number });
    });
    for (const [k, rows] of groups) {
      if (rows.length !== 2 || box.querySelector(`[data-merge-key="${CSS.escape(k)}"]`)) continue;
      const panel = document.createElement('div');
      panel.dataset.mergeKey = k;
      const text = document.createElement('p');
      text.textContent = 'Gleiche Schadenummer in zwei Schreibweisen. Beide Akten mit allen Unterlagen zusammenführen.';
      const button = document.createElement('button');
      button.className = 'vf-secondary';
      button.textContent = 'Diese beiden Fälle zusammenführen';
      button.onclick = async () => {
        busy = true; button.disabled = true; text.textContent = 'Akten werden abgeglichen und gesichert …';
        const request = async (url, options) => {
          const response = await fetch(url, { credentials: 'same-origin', ...options });
          const data = await response.json();
          if (!response.ok) throw Error(data.error || `HTTP ${response.status}`);
          return data;
        };
        try {
          const found = await request('/intern/api/google-drive-sync.php?action=search_cases&q=' + encodeURIComponent(rows[0].number));
          const matches = (found.results || []).filter(row => key(row.meta?.schaden_nr || row.name) === k);
          if (matches.length !== 2) throw Error('Bitte Suche erneuern: Der Aktenbestand hat sich geändert.');
          const loaded = await Promise.all(matches.map(row => request('/intern/api/google-drive-sync.php?action=load_case&id=' + encodeURIComponent(row.id))));
          const score = row => Object.values(row.case.meta || {}).filter(v => v !== '' && v != null).length + (row.case.meta?.versicherungsschein_nr ? 100 : 0);
          loaded.sort((a,b) => score(b) - score(a));
          const result = await request('/intern/api/google-drive-sync.php?action=merge_cases', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ target_id: loaded[0].case.id, source_id: loaded[1].case.id }) });
          const active = { folder_id: result.folder_id, meta: result.meta || loaded[0].case.meta };
          sessionStorage.setItem('svnet-case', JSON.stringify(active));localStorage.setItem('svnet-case', JSON.stringify(active));
          sessionStorage.setItem('svnet-case-merge-result', JSON.stringify({ number: active.meta.schaden_nr, items: result.preserved_items }));
          location.reload();
        } catch (error) {text.textContent = error.message;button.disabled = false;busy = false;}
      };
      panel.append(text, button);box.append(panel);
    }
  };
  new MutationObserver(render).observe(box, { childList: true });
  render();
  try {
    const result = JSON.parse(sessionStorage.getItem('svnet-case-merge-result') || 'null');
    if (result) {
      const notice = document.createElement('p');notice.setAttribute('role','status');
      notice.textContent = `Zusammengeführt: ${result.number}. Alle Unterlagen beider Akten sind erhalten.`;
      document.getElementById('vf-active')?.after(notice);sessionStorage.removeItem('svnet-case-merge-result');
    }
  } catch {}
})();
