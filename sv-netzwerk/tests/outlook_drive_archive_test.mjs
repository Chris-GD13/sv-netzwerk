import assert from 'node:assert/strict';
import fs from 'node:fs';

const tasksApi = fs.readFileSync(new URL('../public/intern/api/outlook-tasks.php', import.meta.url), 'utf8');
const tasksPage = fs.readFileSync(new URL('../src/pages/intern/aufgaben/index.astro', import.meta.url), 'utf8');

assert(tasksApi.includes("in_array($action, ['detail', 'attachment', 'eml'], true)"), 'Der EML-Abruf muss dieselbe Zu-erledigen-Nachricht prüfen wie die Detailansicht.');
assert(tasksApi.includes("'/messages/' . rawurlencode($id) . '/$value'"), 'Die Original-Mail muss als MIME/EML aus Microsoft Graph geladen werden.');
assert(tasksApi.includes("header('Content-Type: message/rfc822')") && tasksApi.includes("25 * 1024 * 1024"), 'Der EML-Endpunkt muss Original-Mails mit Größenlimit ausliefern.');
assert(tasksPage.includes("String(row.meta?.schaden_nr || '').trim() === caseNumber"), 'Mails dürfen nur einem Fall mit exakt passender Schadennummer zugeordnet werden.');
assert(tasksPage.includes("if (exactCases.length > 1) { unresolved++; setArchiveState(item, 'Mehrere Falltreffer', 'is-error'); continue; }"), 'Bei mehreren passenden Fällen darf keine automatische Ablage erfolgen und der Zuordnungsfehler muss sichtbar sein.');
assert(tasksPage.includes("action=save_case") && tasksPage.includes("schaden_nr: caseNumber"), 'Bei Schadennummer ohne Treffer muss ein neuer Drive-Fall angelegt werden.');
assert(tasksPage.includes("action=upload_case_document") && tasksPage.includes("action=attachment"), 'Original-Mails und Mailanhänge müssen in Google Drive abgelegt werden.');
assert(tasksPage.includes('void archiveOpenTasks(data.items || [], data.mailbox || \'\')'), 'Der Aufgabenabruf muss den automatischen Drive-Abgleich starten.');
assert(tasksPage.includes('id="tasks-drive-sync"') && tasksPage.includes("driveSync?.addEventListener('click'"), 'Der Drive-Abgleich muss manuell über einen Button gestartet werden können.');

console.log('outlook_drive_archive_test: ok');
