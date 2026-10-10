import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import { claimFolderPath, safeFileName } from '../browser-extension/claimsforce-bridge/import-utils.js';
import { isClaimsforceMail, normalizeClaimsforceMail } from '../scripts/claimsforce-mail.js';

assert(isClaimsforceMail({name:'Mail_ClaimsForce-Nachricht_langer-Betreff-ohne-Endung',mimeType:'application/json'}));
assert(isClaimsforceMail({name:'Mail_ClaimsForce-Nachricht_kurz.json'}));
assert(!isClaimsforceMail({name:'Mail_ClaimsForce-Nachricht_Bild.jpg',mimeType:'image/jpeg'}));
assert(!isClaimsforceMail({name:'00_Falldaten.json',mimeType:'application/json'}));

assert.equal(claimFolderPath({ rootFolderId: 'OTHER', parentFolderId: 'child' }, [
  { id: 'child', name: 'Mailanhänge', parentFolderId: 'parent' },
  { id: 'parent', name: '2026', parentFolderId: 'OTHER' }
]), 'Weitere Dokumente/2026/Mailanhänge');
assert.equal(claimFolderPath({ parentFolderId: 'CALCULATION_INVOICE' }), 'Rechnungen');

// Den echten Übertragungsabschnitt ausführen: geschützte Referenzen dürfen
// weder weitere Dateien/Mails blockieren noch als heruntergeladen gelten.
const worker = fs.readFileSync(new URL('../browser-extension/claimsforce-bridge/service-worker.js', import.meta.url), 'utf8');
const tabSelection = worker.slice(worker.indexOf('  const invokingTab =', worker.indexOf('async function claimsTab')), worker.indexOf('  tab = await waitTab', worker.indexOf('async function claimsTab')));
for (const groupId of [7, -1]) {
  const groups = [], created = [];
  const chrome = { tabs: {
    get: async () => ({ id: 1, windowId: 3, index: 2, groupId }),
    query: async () => [{ id: 99, windowId: 8, groupId }],
    create: async options => { created.push(options); return {id: 2}; },
    group: async options => groups.push(options)
  } };
  const selected = await vm.runInNewContext(`(async()=>{${tabSelection}\nreturn tab;})()`, { chrome, run: {portalTabId: 1} });
  assert.equal(selected.id, 2, 'Ein fremdes Fenster darf keinen Importtab liefern');
  assert.equal(created[0].windowId, 3);
  assert.equal(groups.length, groupId >= 0 ? 1 : 0);
  if (groupId >= 0) assert.equal(groups[0].groupId, groupId, 'Neuer Tab bleibt im aufrufenden Chat');
}
const transfer = worker.slice(worker.indexOf('    let caseDeadline ='), worker.indexOf('\n    updated++;'));
async function run(status, reference) {
  const uploaded = [], calls = [], commits = [];
  const files = [ { id: 'locked', fileName: 'Kostennote.pdf', attachmentReference: reference },
    { id: 'accessible', fileName: 'Messprotokoll.pdf', parentFolderId: 'OTHER' } ];
  const messages = [ { id: 'mail-1', createdDate: '2026-07-06', payload: { subject: 'Kostennote' }, attachments: [{ id: 'locked', type: 'CLAIM_FILE' }] },
    { id: 'mail-2', payload: { subject: 'Weitere Nachricht' }, attachments: [{ id: 'accessible', type: 'CLAIM_FILE' }] } ];
  const context = { Date, Set, Map, TextEncoder, AbortController, AbortSignal, setTimeout, clearTimeout,
    run: {}, files, fileIndex: files.map(file => ({ id: file.id, name: file.fileName })), messages, folders: [], existingMeta: {}, index: 0, claims: [{}], mapped: { schaden_nr: '26-085905-9' }, item: {},
    profile: 'christian', folderId: 'case', id: 'claim', token: 'test', config: { FILES_API_ENDPOINT: 'https://files.example', COMMUNICATION_API_ENDPOINT: 'https://messages.example' },
    fileVersion: file => file?.id || '', messageVersion: message => message.id,
    claimFolderPath, safeFileName, portalTabId: () => 1,
    filesDone: 0, messagesDone: 0, appointmentsDone: 0, appointments: [], notes: [{ id: 'note' }], tasks: [{ id: 'task' }], rawNotes: [],
    inventory: { files: 2, messages: 2, notes: 1, tasks: 1 }, signature: 'complete',
    async diagnostic() {}, async progress() {},
    async portal(tabId, message) { commits.push(message); return {}; },
    async uploadBuffer(tabId, profile, folderId, name) { uploaded.push(name); return { result: {} }; },
    async requestJson(url) { return messages.find(message => url.endsWith(message.id)); },
    unwrap: value => value,
    async fetch(url) { calls.push(url); const blocked = url.includes('/locked?'); return { ok: !blocked, status: blocked ? status : 200, headers: { get: () => 'application/pdf' }, arrayBuffer: async () => new ArrayBuffer(1) }; }
  };
  const outcome = await vm.runInNewContext(`(async()=>{try{${transfer}\nreturn {ok:true};}catch(error){return {ok:false,message:error.message};}})()`, context);
  return { outcome, uploaded, calls, commits, context };
}
const protectedReference = await run(403, true);
assert(protectedReference.outcome.ok);
assert.equal(protectedReference.context.inventory.restrictedFiles.length, 1);
assert.equal(protectedReference.context.inventory.errors.length, 0);
assert.equal(protectedReference.context.filesDone, 1);
assert.equal(protectedReference.context.messagesDone, 2);
assert(protectedReference.uploaded.some(name => name.includes('Messprotokoll')));
assert.equal(protectedReference.calls.filter(url => url.includes('locked')).length, 1, 'Kein Versuch über einen anderen Endpunkt');
const complete = protectedReference.commits.at(-1);
assert.equal(complete.signature, 'complete');
assert.deepEqual([...complete.fileVersions], ['accessible']);
assert.deepEqual([...complete.messageVersions], ['mail-1', 'mail-2']);
assert.equal(complete.notes.length, 1);
assert.equal(complete.tasks.length, 1);
const mail = normalizeClaimsforceMail({ payload: { subject: 'Schadenfall', from: {name: 'Absender', email:'test@example.org'}, to: ['target@example.org'], body: 'Originaltext' }, attachments: [{id:'locked'}], _svnetImport: {attachments:[{id:'locked',name:'Kostennote',restricted:true}]} });
assert.equal(mail.body, 'Originaltext');
assert.equal(mail.from, 'Absender <test@example.org>');
assert.equal(mail.to, 'target@example.org');
assert.match(mail.attachments[0].status, /gesperrt/);

for (const [status, reference] of [[404, true], [403, false]]) {
  const unavailable = await run(status, reference);
  assert.equal(unavailable.outcome.ok, false, 'Fehlende reguläre Dokumente bleiben unvollständig');
  assert.match(unavailable.outcome.message, /CF-SOURCE-INCOMPLETE/);
  assert.equal(unavailable.context.messagesDone, 2, 'Weitere Mails trotzdem sichern');
  assert.equal(unavailable.commits.at(-1).partial, true);
  assert.equal(unavailable.commits.at(-1).signature, '');
  assert.deepEqual([...unavailable.commits.at(-1).messageVersions], ['mail-2']);
}
console.log('Unterordner, geschützte Referenz, übrige Mails und ehrliche Teilstände geprüft.');
