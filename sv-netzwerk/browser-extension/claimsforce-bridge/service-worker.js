import { loadCredentials, loadPortalCredentials } from './vault.js';
import { mapClaim, safeFileName } from './import-utils.js';

const PLANNING_URL = 'https://web.claimsforce.com/planning?bucket=WITH_FUTURE_APPOINTMENT#with-future-appointment';
const CREDENTIAL_HOST = 'eu.svnetzwerk.claimsforce_credentials';
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const authHeaders = token => ({ Authorization: `Bearer ${token}`, Accept: 'application/json' });

async function credentialsFor(profile) {
  const saved = await loadCredentials(profile);
  if (saved?.email && saved?.password) return saved;
  try {
    const local = await chrome.runtime.sendNativeMessage(CREDENTIAL_HOST, { profile });
    return local?.email && local?.password ? local : null;
  } catch { return null; }
}

async function tokenValue() {
  return (await chrome.storage.session.get('claimsToken')).claimsToken || '';
}

async function waitForToken(timeout = 30000) {
  const deadline = Date.now() + timeout;
  while (Date.now() < deadline) {
    const token = await tokenValue();
    if (token) return token;
    await sleep(500);
  }
  throw new Error('ClaimsForce-Anmeldung konnte nicht übernommen werden. Bitte Zugangsdaten in der Browser-Brücke prüfen.');
}

async function waitTab(tabId, timeout = 30000) {
  const deadline = Date.now() + timeout;
  while (Date.now() < deadline) {
    const tab = await chrome.tabs.get(tabId);
    if (tab.status === 'complete') { await sleep(1200); return tab; }
    await sleep(300);
  }
  throw new Error('ClaimsForce hat nicht rechtzeitig geladen.');
}

async function claimsTab() {
  let [tab] = await chrome.tabs.query({ url: 'https://web.claimsforce.com/*' });
  if (!tab) tab = await chrome.tabs.create({ url: PLANNING_URL, active: false });
  if (!String(tab.url || '').includes('/planning')) tab = await chrome.tabs.update(tab.id, { url: PLANNING_URL });
  await waitTab(tab.id);
  let token = await tokenValue();
  if (!token) {
    await chrome.tabs.reload(tab.id);
    await waitTab(tab.id);
    token = await waitForToken();
  }
  return { tab, token };
}

async function requestJson(url, token, optional = false) {
  const response = await fetch(url, { headers: authHeaders(token) });
  if (optional && response.status === 404) return null;
  if (!response.ok) {
    if (optional) return null;
    throw new Error(`ClaimsForce-Abruf fehlgeschlagen (${response.status}).`);
  }
  return response.json();
}

async function portal(tabId, message) {
  const response = await chrome.tabs.sendMessage(tabId, message);
  if (!response?.ok) throw new Error(response?.error || 'Das SV-Netzwerk hat den Import nicht angenommen.');
  return response;
}

async function progress(tabId, text, current = 0, total = 0) {
  await chrome.tabs.sendMessage(tabId, { type: 'IMPORT_PROGRESS', text, current, total }).catch(() => {});
}

function unwrap(data, key) {
  return data?.[key] ?? data ?? {};
}

async function uploadBuffer(portalTabId, folderId, name, mime, modified, buffer) {
  const uploadId = crypto.randomUUID();
  await portal(portalTabId, { type: 'PORTAL_UPLOAD_START', uploadId, folderId, name: safeFileName(name), mime: mime || 'application/octet-stream', modified: modified || 0 });
  const bytes = new Uint8Array(buffer), size = 384 * 1024;
  for (let offset = 0; offset < bytes.length; offset += size) {
    const chunk = bytes.subarray(offset, Math.min(offset + size, bytes.length));
    let binary = '';
    for (let index = 0; index < chunk.length; index += 0x8000) binary += String.fromCharCode(...chunk.subarray(index, index + 0x8000));
    await portal(portalTabId, { type: 'PORTAL_UPLOAD_CHUNK', uploadId, chunk: btoa(binary) });
  }
  return portal(portalTabId, { type: 'PORTAL_UPLOAD_FINISH', uploadId });
}

async function runImport(portalTabId, profile = 'self') {
  const previous = await chrome.storage.session.get(['activeProfile', 'claimsLoggedProfile']);
  await chrome.storage.session.set({ activeProfile: profile });
  if (profile !== 'self') {
    const currentProfile = previous.claimsLoggedProfile || previous.activeProfile || '';
    if (currentProfile && currentProfile !== profile) {
      const [open] = await chrome.tabs.query({ url: 'https://web.claimsforce.com/*' });
      await chrome.storage.session.remove(['claimsToken', 'claimsLoggedProfile']);
      if (open?.id) {
        await chrome.tabs.update(open.id, { url: 'https://web.claimsforce.com/logout' });
        await waitTab(open.id);
        await sleep(900);
      }
    } else {
      await chrome.storage.session.set({ claimsLoggedProfile: profile });
    }
  }
  await progress(portalTabId, 'ClaimsForce wird geöffnet …');
  const { tab, token } = await claimsTab();
  await chrome.tabs.update(tab.id, { url: PLANNING_URL });
  await waitTab(tab.id);
  const scraped = await chrome.tabs.sendMessage(tab.id, { type: 'SCRAPE_CLAIMS' });
  const claims = scraped?.claims || [];
  if (!claims.length) throw new Error('Unter „Mit Termin“ wurden keine Aufträge gefunden. Bitte ClaimsForce öffnen und die Ansicht vollständig laden.');
  const config = await (await fetch('https://web.claimsforce.com/config')).json();
  let filesDone = 0, messagesDone = 0;
  for (let index = 0; index < claims.length; index++) {
    const item = claims[index], id = item.id;
    await progress(portalTabId, `Auftrag ${index + 1}/${claims.length} wird eingelesen …`, index, claims.length);
    const [rawDisposition, rawCommunication, rawFiles, rawMessages, rawAppointments, rawStakeholders] = await Promise.all([
      requestJson(`${config.DISPOSITION_API_ENDPOINT}/claims/${id}`, token),
      requestJson(`${config.COMMUNICATION_API_ENDPOINT}/claims/${id}`, token, true),
      requestJson(`${config.FILES_API_ENDPOINT}/claims/${id}/files`, token, true),
      requestJson(`${config.COMMUNICATION_API_ENDPOINT}/claims/${id}/messages`, token, true),
      requestJson(`${config.COMMUNICATION_API_ENDPOINT}/claims/${id}/appointments`, token, true),
      requestJson(`${config.COMMUNICATION_API_ENDPOINT}/claims/${id}/stakeholders`, token, true)
    ]);
    const disposition = unwrap(rawDisposition, 'claim');
    const communication = unwrap(rawCommunication, 'claim');
    disposition.id ||= id;
    const appointments = unwrap(rawAppointments, 'appointments');
    const mapped = mapClaim(disposition, communication, Array.isArray(appointments) ? appointments : []);
    const upsert = await portal(portalTabId, { type: 'PORTAL_UPSERT', mapped, source: { claim: disposition, communication, stakeholders: rawStakeholders || {}, importedAt: new Date().toISOString() } });
    const folderId = upsert.folderId;
    const files = unwrap(rawFiles, 'files');
    for (const file of Array.isArray(files) ? files : []) {
      if (!file?.id) continue;
      const name = safeFileName(file.name || file.fileName || file.originalFilename, `ClaimsForce-${file.id}`);
      await progress(portalTabId, `${mapped.schaden_nr || item.label}: ${name}`, index, claims.length);
      const url = `${config.FILES_API_ENDPOINT}/claims/${encodeURIComponent(id)}/files/${encodeURIComponent(file.id)}?token=${encodeURIComponent(token)}`;
      const response = await fetch(url);
      if (!response.ok) throw new Error(`Datei „${name}“ konnte nicht geladen werden (${response.status}).`);
      await uploadBuffer(portalTabId, folderId, name, file.mimeType || file.contentType || response.headers.get('content-type'), Date.parse(file.updatedAt || file.createdAt || '') || 0, await response.arrayBuffer());
      filesDone++;
    }
    const messages = unwrap(rawMessages, 'messages');
    for (const message of Array.isArray(messages) ? messages : []) {
      const detail = message?.id ? await requestJson(`${config.COMMUNICATION_API_ENDPOINT}/claims/${id}/messages/${message.id}`, token, true) : message;
      const record = unwrap(detail, 'message');
      const stamp = String(record?.sentAt || record?.createdAt || '').slice(0, 10) || 'ohne-Datum';
      const subject = safeFileName(record?.subject || record?.payload?.subject || record?.id, 'Nachricht');
      const bytes = new TextEncoder().encode(JSON.stringify(record, null, 2));
      await uploadBuffer(portalTabId, folderId, `Mail_ClaimsForce-Nachricht_${stamp}_${subject}.json`, 'application/json', Date.parse(record?.updatedAt || record?.createdAt || '') || 0, bytes.buffer);
      messagesDone++;
    }
    const future = mapped.claimsforce_termin;
    if (future?.startDate) await portal(portalTabId, { type: 'PORTAL_APPOINTMENT', folderId, appointment: future });
  }
  await progress(portalTabId, `${claims.length} Aufträge, ${filesDone} Dateien und ${messagesDone} Nachrichten eingelesen.`, claims.length, claims.length);
  if (profile !== 'self') await chrome.storage.session.set({ claimsLoggedProfile: profile });
  return { claims: claims.length, files: filesDone, messages: messagesDone };
}

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  if (message?.type === 'CLAIMS_TOKEN') {
    chrome.storage.session.set({ claimsToken: message.token });
    sendResponse({ ok: true });
    return;
  }
  if (message?.type === 'GET_CREDENTIALS') {
    chrome.storage.session.get('activeProfile').then(async row => {
      const profile = row.activeProfile || 'self';
      const value = await credentialsFor(profile);
      if (value) await chrome.storage.session.set({ claimsLoggedProfile: profile });
      return value;
    }).then(value => sendResponse(value || {}));
    return true;
  }
  if (message?.type === 'GET_PORTAL_CREDENTIALS') {
    loadPortalCredentials().then(value => sendResponse(value || {}));
    return true;
  }
  if (message?.type === 'OPEN_OPTIONS') {
    chrome.storage.session.set({ activeProfile: message.profile || 'self' }).then(() => chrome.runtime.openOptionsPage()); sendResponse({ ok: true }); return;
  }
  if (message?.type === 'START_IMPORT' && sender.tab?.id) {
    runImport(sender.tab.id, message.profile || 'self').then(result => sendResponse({ ok: true, result })).catch(async error => {
      await chrome.tabs.sendMessage(sender.tab.id, { type: 'IMPORT_ERROR', error: error.message }).catch(() => {});
      sendResponse({ ok: false, error: error.message });
    });
    return true;
  }
});
