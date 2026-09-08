import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { isActiveRekonTask, mapRekonTask, ownerMatchesRekonProfile, rekonProfileKey } from '../browser-extension/claimsforce-bridge/rekon-utils.js';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const extension = path.join(root, 'browser-extension/claimsforce-bridge');
const manifest = JSON.parse(fs.readFileSync(path.join(extension, 'manifest.json'), 'utf8'));
const worker = fs.readFileSync(path.join(extension, 'service-worker.js'), 'utf8');
const portal = fs.readFileSync(path.join(extension, 'portal-bridge.js'), 'utf8');
const page = fs.readFileSync(path.join(root, 'src/pages/intern/versicherungsfaelle/index.astro'), 'utf8');

assert.equal(rekonProfileKey('marc'), 'marc');
assert.equal(rekonProfileKey('holger'), 'holger');
assert.throws(() => rekonProfileKey('christian'));
assert(ownerMatchesRekonProfile('Schütt Marc (SV)', 'marc'));
assert(ownerMatchesRekonProfile('Holger Roth', 'holger'));
assert(!ownerMatchesRekonProfile('Schütt Marc (SV)', 'holger'));
assert(isActiveRekonTask({ id: 1, state: { title: 'Angenommen' } }));
assert(isActiveRekonTask({ id: 2, state: { title: 'In Bearbeitung' } }));
assert(!isActiveRekonTask({ id: 3, state: { title: 'Abgeschlossen' } }));

const mapped = mapRekonTask({ id: 270330, identifier: '26-157933-3', policy_number: 'V-1', reserve: 1250, state: { title: 'Angenommen' }, owner: { name: 'Schütt Marc (SV)' }, customer: { full_name: 'WEG Andreas-Hofer-Str. 3/1', mobile: '0123', email: 'vn@example.test' }, primary_location: { street: 'Andreas-Hofer-Str.', street_no: '3', postcode: '12345', city: 'Musterstadt' }, primary_form: { template: { title: 'Taskforce 1.0.0' } } });
assert.equal(mapped.schaden_nr, '26-157933-3');
assert.equal(mapped.rekon_task_id, '270330');
assert.equal(mapped.schaden_strasse, 'Andreas-Hofer-Str. 3');
assert.equal(mapped.vn_objekt, 'WEG Andreas-Hofer-Str. 3/1');

assert.equal(manifest.version, '1.4.10');
assert(manifest.host_permissions.includes('https://www.rekoninterschaden-portal.de/*'));
assert(manifest.host_permissions.includes('https://api.www.rekoninterschaden-portal.de/*'));
assert(manifest.content_scripts.some(entry => entry.js?.includes('rekon-main.js') && entry.world === 'MAIN'));
assert(manifest.content_scripts.some(entry => entry.js?.includes('rekon-bridge.js')));
assert(worker.includes('REKON_TASKS_QUERY') && worker.includes('REKON_FILES_QUERY') && worker.includes('REKON_EMAILS_QUERY') && worker.includes('REKON_LOGS_QUERY'));
assert(worker.includes("filter(isActiveRekonTask)"), 'Nur aktive Rekon-Fälle werden verarbeitet');
assert(worker.includes("column: 'REMOVED_AT', operator: 'IS_NULL'") && worker.includes("column: 'ID', order: 'DESC'"), 'Die Aufgabenliste verwendet exakt Rekons Nicht-entfernt-Filter und ID-Sortierung');
assert(worker.includes("payload.errors?.[0]?.message"), 'GraphQL-Fehler werden konkret und begrenzt ausgegeben');
assert(worker.includes('Rekon-Auftragsliste wird eingelesen') && worker.includes('readRekonTasks(token, portalTabId)'), 'Das seitenweise Einlesen der Auftragsliste wird sichtbar gemeldet');
assert(worker.includes("function rekonProgress(tabId") && !worker.includes("await chrome.tabs.sendMessage(tabId, { type: 'REKON_IMPORT_PROGRESS'"), 'Reine Fortschrittsmeldungen dürfen den Rekon-Abruf nicht auf eine Edge-Antwort blockieren');
assert(worker.includes('sendResponse(startRekonImport(sender, message))'), 'Der Rekon-Start wird synchron und ohne offenen Nachrichtenkanal bestätigt');
assert(worker.includes("queueMicrotask(() => {\n    runRekonImport(run)"), 'Der Import beginnt als Microtask nach der sofortigen Startbestätigung und kann nicht zwischen Antwort und Timer schlafen gelegt werden');
assert(worker.includes("rekon: runningRekonImport ? { status: 'running'") && portal.includes('data-svnet-rekon-runtime'), 'Der tatsächliche Rekon-Laufstatus ist im Portal diagnostizierbar');
assert(worker.includes("Object.assign(runningRekonImport, { text, current, total })") && portal.includes("rekon.status === 'running' && rekon.text"), 'Der gepollte Laufstatus stellt Fortschritt auch bei verlorenen Edge-Ereignissen wieder her');
assert(portal.includes('if (!PORTAL_REQUEST_TYPES.has(message?.type)) return;'), 'Rekon-Fortschritt wird nicht als unbekannter Portalauftrag mit offenem Antwortkanal abgefangen');
assert(worker.includes("ownerMatchesRekonProfile"), 'Rekon-Konto und Portalziel werden abgeglichen');
assert(worker.includes("!session?.identity") && worker.includes("while ((!token || !session?.identity)"), 'Die sichtbare Rekon-Profilkennung wird nach dem Seitenaufbau abgewartet');
assert(worker.includes("Mail_Rekon-Nachricht_"), 'E-Mails werden als Korrespondenz archiviert');
assert(worker.includes("for (const attachment of email.attachments || [])"), 'E-Mail-Anhänge werden separat übertragen');
assert(worker.includes("sourceType: 'rekon'"), 'Rekon-Metadaten bleiben von ClaimsForce getrennt');
assert(portal.includes("message.sourceType === 'rekon'"));
assert(portal.includes('Startsignal wurde an die Browser-Brücke übergeben') && portal.includes('nicht innerhalb von 10 Sekunden bestätigt'), 'Startübergabe und Start-Timeout sind im Portal sichtbar');
assert(page.includes('Aktive Aufträge aus Rekon einlesen'));
assert(page.includes('SVNET_REKON_IMPORT_START'));

console.log('Rekon-Import: aktive Fälle, Profilbindung, Dateien, Nachrichten, Anhänge und Termine geprüft.');
