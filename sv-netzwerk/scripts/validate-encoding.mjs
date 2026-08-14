#!/usr/bin/env node
// Encoding validator: detects corrupted Unicode characters in content files.
// Stops publication if suspicious encoding errors are found.

import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs';
import { join, extname, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = join(__dirname, '..');

// Patterns that indicate encoding corruption
const CORRUPT_PATTERNS = [
  // Question mark inside a word (letter?letter) — not a real sentence question
  { regex: /\p{L}\?\p{L}/gu, label: 'Fragezeichen im Wort (Buchstabe?Buchstabe)' },
  // Question mark at likely word start after space/newline (Ä/Ö/Ü replacement)
  { regex: /(?:^|[\s\-])(\?[a-zäöüA-ZÄÖÜ])/gmu, label: 'Fragezeichen am Wortanfang' },
  // Unicode Replacement Character U+FFFD
  { regex: /\uFFFD/g, label: 'Unicode Replacement Character (U+FFFD)' },
  // Mojibake patterns: typical UTF-8 bytes decoded as Windows-1252/Latin-1.
  // The second character is often below U+00C0 (e.g. "Ã¤" contains U+00A4),
  // therefore the former narrower range did not catch published articles.
  { regex: /Ã[\u0080-\u00FF]/g, label: 'Mojibake-Sequenz (Ã + Bytezeichen)' },
  { regex: /Â[\u0080-\u00FF]/g, label: 'Mojibake-Sequenz (Â + Bytezeichen)' },
  { regex: /â[\u0080-\u00FF]{1,2}/g, label: 'Mojibake-Sequenz (â + Bytezeichen)' },
];

const EXTENSIONS_TO_CHECK = new Set(['.md', '.txt', '.json', '.ts']);

const PATHS_TO_CHECK = [
  join(ROOT, 'src', 'content', 'knowledge'),
  join(ROOT, 'drafts', 'fachbeitraege', 'scheduled'),
  join(ROOT, 'src', 'data', 'library.ts'),
];

function collectFiles(pathOrDir) {
  if (!existsSync(pathOrDir)) return [];
  const stat = statSync(pathOrDir);
  if (stat.isFile()) return [pathOrDir];
  const results = [];
  for (const entry of readdirSync(pathOrDir, { withFileTypes: true })) {
    const full = join(pathOrDir, entry.name);
    if (entry.isDirectory()) {
      results.push(...collectFiles(full));
    } else if (EXTENSIONS_TO_CHECK.has(extname(entry.name))) {
      results.push(full);
    }
  }
  return results;
}

let totalFiles = 0;
let failFiles = 0;
const findings = [];

for (const p of PATHS_TO_CHECK) {
  for (const file of collectFiles(p)) {
    totalFiles++;
    const content = readFileSync(file, 'utf-8');
    const relPath = file.replace(ROOT + '\\', '').replace(ROOT + '/', '');
    const fileFindings = [];

    for (const { regex, label } of CORRUPT_PATTERNS) {
      const matches = [...content.matchAll(regex)];
      if (matches.length > 0) {
        fileFindings.push(`  ${label}: ${matches.length} Treffer`);
      }
    }

    if (fileFindings.length > 0) {
      failFiles++;
      findings.push(`FAIL ${relPath}\n${fileFindings.join('\n')}`);
    }
  }
}

if (findings.length > 0) {
  console.error(`\nEncoding-Validierung fehlgeschlagen: ${failFiles} von ${totalFiles} Dateien betroffen.\n`);
  findings.forEach((f) => console.error(f));
  process.exit(1);
} else {
  console.log(`OK Encoding-Validierung: ${totalFiles} Dateien geprüft, keine Fehler gefunden.`);
}
