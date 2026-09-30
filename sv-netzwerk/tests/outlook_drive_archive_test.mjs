import assert from 'node:assert/strict';
import fs from 'node:fs';

const tasksApi = fs.readFileSync(new URL('../public/intern/api/outlook-tasks.php', import.meta.url), 'utf8');
const tasksPage = fs.readFileSync(new URL('../src/pages/intern/aufgaben/index.astro', import.meta.url), 'utf8');

assert(tasksApi.includes("in_array($action, ['detail', 'attachment', 'eml'], true)"), 'Der EML-Abruf muss dieselbe Zu-erledigen-Nachricht prüfen wie die Detailansicht.');
assert(tasksApi.includes("'/messages/' . rawurlencode($id) . '/$value'"), 'Die Original-Mail muss als MIME/EML aus Microsoft Graph geladen werden.');
assert(tasksApi.includes("header('Content-Type: message/rfc822')") && tasksApi.includes("25 * 1024 * 1024"), 'Der EML-Endpunkt muss Original-Mails mit Größenlimit ausliefern.');
assert(tasksPage.includes("String(row.meta?.schaden_nr || '').trim() === caseNumber"), 'Mails dürfen nur einem Fall mit exakt passender Schadennummer zugeordnet werden.');
assert(tasksPage.includes('exactCases.length !== 1'), 'Bei fehlendem oder mehrdeutigem Treffer darf keine automatische Ablage stattfinden.');
assert(tasksPage.includes("action=upload_case_document") && tasksPage.includes("action=attachment"), 'Original-Mails und Mailanhänge müssen in Google Drive abgelegt werden.');
assert(tasksPage.includes('void archiveOpenTasks(data.items || [], data.mailbox || \'\')'), 'Der Aufgabenabruf muss den automatischen Drive-Abgleich starten.');

console.log('outlook_drive_archive_test: ok');
