(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.SVNetPhonebookImport = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  const clean = value => String(value == null ? '' : value).trim();
  const cleanNote = value => clean(value)
    .replace(/\bms-outlook:\/\/\S+/gi, ' ')
    .replace(/\s+/g, ' ')
    .trim();
  const phoneKey = value => clean(value).replace(/\D/g, '').replace(/^00/, '');
  const unescapeVCard = value => clean(value).replace(/\\n/gi, ' ').replace(/\\([,;\\])/g, '$1');

  function deduplicate(contacts) {
    const rows = [];
    const seen = new Map();
    contacts.forEach(contact => {
      const name = clean(contact?.name);
      const phone = clean(contact?.phone);
      const key = phoneKey(phone);
      if (!name || key.length < 3) return;
      const note = cleanNote(contact?.note);
      if (!seen.has(key)) {
        seen.set(key, rows.length);
        rows.push({ name, phone, note });
        return;
      }
      const existing = rows[seen.get(key)];
      if (name.length > existing.name.length) existing.name = name;
      if (!existing.note && note) existing.note = note;
    });
    return rows;
  }

  function parseCsvLine(line, delimiter) {
    const cells = [];
    let value = '';
    let quoted = false;
    for (let index = 0; index < line.length; index += 1) {
      const char = line[index];
      if (char === '"' && quoted && line[index + 1] === '"') { value += '"'; index += 1; }
      else if (char === '"') quoted = !quoted;
      else if (char === delimiter && !quoted) { cells.push(clean(value)); value = ''; }
      else value += char;
    }
    cells.push(clean(value));
    return cells;
  }

  function parseCsv(text) {
    const lines = String(text || '').replace(/^\uFEFF/, '').split(/\r?\n/).filter(line => line.trim());
    if (!lines.length) return [];
    const delimiter = (lines[0].match(/;/g) || []).length >= (lines[0].match(/,/g) || []).length ? ';' : ',';
    const rows = lines.map(line => parseCsvLine(line, delimiter));
    const headers = rows[0].map(value => value.toLocaleLowerCase('de-DE').replace(/[^a-z0-9äöüß]+/g, ''));
    const indexOf = names => headers.findIndex(header => names.includes(header));
    const phoneIndex = indexOf(['telefon', 'telefonnummer', 'phone', 'mobil', 'mobile', 'handy', 'rufnummer']);
    const nameIndex = indexOf(['name', 'vollständigername', 'vollstaendigername', 'fullname', 'kontakt', 'firma']);
    const firstIndex = indexOf(['vorname', 'firstname', 'givenname']);
    const lastIndex = indexOf(['nachname', 'lastname', 'surname', 'familyname']);
    const noteIndex = indexOf(['notiz', 'notizen', 'note', 'notes', 'bemerkung', 'firma', 'company']);
    const hasHeader = phoneIndex >= 0 && (nameIndex >= 0 || firstIndex >= 0 || lastIndex >= 0);
    const body = hasHeader ? rows.slice(1) : rows;
    return deduplicate(body.map(row => ({
      name: nameIndex >= 0 ? clean(row[nameIndex]) : clean([row[firstIndex], row[lastIndex]].filter(Boolean).join(' ')) || clean(row[0]),
      phone: phoneIndex >= 0 ? clean(row[phoneIndex]) : clean(row[1]),
      note: noteIndex >= 0 ? cleanNote(row[noteIndex]) : cleanNote(row[2]),
    })));
  }

  function parseVCard(text) {
    const unfolded = String(text || '').replace(/\r?\n[ \t]/g, '');
    const cards = unfolded.match(/BEGIN:VCARD[\s\S]*?END:VCARD/gi) || [];
    const contacts = [];
    cards.forEach(card => {
      const lines = card.split(/\r?\n/);
      const values = key => lines.filter(line => new RegExp(`^(?:[^.:;]+\\.)?${key}(?:;[^:]*)?:`, 'i').test(line)).map(line => unescapeVCard(line.slice(line.indexOf(':') + 1)));
      const formatted = values('FN')[0] || '';
      const parts = (values('N')[0] || '').split(';');
      const name = formatted || clean([parts[1], parts[0]].filter(Boolean).join(' '));
      const note = cleanNote(values('NOTE')[0] || values('ORG')[0] || '');
      values('TEL').forEach(value => { const phone = value.replace(/^tel:/i, ''); if (name && phone) contacts.push({ name, phone, note }); });
    });
    return deduplicate(contacts);
  }

  function parse(text, filename) {
    return /\.vcf$/i.test(String(filename || '')) || /BEGIN:VCARD/i.test(String(text || '')) ? parseVCard(text) : parseCsv(text);
  }

  return { parse, parseCsv, parseVCard, deduplicate, phoneKey, cleanNote };
});
