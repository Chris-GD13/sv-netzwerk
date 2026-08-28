const API = '/intern/api/google-drive-sync.php';
const CAL = '/intern/api/outlook-case-calendar.php';
const uploads = new Map();
const blank = value => value == null || (typeof value === 'string' && value.trim() === '');
const mergeBlank = (existing, incoming) => { const out = { ...(existing || {}) }; Object.entries(incoming || {}).forEach(([key, value]) => { if (blank(out[key]) && !blank(value)) out[key] = value; }); return out; };

async function api(url, options = {}) {
  const response = await fetch(url, { credentials: 'same-origin', ...options });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(data.error || `HTTP ${response.status}`);
  return data;
}

async function findCase(mapped) {
  const query = mapped.schaden_nr || mapped.claimsforce_claim_id;
  if (!query) return null;
  const found = await api(`${API}?action=search_cases&q=${encodeURIComponent(query)}`);
  for (const row of found.results || []) {
    const loaded = await api(`${API}?action=load_case&id=${encodeURIComponent(row.id)}`);
    const meta = loaded.case?.meta || row.meta || {};
    if (meta.claimsforce_claim_id === mapped.claimsforce_claim_id || (mapped.schaden_nr && meta.schaden_nr === mapped.schaden_nr)) return { folderId: row.id, meta };
  }
  return null;
}

async function upsert(message) {
  const existing = await findCase(message.mapped);
  const merged = mergeBlank(existing?.meta || {}, message.mapped);
  merged.claimsforce_claim_id = message.mapped.claimsforce_claim_id;
  merged.claimsforce_termin = message.mapped.claimsforce_termin;
  merged.claimsforce_quelle = message.source;
  merged.claimsforce_zuletzt_eingelesen = message.mapped.claimsforce_zuletzt_eingelesen;
  const saved = await api(`${API}?action=save_case`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ folder_id: existing?.folderId || '', case: merged }) });
  return { folderId: saved.folder_id, meta: merged };
}

async function finishUpload(message) {
  const entry = uploads.get(message.uploadId);
  if (!entry) throw new Error('Dateiübertragung ist unvollständig.');
  uploads.delete(message.uploadId);
  const bytes = [];
  for (const encoded of entry.chunks) {
    const binary = atob(encoded), chunk = new Uint8Array(binary.length);
    for (let index = 0; index < binary.length; index++) chunk[index] = binary.charCodeAt(index);
    bytes.push(chunk);
  }
  const form = new FormData();
  form.append('folder_id', entry.folderId);
  form.append('last_modified', String(entry.modified || 0));
  form.append('file', new File(bytes, entry.name, { type: entry.mime, lastModified: entry.modified || Date.now() }));
  return api(`${API}?action=upload_case_document`, { method: 'POST', body: form });
}

async function appointment(message) {
  const loaded = await api(`${API}?action=load_case&id=${encodeURIComponent(message.folderId)}`), meta = loaded.case?.meta || {};
  if (meta.calendar_event || meta.claimsforce_calendar_appointment_id === message.appointment.id) return { skipped: true };
  const start = new Date(message.appointment.startDate), end = message.appointment.endDate ? new Date(message.appointment.endDate) : new Date(start.getTime() + 60 * 60000);
  const form = new FormData();
  form.append('folder_id', message.folderId);
  form.append('date', start.toLocaleDateString('sv-SE', { timeZone: 'Europe/Berlin' }));
  form.append('time', start.toLocaleTimeString('de-DE', { timeZone: 'Europe/Berlin', hour: '2-digit', minute: '2-digit', hour12: false }));
  form.append('duration', String(Math.max(15, Math.round((end - start) / 60000))));
  form.append('notes', message.appointment.comment || 'Aus ClaimsForce übernommen');
  form.append('invite_vn', '0');
  const result = await api(`${CAL}?action=create`, { method: 'POST', body: form });
  meta.calendar_event = result.event;
  meta.claimsforce_calendar_appointment_id = message.appointment.id || message.appointment.startDate;
  await api(`${API}?action=save_case`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ folder_id: message.folderId, case: meta }) });
  return result;
}

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  (async () => {
    if (message?.type === 'PORTAL_UPSERT') return { ok: true, ...(await upsert(message)) };
    if (message?.type === 'PORTAL_UPLOAD_START') { uploads.set(message.uploadId, { ...message, chunks: [] }); return { ok: true }; }
    if (message?.type === 'PORTAL_UPLOAD_CHUNK') { const entry = uploads.get(message.uploadId); if (!entry) throw new Error('Unbekannte Dateiübertragung.'); entry.chunks.push(message.chunk); return { ok: true }; }
    if (message?.type === 'PORTAL_UPLOAD_FINISH') return { ok: true, result: await finishUpload(message) };
    if (message?.type === 'PORTAL_APPOINTMENT') return { ok: true, result: await appointment(message) };
    return { ok: false, error: 'Unbekannter Auftrag.' };
  })().then(sendResponse).catch(error => sendResponse({ ok: false, error: error.message }));
  return true;
});

window.addEventListener('message', event => {
  if (event.source !== window || event.origin !== location.origin) return;
  if (event.data?.type === 'SVNET_CLAIMS_IMPORT_START') chrome.runtime.sendMessage({ type: 'START_IMPORT', profile: event.data.profile || 'self' }).then(response => window.postMessage({ type: response?.ok ? 'SVNET_CLAIMS_IMPORT_DONE' : 'SVNET_CLAIMS_IMPORT_ERROR', result: response?.result, error: response?.error }, location.origin)).catch(error => window.postMessage({ type: 'SVNET_CLAIMS_IMPORT_ERROR', error: error.message }, location.origin));
  if (event.data?.type === 'SVNET_CLAIMS_OPEN_OPTIONS') chrome.runtime.sendMessage({ type: 'OPEN_OPTIONS', profile: event.data.profile || 'self' });
});
chrome.runtime.onMessage.addListener(message => {
  if (message?.type === 'IMPORT_PROGRESS') window.postMessage({ type: 'SVNET_CLAIMS_IMPORT_PROGRESS', ...message }, location.origin);
  if (message?.type === 'IMPORT_ERROR') window.postMessage({ type: 'SVNET_CLAIMS_IMPORT_ERROR', error: message.error }, location.origin);
});
window.postMessage({ type: 'SVNET_CLAIMS_BRIDGE_READY' }, location.origin);
