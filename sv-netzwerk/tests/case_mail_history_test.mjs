import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync(new URL('../src/pages/intern/versicherungsfaelle/index.astro', import.meta.url), 'utf8');
const client = fs.readFileSync(new URL('../public/intern/case-mail-history.js', import.meta.url), 'utf8');
const drive = fs.readFileSync(new URL('../public/intern/api/google-drive-sync.php', import.meta.url), 'utf8');

assert(page.includes('class="vf-mail-row"'), 'E-Mail schreiben und Mails muessen eine gemeinsame Zeile bilden.');
assert(page.includes('<strong>Mails</strong>') && page.includes('Outlook-Mails hier hineinziehen'), 'Der neue Mailbereich fehlt.');
assert(page.includes('.vf-mail-row{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr)'), 'Die Mailzeile ist nicht mittig geteilt.');
assert(page.includes('.vf-command-grid,.vf-analysis-grid,.vf-mail-row{grid-template-columns:1fr'), 'Die mobile Einspaltenregel fehlt.');
assert(client.includes('/intern/api/case-file-browser.php') && client.includes('action=upload_case_document'), 'Fallablage und Mailverlauf sind nicht verbunden.');
assert(client.includes('/\\.(?:msg|eml)$/i') && client.includes('07[_\\s-]*korrespondenz'), 'Mailformate oder Korrespondenzordner werden nicht erkannt.');
assert(drive.includes("in_array($extension,['msg','eml'],true)"), '.msg und .eml muessen sicher nach 07_Korrespondenz einsortiert werden.');

console.log('case mail history tests passed');
