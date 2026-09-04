const API = '/intern/api/google-drive-sync.php';
const CAL = '/intern/api/outlook-case-calendar.php';
const BRIDGE_VERSION = chrome.runtime.getManifest().version;
const CONTEXT_RELOAD_MESSAGE = 'Die Browser-Brücke wurde aktualisiert. Bitte diese Portalseite einmal neu laden und den Import danach erneut starten.';
let contextReloadReported = false;
const invalidExtensionContext = error => /Extension context invalidated|Receiving end does not exist|message port closed/i.test(String(error?.message || error || ''));
function reportInvalidExtensionContext() {
  if (contextReloadReported) return;
  contextReloadReported = true;
  document.documentElement.setAttribute('data-svnet-claims-runtime', 'unavailable|CF-EXTENSION-RELOAD|0');
  window.postMessage({ type: 'SVNET_CLAIMS_IMPORT_ERROR', error: CONTEXT_RELOAD_MESSAGE }, location.origin);
}
function safeSendResponse(sendResponse, value) {
  try { sendResponse(value); }
  catch (error) { if (invalidExtensionContext(error)) reportInvalidExtensionContext(); }
}
const SUPPORTED_PROFILES = ['christian', 'holger', 'marc', 'jens'];
const profileKey = value => {
  const profile = String(value || '').trim().toLowerCase();
  if (!SUPPORTED_PROFILES.includes(profile)) throw new Error('Ungültiges ClaimsForce-Profil.');
  return profile;
};
const uploads = new Map();
const operations = new Map();
let keepalivePort = null;
let keepaliveTimer = 0;
let activeRequest = null;
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
  const profile = profileKey(message.profile);
  const existing = await findCase(message.mapped);
  const merged = mergeBlank(existing?.meta || {}, message.mapped);
  merged.claimsforce_claim_id = message.mapped.claimsforce_claim_id;
  merged.claimsforce_profile = profile;
  merged.claimsforce_termin = message.mapped.claimsforce_termin;
  merged.claimsforce_quelle = message.source;
  merged.claimsforce_zuletzt_eingelesen = message.mapped.claimsforce_zuletzt_eingelesen;
  const saved = await api(`${API}?action=save_case`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ folder_id: existing?.folderId || '', case: merged }) });
  return { folderId: saved.folder_id, meta: merged, existed: !!existing };
}

async function syncState(message) {
  const existing = await findCase(message.mapped || {});
  return existing ? { folderId: existing.folderId, meta: existing.meta || {}, existed: true } : { folderId: '', meta: {}, existed: false };
}

async function commitSync(message) {
  const profile = profileKey(message.profile);
  const loaded = await api(`${API}?action=load_case&id=${encodeURIComponent(message.folderId)}`);
  const meta = { ...(loaded.case?.meta || {}) };
  meta.claimsforce_sync_signature = String(message.signature || '');
  meta.claimsforce_profile = profile;
  meta.claimsforce_file_versions = [...new Set((message.fileVersions || []).map(String).filter(Boolean))];
  meta.claimsforce_message_versions = [...new Set((message.messageVersions || []).map(String).filter(Boolean))];
  meta.claimsforce_list_version = String(message.listVersion || '');
  meta.claimsforce_zuletzt_eingelesen = new Date().toISOString();
  await api(`${API}?action=save_case`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ folder_id: message.folderId, case: meta }) });
  return { folderId: message.folderId, meta };
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
  const profile = profileKey(message.profile);
  const loaded = await api(`${API}?action=load_case&id=${encodeURIComponent(message.folderId)}`), meta = loaded.case?.meta || {};
  const appointmentId = String(message.appointment.id || message.appointment.startDate || '');
  const imported = Array.isArray(meta.claimsforce_calendar_appointment_ids) ? meta.claimsforce_calendar_appointment_ids.map(String) : (meta.claimsforce_calendar_appointment_id ? [String(meta.claimsforce_calendar_appointment_id)] : []);
  if (appointmentId && imported.includes(appointmentId)) return { skipped: true };
  const start = new Date(message.appointment.startDate), end = message.appointment.endDate ? new Date(message.appointment.endDate) : new Date(start.getTime() + 60 * 60000);
  const form = new FormData();
  form.append('folder_id', message.folderId);
  form.append('claims_profile', profile);
  form.append('date', start.toLocaleDateString('sv-SE', { timeZone: 'Europe/Berlin' }));
  form.append('time', start.toLocaleTimeString('de-DE', { timeZone: 'Europe/Berlin', hour: '2-digit', minute: '2-digit', hour12: false }));
  form.append('duration', String(Math.max(15, Math.round((end - start) / 60000))));
  form.append('notes', message.appointment.comment || 'Aus ClaimsForce übernommen');
  form.append('invite_vn', '0');
  const result = await api(`${CAL}?action=create`, { method: 'POST', body: form });
  if (appointmentId) imported.push(appointmentId);
  meta.claimsforce_calendar_appointment_ids = [...new Set(imported)];
  meta.claimsforce_calendar_appointment_id = appointmentId;
  meta.claimsforce_calendar_events = [...(Array.isArray(meta.claimsforce_calendar_events) ? meta.claimsforce_calendar_events : []), result.event].filter(Boolean);
  await api(`${API}?action=save_case`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ folder_id: message.folderId, case: meta }) });
  return result;
}

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  if (message?.type === 'PORTAL_UPSERT_ASYNC') {
    const operationId = String(message.operationId || '');
    if (!operationId) { sendResponse({ ok: false, error: 'Portal-Operations-ID fehlt.' }); return; }
    if (!operations.has(operationId)) {
      operations.set(operationId, { status: 'running' });
      upsert(message).then(result => operations.set(operationId, { status: 'done', result })).catch(error => operations.set(operationId, { status: 'failed', error: error.message }));
    }
    sendResponse({ ok: true, accepted: true, operationId });
    return;
  }
  if (message?.type === 'PORTAL_OPERATION_STATUS') {
    const operation = operations.get(String(message.operationId || ''));
    sendResponse({ ok: true, operation: operation || { status: 'missing' } });
    return;
  }
  (async () => {
    if (message?.type === 'PORTAL_UPSERT') return { ok: true, ...(await upsert(message)) };
    if (message?.type === 'PORTAL_UPLOAD_START') { uploads.set(message.uploadId, { ...message, chunks: [] }); return { ok: true }; }
    if (message?.type === 'PORTAL_UPLOAD_CHUNK') { const entry = uploads.get(message.uploadId); if (!entry) throw new Error('Unbekannte Dateiübertragung.'); entry.chunks.push(message.chunk); return { ok: true }; }
    if (message?.type === 'PORTAL_UPLOAD_FINISH') return { ok: true, result: await finishUpload(message) };
    if (message?.type === 'PORTAL_APPOINTMENT') return { ok: true, result: await appointment(message) };
    if (message?.type === 'PORTAL_SYNC_STATE') return { ok: true, result: await syncState(message) };
    if (message?.type === 'PORTAL_COMMIT_SYNC') return { ok: true, result: await commitSync(message) };
    return { ok: false, error: 'Unbekannter Auftrag.' };
  })().then(value => safeSendResponse(sendResponse, value)).catch(error => {
    const invalid = invalidExtensionContext(error);
    if (invalid) reportInvalidExtensionContext();
    safeSendResponse(sendResponse, { ok: false, error: invalid ? CONTEXT_RELOAD_MESSAGE : error.message });
  });
  return true;
});

function reportRuntime() {
  return chrome.runtime.sendMessage({ type: 'GET_RUNTIME_STATUS' }).then(status => {
    const active = status?.active || {}, diagnostic = status?.diagnostic || {};
    document.documentElement.setAttribute('data-svnet-claims-runtime', [active.status || 'idle', diagnostic.phase || active.phase || 'CF-IDLE', Number(active.jobId || 0), active.profile || 'none'].join('|'));
    window.postMessage({ type: 'SVNET_CLAIMS_RUNTIME_STATUS', status }, location.origin);
  }).catch(error => {
    if (invalidExtensionContext(error)) reportInvalidExtensionContext();
    else document.documentElement.setAttribute('data-svnet-claims-runtime', 'unavailable|CF-RUNTIME|0');
  });
}

window.addEventListener('message', event => {
  if (event.source !== window || event.origin !== location.origin) return;
  if (event.data?.type === 'SVNET_CLAIMS_BRIDGE_PING') window.postMessage({ type: 'SVNET_CLAIMS_BRIDGE_READY', version: BRIDGE_VERSION }, location.origin);
  if (event.data?.type === 'SVNET_CLAIMS_RUNTIME_PING') reportRuntime();
  if (event.data?.type === 'SVNET_CLAIMS_IMPORT_START') {
    let profile;
    try { profile = profileKey(event.data.profile); }
    catch (error) { window.postMessage({ type: 'SVNET_CLAIMS_IMPORT_ERROR', error: error.message, runtime: { jobId: Number(event.data.jobId || 0) } }, location.origin); return; }
    activeRequest = { type: 'START_IMPORT', profile, jobId: Number(event.data.jobId || 0), runId: event.data.runId || crypto.randomUUID() };
    const request = activeRequest;
    connectKeepalive();
    chrome.runtime.sendMessage(request).then(response => {
      if (!response?.ok) window.postMessage({ type: 'SVNET_CLAIMS_IMPORT_ERROR', error: response?.error, runtime: { jobId: request.jobId } }, location.origin);
      else window.postMessage({ type: 'SVNET_CLAIMS_IMPORT_ACCEPTED', runtime: { jobId: request.jobId, runId: response.runId, resumed: response.resumed } }, location.origin);
    }).catch(error => {
      const invalid = invalidExtensionContext(error);
      if (invalid) reportInvalidExtensionContext();
      window.postMessage({ type: 'SVNET_CLAIMS_IMPORT_ERROR', error: invalid ? CONTEXT_RELOAD_MESSAGE : `[CF-RUN-00] ${error.message}`, runtime: { jobId: request.jobId } }, location.origin);
    });
  }
  if (event.data?.type === 'SVNET_CLAIMS_OPEN_OPTIONS') {
    try { chrome.runtime.sendMessage({ type: 'OPEN_OPTIONS', profile: profileKey(event.data.profile) }); }
    catch (error) { window.postMessage({ type: 'SVNET_CLAIMS_IMPORT_ERROR', error: error.message }, location.origin); }
  }
});
chrome.runtime.onMessage.addListener(message => {
  if (message?.type === 'IMPORT_PROGRESS') window.postMessage({ type: 'SVNET_CLAIMS_IMPORT_PROGRESS', ...message }, location.origin);
  if (message?.type === 'IMPORT_DONE') { activeRequest = null; disconnectKeepalive(); window.postMessage({ type: 'SVNET_CLAIMS_IMPORT_DONE', result: message.result, runtime: message.runtime }, location.origin); }
  if (message?.type === 'IMPORT_ERROR') { activeRequest = null; disconnectKeepalive(); window.postMessage({ type: 'SVNET_CLAIMS_IMPORT_ERROR', error: message.error, runtime: message.runtime }, location.origin); }
});
function disconnectKeepalive() {
  clearInterval(keepaliveTimer);
  keepaliveTimer = 0;
  try { keepalivePort?.disconnect(); } catch {}
  keepalivePort = null;
}
function connectKeepalive() {
  if (keepalivePort) return;
  try {
    keepalivePort = chrome.runtime.connect({ name: 'claims-import-keepalive' });
    keepalivePort.onDisconnect.addListener(() => {
      keepalivePort = null;
      clearInterval(keepaliveTimer);
      keepaliveTimer = 0;
    });
    keepaliveTimer = setInterval(() => { try { keepalivePort?.postMessage({ type: 'KEEPALIVE', at: Date.now() }); } catch {} }, 15000);
  } catch {}
}
window.postMessage({ type: 'SVNET_CLAIMS_BRIDGE_READY', version: BRIDGE_VERSION }, location.origin);
reportRuntime();
setInterval(reportRuntime, 5000);
