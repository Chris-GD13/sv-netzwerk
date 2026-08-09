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
  // Mojibake patterns: Ã followed by extended chars (typical UTF-8 double-decode)
  { regex: /Ã[\u00C0-\u00FF]/g, label: 'Mojibake-Sequenz (Ã + Erweiterungszeichen)' },
  // Â followed by non-breaking space or soft hyphen (typical win-1252 mis-read)
  { regex: /Â[\u00A0\u00AD]/g, label: 'Mojibake-Sequenz (Â + NBSP/SHY)' },
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
