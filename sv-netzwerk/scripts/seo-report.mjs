import { readdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const knowledgeDir = path.join(root, 'src', 'content', 'knowledge');

const parse = (source) => {
  const match = source.match(/^---\r?\n([\s\S]*?)\r?\n---\r?\n([\s\S]*)$/);
  if (!match) return null;
  const front = match[1];
  const value = (key) => {
    const item = front.match(new RegExp(`^\\s*${key}:\\s*["']?([^"'\\r\\n]+)["']?\\s*$`, 'm'));
    return item?.[1]?.trim();
  };
  return { front, body: match[2], value };
};

const files = (await readdir(knowledgeDir)).filter((f) => /\.mdx?$/.test(f));
const entries = [];

for (const file of files) {
  const source = await readFile(path.join(knowledgeDir, file), 'utf8');
  const parsed = parse(source);
  if (!parsed) continue;
  entries.push({
    file,
    slug: file.replace(/\.mdx?$/, ''),
    date: parsed.value('publishedAt') ?? '',
    status: parsed.value('status') ?? 'draft',
    level: parsed.value('contentLevel') ?? '',
    category: parsed.value('category') ?? '',
    daily: /^dailyStandard:\s*true$/m.test(parsed.front),
    featured: /^featured:\s*true$/m.test(parsed.front),
  });
}

entries.sort((a, b) => a.date.localeCompare(b.date));

const published = entries.filter((e) => e.status === 'published');
const daily = entries.filter((e) => e.daily);
const byLevel = { A: daily.filter((e) => e.level === 'A'), B: daily.filter((e) => e.level === 'B'), C: daily.filter((e) => e.level === 'C') };

const byCategory = published.reduce((map, e) => {
  map.set(e.category, (map.get(e.category) ?? 0) + 1);
  return map;
}, new Map());

const byMonth = daily.reduce((map, e) => {
  const month = e.date.slice(0, 7);
  map.set(month, (map.get(month) ?? 0) + 1);
  return map;
}, new Map());

const now = new Date();
const reportMonth = now.toISOString().slice(0, 7);
const reportDate = now.toISOString().slice(0, 10);

const categoryTable = [...byCategory.entries()]
  .sort((a, b) => b[1] - a[1])
  .map(([cat, count]) => `| ${cat} | ${count} |`)
  .join('\n');

const monthTable = [...byMonth.entries()]
  .sort()
  .map(([month, count]) => `| ${month} | ${count} |`)
  .join('\n');

const recentArticles = daily.slice(-5).reverse()
  .map((e) => `- ${e.date} | ${e.level} | ${e.slug}`)
  .join('\n');

const report = `# SEO-Statusbericht ${reportMonth}

_Automatisch erstellt am ${reportDate}_

---

## 1. Inhaltsbestand (automatisch ermittelt)

| Metrik | Wert |
|---|---:|
| Wissensartikel gesamt | ${entries.length} |
| Veröffentlicht | ${published.length} |
| Tägliche Pflichtbeiträge | ${daily.length} |
| Davon A-Beiträge (1.500–2.500 Wörter) | ${byLevel.A.length} |
| Davon B-Beiträge (800–1.200 Wörter) | ${byLevel.B.length} |
| Davon C-Beiträge (300–500 Wörter) | ${byLevel.C.length} |
| Featured-Artikel | ${entries.filter((e) => e.featured).length} |

### Veröffentlichungen nach Kategorie

| Kategorie | Artikel |
|---|---:|
${categoryTable}

### Veröffentlichungen nach Monat

| Monat | Artikel |
|---|---:|
${monthTable}

### Zuletzt veröffentlichte Pflichtbeiträge

${recentArticles}

---

## 2. SEO-Kennzahlen (aus Google Search Console einzutragen)

| Kennzahl | Vormonat | Aktuell | Entwicklung |
|---|---:|---:|---:|
| Indexierte Seiten | — | _eintragen_ | |
| Organische Klicks (Monat) | — | _eintragen_ | |
| Impressionen (Monat) | — | _eintragen_ | |
| Ø Google-Position | — | _eintragen_ | |
| Neue Backlinks | — | _eintragen_ | |

---

## 3. Top-Keywords (aus Google Search Console)

| Keyword | Klicks | Impressionen | Ø Position |
|---|---:|---:|---:|
| _eintragen_ | | | |
| _eintragen_ | | | |
| _eintragen_ | | | |
| _eintragen_ | | | |
| _eintragen_ | | | |

---

## 4. Erfolgreichste Seiten (organischer Traffic)

| Seite | Klicks | Impressionen | CTR |
|---|---:|---:|---:|
| _eintragen_ | | | |
| _eintragen_ | | | |
| _eintragen_ | | | |

---

## 5. Core Web Vitals und Ladezeiten

| Metrik | Wert | Bewertung |
|---|---|---|
| LCP (Largest Contentful Paint) | _eintragen_ | |
| INP (Interaction to Next Paint) | _eintragen_ | |
| CLS (Cumulative Layout Shift) | _eintragen_ | |
| Ø Ladezeit Desktop | _eintragen_ | |
| Ø Ladezeit Mobil | _eintragen_ | |

---

## 6. Crawl- und Indexierungsfehler (aus GSC: Abdeckung)

- Seiten mit Fehlern: _eintragen_
- Ausgeschlossene Seiten: _eintragen_
- Wichtigste Fehlertypen: _eintragen_

---

## 7. Rankingentwicklung

### Positive Entwicklung (Top-5 gestiegene Seiten)

| Seite | Position Vormonat | Position aktuell |
|---|---:|---:|
| _eintragen_ | | |

### Negative Entwicklung (Top-5 gefallene Seiten)

| Seite | Position Vormonat | Position aktuell |
|---|---:|---:|
| _eintragen_ | | |

---

## 8. Maßnahmen und Priorisierung für den Folgemonat

### Themenvorschläge auf Basis aktueller Inhaltslücken

- [ ] _Thema 1 eintragen (z. B. unterperformende Keywords stärken)_
- [ ] _Thema 2 eintragen_
- [ ] _Thema 3 eintragen_

### Technische Maßnahmen

- [ ] _Maßnahme 1 (z. B. Ladezeit-Optimierung, fehlende Meta-Tags)_
- [ ] _Maßnahme 2_

---

_Bericht wurde automatisch aus dem Repository-Bestand erzeugt. Abschnitte 2–8 sind manuell aus Google Search Console und PageSpeed Insights zu befüllen._
`;

const outPath = process.argv.find((a) => a.startsWith('--out='))?.slice(6);
if (outPath) {
  await writeFile(outPath, report, 'utf8');
  console.log(`SEO-Bericht gespeichert: ${outPath}`);
} else {
  process.stdout.write(report);
}
