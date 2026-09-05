import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const clean = value => String(value ?? '').trim();
const unfold = value => String(value ?? '').replace(/\r?\n[ \t]/g, '');
const phoneKey = value => {
  let digits = clean(value).replace(/\D/g, '').replace(/^00/, '');
  if (/^490/.test(digits)) digits = `49${digits.slice(3)}`;
  else if (/^0/.test(digits)) digits = `49${digits.slice(1)}`;
  return digits;
};
const phoneMatchKey = value => phoneKey(value).replace(/^49(?=\d{5,})/, '').replace(/^0+/, '');
const emailKey = value => clean(value).toLocaleLowerCase('de-DE');
const outlookArtifact = /(?:ms-outlook:\/\/|X-APPLE-OL-[A-Z0-9-]+)/i;
const unwantedContactName = /(?:andersson|\bab\b|per\s*mail)/i;
const nameKey = value => clean(value)
  .normalize('NFKD')
  .replace(/[\u0300-\u036f]/g, '')
  .toLocaleLowerCase('de-DE')
  .replace(/\b(?:herr|frau|dr|prof|dipl|ing)\b/g, ' ')
  .replace(/[^a-z0-9]+/g, ' ')
  .replace(/\s+/g, ' ')
  .trim();

function stripOutlookArtifact(value) {
  const match = String(value ?? '').search(outlookArtifact);
  return clean(match >= 0 ? String(value).slice(0, match) : value);
}

function property(line) {
  const colon = line.indexOf(':');
  if (colon < 1) return null;
  const head = line.slice(0, colon);
  const value = line.slice(colon + 1);
  const bare = head.split(';')[0];
  const dot = bare.indexOf('.');
  const group = dot >= 0 ? bare.slice(0, dot) : '';
  const key = (dot >= 0 ? bare.slice(dot + 1) : bare).toUpperCase();
  const suffix = head.slice(bare.length);
  return { group, key, suffix, value, line };
}

function parseCard(card, sourceIndex) {
  const parsed = unfold(card)
    .split(/\r?\n/)
    .map(clean)
    .filter(Boolean)
    .map(property)
    .filter(Boolean);
  const contaminatedGroups = new Set(
    parsed.filter(item => item.group && /ms-outlook:\/\//i.test(item.value)).map(item => item.group),
  );
  let removedOutlookLines = 0;
  const items = parsed.filter(item => {
    if (['BEGIN', 'END', 'VERSION', 'PRODID', 'REV', 'UID'].includes(item.key)) return false;
    if (contaminatedGroups.has(item.group) || outlookArtifact.test(item.value) || item.key.startsWith('X-APPLE-OL-')) {
      removedOutlookLines += 1;
      if (item.key !== 'TEL') return false;
    }
    if (item.key === 'X-ABLABEL') return false;
    return true;
  }).map(item => {
    const value = stripOutlookArtifact(item.value);
    return { ...item, value, group: '', line: `${item.key}${item.suffix}:${value}` };
  }).filter(item => {
    if (!item.value) return false;
    if (item.key !== 'TEL') return true;
    const value = item.value.replace(/^tel:/i, '');
    return !/[0-9][,.][0-9]+E[+-][0-9]+/i.test(value) && phoneKey(value).length >= 3;
  });

  const values = key => items.filter(item => item.key === key).map(item => item.value);
  const phones = values('TEL').map(value => value.replace(/^tel:/i, '')).map(phoneMatchKey).filter(key => key.length >= 3);
  const emails = values('EMAIL').map(emailKey).filter(Boolean);
  const fn = values('FN')[0] || '';
  const fingerprint = items.map(item => `${item.key}:${item.value.toLocaleLowerCase('de-DE')}`).sort().join('|');
  return { sourceIndex, items, phones: [...new Set(phones)], emails: [...new Set(emails)], fn, nameKey: nameKey(fn), fingerprint, removedOutlookLines };
}

function mergeCards(cards) {
  const parent = cards.map((_, index) => index);
  const find = index => {
    while (parent[index] !== index) {
      parent[index] = parent[parent[index]];
      index = parent[index];
    }
    return index;
  };
  const union = (left, right) => {
    left = find(left);
    right = find(right);
    if (left !== right) parent[right] = left;
  };
  const identities = new Map();
  cards.forEach((card, index) => {
    const keys = [
      ...card.phones.map(value => `tel:${value}|name:${card.nameKey}`),
      ...card.emails.map(value => `mail:${value}|name:${card.nameKey}`),
    ];
    if (!keys.length && card.fingerprint) keys.push(`card:${card.fingerprint}`);
    keys.forEach(key => {
      if (identities.has(key)) union(index, identities.get(key));
      else identities.set(key, index);
    });
  });

  const groups = new Map();
  cards.forEach((card, index) => {
    const root = find(index);
    if (!groups.has(root)) groups.set(root, []);
    groups.get(root).push(card);
  });

  return [...groups.values()].map(group => {
    const preferredFn = group.map(card => card.fn).filter(Boolean).sort((a, b) => b.length - a.length)[0] || 'Unbenannter Kontakt';
    const lines = [];
    const seen = new Set();
    const seenPhones = new Set();
    const seenEmails = new Set();
    const add = item => {
      if (item.key === 'FN' || item.key === 'N') return;
      if (item.key === 'TEL') {
        const key = phoneMatchKey(item.value.replace(/^tel:/i, ''));
        if (!key || seenPhones.has(key)) return;
        seenPhones.add(key);
      }
      if (item.key === 'EMAIL') {
        const key = emailKey(item.value);
        if (!key || seenEmails.has(key)) return;
        seenEmails.add(key);
      }
      const key = `${item.key}|${item.value.toLocaleLowerCase('de-DE')}`;
      if (seen.has(key)) return;
      seen.add(key);
      lines.push(item.line);
    };
    group.forEach(card => card.items.forEach(add));
    const nLine = group.flatMap(card => card.items).find(item => item.key === 'N')?.line;
    return {
      name: preferredFn,
      lines,
      phones: seenPhones.size,
      phoneValues: lines.filter(line => /^TEL(?:;|:)/i.test(line)).map(line => line.slice(line.indexOf(':') + 1)),
      emails: seenEmails.size,
      text: ['BEGIN:VCARD', 'VERSION:3.0', `FN:${preferredFn}`, ...(nLine ? [nLine] : []), ...lines, 'END:VCARD'].join('\r\n'),
    };
  });
}

function csvCell(value) {
  const text = String(value ?? '').replace(/\r?\n/g, ' ');
  return /[";,\r\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

function portalCsv(cards) {
  const contacts = new Map();
  cards.forEach(card => card.phoneValues.forEach(phone => {
    const key = `${phoneMatchKey(phone)}|${nameKey(card.name)}`;
    if (!key) return;
    const existing = contacts.get(key);
    if (!existing || card.name.length > existing.name.length) contacts.set(key, { name: card.name, phone });
  }));
  const rows = [['Name', 'Telefon', 'Notiz'], ...[...contacts.values()].map(contact => [contact.name, contact.phone, 'iCloud'])];
  return { csv: `\uFEFF${rows.map(row => row.map(csvCell).join(';')).join('\r\n')}\r\n`, count: contacts.size };
}

export function cleanVCards(texts) {
  const cards = [];
  let removedOutlookLines = 0;
  texts.forEach(text => {
    const rawCards = unfold(text).match(/BEGIN:VCARD[\s\S]*?END:VCARD/gi) || [];
    rawCards.forEach(rawCard => {
      const card = parseCard(rawCard, cards.length);
      removedOutlookLines += card.removedOutlookLines;
      if (card.items.length) cards.push(card);
    });
  });
  const allMerged = mergeCards(cards);
  const merged = allMerged.filter(card => !unwantedContactName.test(card.name));
  const portal = portalCsv(merged);
  const report = {
    sourceCards: cards.length,
    cleanedCards: merged.length,
    duplicatesRemoved: cards.length - allMerged.length,
    excludedUnwantedContacts: allMerged.length - merged.length,
    contactsWithPhone: merged.filter(card => card.phones > 0).length,
    contactsWithoutPhone: merged.filter(card => card.phones === 0).length,
    portalPhoneRows: portal.count,
    removedOutlookLines,
  };
  return { vcf: `${merged.map(card => card.text).join('\r\n')}\r\n`, portalCsv: portal.csv, report };
}

function runCli() {
  const args = process.argv.slice(2);
  if (args.length < 2) {
    throw new Error('Aufruf: node scripts/clean-phonebook-vcards.mjs <ausgabe.vcf> <quelle1.vcf> [quelle2.vcf ...]');
  }
  const [output, ...inputs] = args;
  const result = cleanVCards(inputs.map(input => fs.readFileSync(input, 'utf8')));
  fs.mkdirSync(path.dirname(path.resolve(output)), { recursive: true });
  fs.writeFileSync(output, result.vcf, 'utf8');
  const portalOutput = output.replace(/\.vcf$/i, '') + '.portal.csv';
  fs.writeFileSync(portalOutput, result.portalCsv, 'utf8');
  fs.writeFileSync(`${output}.report.json`, `${JSON.stringify(result.report, null, 2)}\n`, 'utf8');
  process.stdout.write(`${JSON.stringify(result.report, null, 2)}\n`);
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) runCli();
