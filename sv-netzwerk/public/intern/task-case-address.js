(() => {
  const normalize = value => String(value || '').toLocaleLowerCase('de-DE').replace(/[äöüß]/g, char => ({ ä: 'ae', ö: 'oe', ü: 'ue', ß: 'ss' }[char])).normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/str\.(?=\s|$)/g, 'strasse').replace(/[^a-z0-9]+/g, ' ').trim();
  const addresses = text => {
    const found = new Set();
    const pattern = /\b(?:[\p{L}][\p{L}-]*[ \t]+){0,2}(?:[\p{L}][\p{L}-]*?)?(?:straße|strasse|str\.|ring|weg|platz|allee|gasse|ufer|damm)[ \t]+\d{1,4}[a-z]?(?:[ \t]*[-/][ \t]*\d{1,4}[a-z]?)?\b/giu;
    for (const match of String(text || '').matchAll(pattern)) {
      const parts = match[0].trim().split(/[ \t]+/);
      while (parts.length >= 2) {
        const address = parts.join(' ');
        if (!/^(?:straße|strasse|str\.|ring|weg|platz|allee|gasse|ufer|damm)\s/iu.test(address)) found.add(address);
        parts.shift();
      }
    }
    return [...found];
  };
  const search = async (task, request) => {
    const found = new Map();
    for (const address of addresses([task.subject, task.body, task.preview].filter(Boolean).join('\n'))) {
      const query = address.replace(/(?:straße|strasse|str\.)(?=\s+\d)/giu, 'str');
      const data = await request('/intern/api/google-drive-sync.php?action=search_cases&q=' + encodeURIComponent(query));
      const key = normalize(address);
      for (const row of data.results || []) {
        const meta = row.meta || {};
        const fields = [meta.schaden_strasse, meta.strasse, meta.vn_objekt, row.name];
        if (row.id && fields.some(value => (' ' + normalize(value) + ' ').includes(' ' + key + ' '))) {
          found.set(row.id, { ...row, task_address: address });
        }
      }
    }
    return [...found.values()];
  };
  window.SVNetTaskAddress = { addresses, search };
})();
