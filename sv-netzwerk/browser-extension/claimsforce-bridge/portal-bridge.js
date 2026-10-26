const API = '/intern/api/google-drive-sync.php';
const CAL = '/intern/api/outlook-case-calendar.php';
const BRIDGE_VERSION = chrome.runtime.getManifest().version;
const PORTAL_CODE_VERSION = '1.4.79';
const CONTEXT_RELOAD_MESSAGE = 'Die Browser-Brücke wurde aktualisiert. Bitte diese Portalseite einmal neu laden und den Import danach erneut starten.';
let contextReloadReported = false;
const invalidExtensionContext = error => /Extension context invalidated|Receiving end does not exist|message port closed/i.test(String(error?.message || error || ''));
function reportInvalidExtensionContext() {
  if (contextReloadReported) return;
  contextReloadReported = true;
  document.documentElement.setAttribute('data-svnet-claims-runtime', 'unavailable|CF-EXTENSION-RELOAD|0');
  window.postMessage({ type: 'SVNET_CLAIMS_IMPORT_ERROR', bridgeInvalidated: true, error: CONTEXT_RELOAD_MESSAGE }, location.origin);
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
const operationControllers = new Map();
const PORTAL_REQUEST_TYPES = new Set(['PORTAL_UPSERT', 'PORTAL_UPLOAD_START', 'PORTAL_UPLOAD_CHUNK', 'PORTAL_UPLOAD_FINISH', 'PORTAL_APPOINTMENT', 'PORTAL_SYNC_STATE', 'PORTAL_COMMIT_SYNC', 'PORTAL_SOURCE_STATE', 'PORTAL_SOURCE_UPLOAD_START', 'PORTAL_SOURCE_UPLOAD_FINISH']);
let keepalivePort = null;
let keepaliveTimer = 0;
let activeRequest = null;
const caseNumberKey = value => String(value || '').replace(/[\s\u00ad\u2010-\u2015\u2212-]+/gu, '').toUpperCase();
const blank = value => value == null || (typeof value === 'string' && value.trim() === '');
const mergeBlank = (existing, incoming) => { const out = { ...(existing || {}) }; Object.entries(incoming || {}).forEach(([key, value]) => { if (blank(out[key]) && !blank(value)) out[key] = value; }); return out; };

async function api(url, options = {}) {
  const controller = new AbortController();
  const timeout = Number(options.timeoutMs || 30000);
  const timer = setTimeout(() => controller.abort(), timeout);
  const requestOptions = { ...options, signal: controller.signal };
  delete requestOptions.timeoutMs;
  const onAbort = () => controller.abort();
  options.signal?.addEventListener('abort', onAbort, { once: true });
  try {
    const response = await fetch(url, { credentials: 'same-origin', ...requestOptions });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.error || `HTTP ${response.status}`);
    return data;
  } finally {
    clearTimeout(timer);
    options.signal?.removeEventListener('abort', onAbort);
  }
}

async function scopedApi(profile, url, options = {}) {
  const headers = { ...(options.headers || {}), 'X-SVNET-Expert-Profile': profileKey(profile) };
  return api(url, { ...options, headers });
}

async function findCase(mapped, profile, signal) {
  const query = mapped.schaden_nr || mapped.claimsforce_claim_id || mapped.rekon_task_id;
  if (!query) return null;
  const found = await scopedApi(profile, `${API}?action=search_cases&q=${encodeURIComponent(query)}`, { signal });
  const matches = meta => (mapped.claimsforce_claim_id && meta.claimsforce_claim_id === mapped.claimsforce_claim_id) || (mapped.rekon_task_id && meta.rekon_task_id === mapped.rekon_task_id) || (mapped.schaden_nr && caseNumberKey(meta.schaden_nr) === caseNumberKey(mapped.schaden_nr));
  const rows = found.results || [];
  // Treffer mit mitgelieferten Falldaten zuerst prüfen; Einzelabrufe sind langsam und werden begrenzt.
  for (const row of rows) if (row.meta && Object.keys(row.meta).length && matches(row.meta)) return { folderId: row.id, meta: row.meta };
  const deadline = Date.now() + 40000;
  let loads = 0;
  for (const row of rows) {
    if (loads >= 8 || Date.now() > deadline) break;
    if (row.meta && Object.keys(row.meta).length) continue;
    loads++;
    const loaded = await scopedApi(profile, `${API}?action=load_case&id=${encodeURIComponent(row.id)}`, { signal });
    const meta = loaded.case?.meta || {};
    if (matches(meta)) return { folderId: loaded.case?.id || row.id, meta };
  }
  return null;
}

async function upsert(message, signal) {
  const profile = profileKey(message.profile);
  const existing = message.knownState ? (message.knownState.existed ? { folderId: message.knownState.folderId, meta: message.knownState.meta || {} } : null) : await findCase(message.mapped, profile, signal);
  const merged = mergeBlank(existing?.meta || {}, message.mapped);
  if (message.sourceType === 'rekon') {
    merged.rekon_task_id = message.mapped.rekon_task_id;
    merged.rekon_profile = profile;
    merged.rekon_termin = message.mapped.rekon_termin;
    merged.rekon_quelle = message.source;
    merged.rekon_zuletzt_eingelesen = message.mapped.rekon_zuletzt_eingelesen;
  } else {
  merged.claimsforce_claim_id = message.mapped.claimsforce_claim_id;
  merged.claimsforce_profile = profile;
  merged.claimsforce_termin = message.mapped.claimsforce_termin;
  merged.claimsforce_quelle = message.source;
  merged.claimsforce_zuletzt_eingelesen = message.mapped.claimsforce_zuletzt_eingelesen;
  }
  const saved = await scopedApi(profile, `${API}?action=save_case`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ folder_id: existing?.folderId || '', case: merged }), signal });
  return { folderId: saved.folder_id, meta: merged, existed: !!existing };
}

async function syncState(message) {
  const profile = profileKey(message.profile);
  const existing = await findCase(message.mapped || {}, profile);
  return existing ? { folderId: existing.folderId, meta: existing.meta || {}, existed: true } : { folderId: '', meta: {}, existed: false };
}

async function commitSync(message) {
  const profile = profileKey(message.profile);
  const loaded = await scopedApi(profile, `${API}?action=load_case&id=${encodeURIComponent(message.folderId)}`);
  const meta = { ...(loaded.case?.meta || {}) };
  const prefix = message.sourceType === 'rekon' ? 'rekon' : 'claimsforce';
  meta[`${prefix}_sync_signature`] = message.partial ? '' : String(message.signature || '');
  meta[`${prefix}_profile`] = profile;
  meta[`${prefix}_file_versions`] = [...new Set((message.fileVersions || []).map(String).filter(Boolean))];
  meta[`${prefix}_message_versions`] = [...new Set((message.messageVersions || []).map(String).filter(Boolean))];
  // Notes are persisted only by merge_claimsforce_notes; ordinary imports preserve them.
  if (prefix === 'claimsforce' && Array.isArray(message.tasks)) meta.claimsforce_aufgaben = message.tasks;
  if (prefix === 'claimsforce' && message.inventory) meta.claimsforce_importbestand = message.inventory;
  meta[`${prefix}_list_version`] = String(message.listVersion || '');
  meta[`${prefix}_zuletzt_eingelesen`] = new Date().toISOString();
  await scopedApi(profile, `${API}?action=save_case`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ folder_id: message.folderId, case: meta }) });
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
  if (entry.supportingIdentity) form.append('supporting_identity', JSON.stringify(entry.supportingIdentity));
  form.append('file', new File(bytes, entry.name, { type: entry.mime, lastModified: entry.modified || Date.now() }));
  return scopedApi(entry.profile, `${API}?action=upload_case_document`, { method: 'POST', body: form });
}

async function appointment(message) {
  const profile = profileKey(message.profile);
  const loaded = await scopedApi(profile, `${API}?action=load_case&id=${encodeURIComponent(message.folderId)}`), meta = loaded.case?.meta || {};
  const appointmentId = String(message.appointment.id || message.appointment.startDate || '');
  const prefix = message.sourceType === 'rekon' ? 'rekon' : 'claimsforce';
  const idsKey = `${prefix}_calendar_appointment_ids`, idKey = `${prefix}_calendar_appointment_id`, eventsKey = `${prefix}_calendar_events`;
  const imported = Array.isArray(meta[idsKey]) ? meta[idsKey].map(String) : (meta[idKey] ? [String(meta[idKey])] : []);
  if (appointmentId && imported.includes(appointmentId)) return { skipped: true };
  const start = new Date(message.appointment.startDate), end = message.appointment.endDate ? new Date(message.appointment.endDate) : new Date(start.getTime() + 60 * 60000);
  const form = new FormData();
  form.append('folder_id', message.folderId);
  form.append('claims_profile', profile);
  form.append('date', start.toLocaleDateString('sv-SE', { timeZone: 'Europe/Berlin' }));
  form.append('time', start.toLocaleTimeString('de-DE', { timeZone: 'Europe/Berlin', hour: '2-digit', minute: '2-digit', hour12: false }));
  form.append('duration', String(Math.max(15, Math.round((end - start) / 60000))));
  form.append('notes', message.appointment.comment || (message.sourceType === 'rekon' ? 'Aus Rekon übernommen' : 'Aus ClaimsForce übernommen'));
  form.append('invite_vn', '0');
  const result = await scopedApi(profile, `${CAL}?action=create`, { method: 'POST', body: form });
  if (appointmentId) imported.push(appointmentId);
  meta[idsKey] = [...new Set(imported)];
  meta[idKey] = appointmentId;
  meta[eventsKey] = [...(Array.isArray(meta[eventsKey]) ? meta[eventsKey] : []), result.event].filter(Boolean);
  await scopedApi(profile, `${API}?action=save_case`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ folder_id: message.folderId, case: meta }) });
  return result;
}

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  if (typeof message?.type === 'string' && message.type.startsWith('PORTAL_SOURCE_') && _sender?.id !== chrome.runtime.id) { sendResponse({ ok: false, error: 'SOURCE_SENDER_REJECTED' }); return; }
  if (message?.type === 'PORTAL_UPSERT_ASYNC') {
    const operationId = String(message.operationId || '');
    if (!operationId) { sendResponse({ ok: false, error: 'Portal-Operations-ID fehlt.' }); return; }
    if (!operations.has(operationId)) {
      operations.set(operationId, { status: 'running' });
      const controller = new AbortController();
      operationControllers.set(operationId, controller);
      upsert(message, controller.signal).then(result => operations.set(operationId, { status: 'done', result })).catch(error => operations.set(operationId, { status: 'failed', error: error.name === 'AbortError' ? 'Portal-Falloperation wurde wegen Zeitüberschreitung abgebrochen.' : error.message })).finally(() => operationControllers.delete(operationId));
    }
    sendResponse({ ok: true, accepted: true, operationId });
    return;
  }
  if (message?.type === 'PORTAL_OPERATION_CANCEL') {
    const operationId = String(message.operationId || ''), controller = operationControllers.get(operationId);
    if (controller) controller.abort();
    if (operations.get(operationId)?.status === 'running') operations.set(operationId, { status: 'failed', error: 'Portal-Falloperation wurde wegen Zeitüberschreitung abgebrochen.' });
    sendResponse({ ok: true, cancelled: true });
    return;
  }
  if (message?.type === 'PORTAL_OPERATION_STATUS') {
    const operation = operations.get(String(message.operationId || ''));
    sendResponse({ ok: true, operation: operation || { status: 'missing' } });
    return;
  }
  if (!PORTAL_REQUEST_TYPES.has(message?.type)) return;
  (async () => {
    if (message?.type === 'PORTAL_SOURCE_STATE') return { ok: true, result: await readSourceState(message) };
    if (message?.type === 'PORTAL_SOURCE_UPLOAD_START') { if (!message.sourceIdentity || !message.originalReadState || !message.sourceSha256) throw new Error('SOURCE_CONTEXT_REQUIRED'); uploads.set(message.uploadId, { ...message, chunks: [] }); return { ok: true }; }
    if (message?.type === 'PORTAL_SOURCE_UPLOAD_FINISH') return { ok: true, result: await finishSourceUpload(message) };
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
  return Promise.resolve().then(() => chrome.runtime.sendMessage({ type: 'GET_RUNTIME_STATUS' })).then(status => {
    const active = status?.active || {}, diagnostic = status?.diagnostic || {};
    document.documentElement.setAttribute('data-svnet-claims-runtime', [active.status || 'idle', diagnostic.phase || active.phase || 'CF-IDLE', Number(active.jobId || 0), active.profile || 'none'].join('|'));
    const rekon = status?.rekon || {};
    document.documentElement.setAttribute('data-svnet-rekon-runtime', [rekon.status || 'idle', rekon.runId || 'none', rekon.profile || 'none', rekon.current || 0, rekon.total || 0].join('|'));
    if (rekon.status === 'running' && rekon.text) window.postMessage({ type: 'SVNET_REKON_IMPORT_PROGRESS', text: rekon.text, current: rekon.current || 0, total: rekon.total || 0 }, location.origin);
    document.documentElement.setAttribute('data-svnet-claims-worker-version', status?.workerVersion || 'nicht geladen');
    window.postMessage({ type: 'SVNET_CLAIMS_BRIDGE_READY_V2', version: BRIDGE_VERSION, portalVersion: PORTAL_CODE_VERSION, workerVersion: status?.workerVersion || '', protocol: status?.protocol || 0 }, location.origin);
    window.postMessage({ type: 'SVNET_CLAIMS_RUNTIME_STATUS_V2', status }, location.origin);
  }).catch(error => {
    if (invalidExtensionContext(error)) reportInvalidExtensionContext();
    else document.documentElement.setAttribute('data-svnet-claims-runtime', 'unavailable|CF-RUNTIME|0');
  });
}

window.addEventListener('message', event => {
  if (event.source !== window || event.origin !== location.origin) return;
  if (event.data?.type === 'SVNET_CLAIMS_BRIDGE_PING') reportRuntime();
  if (event.data?.type === 'SVNET_CLAIMS_RUNTIME_PING') reportRuntime();
  if (event.data?.type === 'SVNET_CLAIMS_CREDENTIAL_CHECK') {
    const requestId = String(event.data.requestId || '');
    let profile;
    try { profile = profileKey(event.data.profile); }
    catch (error) {
      window.postMessage({ type: 'SVNET_CLAIMS_CREDENTIAL_STATUS', requestId, ok: false, profile: '', phase: 'invalid-profile' }, location.origin);
      return;
    }
    chrome.runtime.sendMessage({ type: 'CHECK_PROFILE_CREDENTIALS', profile }).then(status => {
      window.postMessage({
        type: 'SVNET_CLAIMS_CREDENTIAL_STATUS',
        requestId,
        ok: status?.ok === true,
        profile,
        phase: String(status?.phase || 'unknown')
      }, location.origin);
    }).catch(error => {
      if (invalidExtensionContext(error)) reportInvalidExtensionContext();
      window.postMessage({ type: 'SVNET_CLAIMS_CREDENTIAL_STATUS', requestId, ok: false, profile, phase: 'bridge-unavailable' }, location.origin);
    });
  }
  if (event.data?.type === 'SVNET_CLAIMS_NATIVE_FORM_READONLY') {
    const requestId=String(event.data.requestId||'');
    if(!requestId)return;
    chrome.runtime.sendMessage({type:'OPEN_NATIVE_FORM_READONLY',profile:event.data.profile,caseId:event.data.caseId,formId:event.data.formId,tabId:event.data.tabId,requiredFields:event.data.requiredFields}).then(response=>window.postMessage({type:'SVNET_CLAIMS_NATIVE_FORM_RESULT',requestId,...response},location.origin)).catch(error=>window.postMessage({type:'SVNET_CLAIMS_NATIVE_FORM_RESULT',requestId,ok:false,error:error.message},location.origin));
  }
  if (event.data?.type === 'SVNET_CLAIMS_NOTES_READ') {
    const requestId=String(event.data.requestId||'');
    if(!requestId)return;
    chrome.runtime.sendMessage({type:'READ_CLAIMS_NOTES',identity:event.data.identity}).then(response=>{
      window.postMessage({type:'SVNET_CLAIMS_NOTES_RESULT',requestId,...response},location.origin);
    }).catch(error=>window.postMessage({type:'SVNET_CLAIMS_NOTES_RESULT',requestId,ok:false,error:error.message},location.origin));
  }
  if (event.data?.type === 'SVNET_CLAIMS_IMPORT_START_V2') {
    let profile;
    try { profile = profileKey(event.data.profile); }
    catch (error) { window.postMessage({ type: 'SVNET_CLAIMS_IMPORT_ERROR', error: error.message, runtime: { jobId: Number(event.data.jobId || 0) } }, location.origin); return; }
    activeRequest = {
      type: 'START_IMPORT_V2',
      protocol: 2,
      workerVersion: PORTAL_CODE_VERSION,
      profile,
      jobId: Number(event.data.jobId || 0),
      runId: event.data.runId || crypto.randomUUID(),
      ...(event.data.mode==='nativeform'?{nativeRequest:event.data.nativeRequest||null}:{}),
      mode: event.data.mode === 'nativeform' ? 'nativeform' : event.data.mode === 'source' ? 'source' : String(event.data.claimNumber || '').trim() ? 'single' : event.data.mode === 'tasks' ? 'tasks' : event.data.mode === 'single' ? 'single' : event.data.mode === 'full' || event.data.full ? 'full' : 'quick',
      full: event.data.full === true,
      claimNumber: String(event.data.claimNumber || ''),
      sourceProfiles: Array.isArray(event.data.sourceProfiles)?event.data.sourceProfiles:[],
      since: String(event.data.since || '')
    };
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
  if (event.data?.type === 'SVNET_CLAIMS_IMPORT_STOP') chrome.runtime.sendMessage({ type: 'STOP_IMPORT', jobId: Number(event.data.jobId || 0) }).catch(() => {});
  if (event.data?.type === 'SVNET_REKON_IMPORT_START') {
    let profile;
    try { profile = profileKey(event.data.profile); }
    catch (error) { window.postMessage({ type: 'SVNET_REKON_IMPORT_ERROR', error: error.message }, location.origin); return; }
    connectKeepalive();
    window.postMessage({ type: 'SVNET_REKON_IMPORT_PROGRESS', text: 'Startsignal wurde an die Browser-Brücke übergeben …', current: 0, total: 0 }, location.origin);
    let startTimer = 0;
    const startRequest = chrome.runtime.sendMessage({ type: 'START_REKON_IMPORT', profile, runId: event.data.runId || crypto.randomUUID() });
    Promise.race([
      startRequest,
      new Promise((_, reject) => { startTimer = setTimeout(() => reject(new Error('Die Browser-Brücke hat den Rekon-Start nicht innerhalb von 10 Sekunden bestätigt.')), 10000); })
    ]).then(response => {
      if (!response?.ok) window.postMessage({ type: 'SVNET_REKON_IMPORT_ERROR', error: response?.error }, location.origin);
      else window.postMessage({ type: 'SVNET_REKON_IMPORT_ACCEPTED', runId: response.runId }, location.origin);
    }).catch(error => {
      const invalid = invalidExtensionContext(error);
      if (invalid) reportInvalidExtensionContext();
      window.postMessage({ type: 'SVNET_REKON_IMPORT_ERROR', error: invalid ? CONTEXT_RELOAD_MESSAGE : error.message }, location.origin);
    }).finally(() => clearTimeout(startTimer));
  }
  if (event.data?.type === 'SVNET_CLAIMS_OPEN_OPTIONS') {
    try { chrome.runtime.sendMessage({ type: 'OPEN_OPTIONS', profile: profileKey(event.data.profile) }); }
    catch (error) { window.postMessage({ type: 'SVNET_CLAIMS_IMPORT_ERROR', error: error.message }, location.origin); }
  }
  if (event.data?.type === 'SVNET_KUSS_SCAN_START') {
    chrome.runtime.sendMessage({ type: 'KUSS_SCAN_JOBS', all: true }).then(result => {
      window.postMessage({ type: 'SVNET_KUSS_SCAN_RESULT', ...result }, location.origin);
    }).catch(error => window.postMessage({ type: 'SVNET_KUSS_SCAN_RESULT', ok: false, error: error.message }, location.origin));
  }
});
chrome.runtime.onMessage.addListener(message => {
  if (message?.type === 'IMPORT_PROGRESS') window.postMessage({ type: 'SVNET_CLAIMS_IMPORT_PROGRESS', ...message }, location.origin);
  if (message?.type === 'IMPORT_DONE') { activeRequest = null; disconnectKeepalive(); window.postMessage({ type: 'SVNET_CLAIMS_IMPORT_DONE', result: message.result, runtime: message.runtime }, location.origin); }
  if (message?.type === 'IMPORT_ERROR') { activeRequest = null; disconnectKeepalive(); window.postMessage({ type: 'SVNET_CLAIMS_IMPORT_ERROR', error: message.error, runtime: message.runtime }, location.origin); }
  if (message?.type === 'REKON_IMPORT_PROGRESS') window.postMessage({ type: 'SVNET_REKON_IMPORT_PROGRESS', ...message }, location.origin);
  if (message?.type === 'REKON_IMPORT_DONE') { disconnectKeepalive(); window.postMessage({ type: 'SVNET_REKON_IMPORT_DONE', result: message.result }, location.origin); }
  if (message?.type === 'REKON_IMPORT_ERROR') { disconnectKeepalive(); window.postMessage({ type: 'SVNET_REKON_IMPORT_ERROR', error: message.error }, location.origin); }
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
reportRuntime();
setInterval(reportRuntime, 5000);

// Source refresh uses stable Claims identity and read-before-write CAS.
function validateSourceState(state, identity) {
  const uuid = /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/;
  if (!identity || !uuid.test(identity.claimId || '') || !uuid.test(identity.sourceFileId || '') || !['christian', 'marc', 'holger', 'jens'].includes(identity.profile) || !identity.targetFolderId) throw new Error('SOURCE_CONTEXT_REQUIRED');
  if (!state || !['NEW_SOURCE', 'EXISTING_SOURCE'].includes(state.kind) || !/^[a-f0-9]{64}$/.test(state.sourceHash || '') || state.context?.profile !== identity.profile || state.context?.caseId !== identity.claimId || state.context?.sourceFileId !== identity.sourceFileId || state.context?.targetFolderId !== identity.targetFolderId) throw new Error('SOURCE_READ_STATE_IDENTITY_MISMATCH');
  if (!Array.isArray(state.context.contextRecords) || !state.context.contextRecords.length || state.context.contextRecords.some(r => !r.id || !/^[a-f0-9]{64}$/.test(r.rowFingerprint || '') || !['content', 'metadata'].includes(r.kind) || (r.kind === 'content' && !/^[a-f0-9]{64}$/.test(r.sha256 || '')))) throw new Error('SOURCE_CONTEXT_VERSION_REQUIRED');
  if (state.kind === 'EXISTING_SOURCE' && (!state.portalFileId || state.originalPrecondition?.id !== state.portalFileId || !/^[a-f0-9]{64}$/.test(state.originalPrecondition?.rowFingerprint || '') || !/^[a-f0-9]{64}$/.test(state.originalPrecondition?.sha256 || ''))) throw new Error('SOURCE_ORIGINAL_READ_REQUIRED');
  return state;
}
async function readSourceState(message) {
  if (!['christian', 'marc', 'holger', 'jens'].includes(message.profile) || !message.folderId || !message.sourceIdentity || message.sourceIdentity.profile !== profileKey(message.profile)) throw new Error('SOURCE_CONTEXT_REQUIRED');
  const result = await scopedApi(message.profile, `${API}?action=claimsforce_source_state`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ folder_id: message.folderId, source_identity: message.sourceIdentity }) });
  validateSourceState(result.source_state, message.sourceIdentity); return result;
}
async function sourceOperationId(state, newHash) {
  if (typeof crypto === 'undefined' || !crypto.subtle) throw new Error('SOURCE_CRYPTO_UNAVAILABLE');
  const records = state.context.contextRecords.map(r => [r.id, r.kind, r.rowFingerprint, r.sha256 || null]);
  const payload = ['claimsforce-source-refresh-v1', state.sourceHash, state.kind, state.context.targetFolderId, state.portalFileId || null, state.originalPrecondition?.rowFingerprint || null, state.originalPrecondition?.sha256 || null, records, newHash];
  return [...new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(JSON.stringify(payload))))].map(v => v.toString(16).padStart(2, '0')).join('');
}
async function finishSourceUpload(message) {
  const entry = uploads.get(message.uploadId);
  if (!entry) throw new Error('SOURCE_UPLOAD_INCOMPLETE');
  uploads.delete(message.uploadId);
  if (!['christian', 'marc', 'holger', 'jens'].includes(entry.profile) || !entry.folderId || !entry.sourceIdentity || !entry.originalReadState || entry.sourceIdentity.profile !== profileKey(entry.profile) || !/^[a-f0-9]{64}$/.test(entry.sourceSha256 || '')) throw new Error('SOURCE_CONTEXT_REQUIRED');
  validateSourceState(entry.originalReadState, entry.sourceIdentity);
  const operationId = await sourceOperationId(entry.originalReadState, entry.sourceSha256);
  const bytes = [];
  for (const encoded of entry.chunks) { const binary = atob(encoded), chunk = new Uint8Array(binary.length); for (let index = 0; index < binary.length; index++) chunk[index] = binary.charCodeAt(index); bytes.push(chunk); }
  const form = new FormData();
  form.append('folder_id', entry.folderId);
  form.append('source_identity', JSON.stringify(entry.sourceIdentity));
  form.append('original_read_state', JSON.stringify(entry.originalReadState));
  form.append('source_sha256', entry.sourceSha256);
  form.append('operation_id', operationId);
  form.append('file', new File(bytes, entry.name, { type: entry.mime || 'application/octet-stream' }));
  const result = await scopedApi(entry.profile, `${API}?action=claimsforce_source_upload`, { method: 'POST', body: form });
  const state = validateSourceState(result.sourceState, entry.sourceIdentity);
  if (result.operationId !== operationId || state.kind !== 'EXISTING_SOURCE' || state.originalPrecondition?.sha256 !== entry.sourceSha256 || result.file?.id !== state.portalFileId || (entry.originalReadState.kind === 'EXISTING_SOURCE' && result.file.id !== entry.originalReadState.portalFileId)) throw new Error('SOURCE_READBACK_MISMATCH');
  return result;
}
