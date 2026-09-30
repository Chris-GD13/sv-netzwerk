import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const page = fs.readFileSync(path.join(root, 'src/pages/intern/versicherungsfaelle/index.astro'), 'utf8');

assert(page.includes("taskNumber=String(task.case_number||number||'').trim()"), 'Die Schadennummer aus dem vollständigen Mailtext muss Vorrang vor der Vorschau haben');
assert(page.includes("String(r.meta?.schaden_nr||'').trim()===taskNumber"), 'Aufgaben dürfen nur einem Fall mit exakt passender Schadennummer zugeordnet werden');
assert(page.includes("if(!manuallySelected&&(!taskNumber||!current?.folder_id||String(current.meta?.schaden_nr||'').trim()!==taskNumber))"), 'Ein beliebiger zuvor geöffneter Fall darf nicht stillschweigend übernommen werden');
assert(page.includes("new URLSearchParams({aufgabe:taskId,fallordner:folderId})"), 'Eine manuelle Fallzuordnung muss beim Laden der Aufgabenansicht erhalten bleiben');
assert(page.includes('id="vf-task-reply"') && page.includes('recipient.value=task.from') && page.includes('body?.focus'), 'Die Aufgabe muss eine Antwort im fallbezogenen Mailformular vorbereiten');
assert(page.includes('id="vf-task-kva"') && page.includes("document.querySelector('.vf-technical-release')"), 'Die Aufgabe muss direkt zur KVA-Prüfung und Freigabe führen');
assert(page.includes('/\\b(KVA|Kostenvoranschlag|Angebot)\\b/i.test(a.name)') && page.includes("action=upload_case_document"), 'KVA-Anhänge aus der Aufgabe müssen in den Fall übernommen werden können');

console.log('task_case_workflow_test: ok');
