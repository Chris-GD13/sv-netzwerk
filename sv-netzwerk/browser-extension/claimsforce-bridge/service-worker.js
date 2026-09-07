import { clearCredentials, loadCredentials, loadPortalCredentials, saveCredentials } from './vault.js';
import { mapClaim, safeFileName } from './import-utils.js';
import { isActiveRekonTask, mapRekonTask, ownerMatchesRekonProfile, rekonFileVersion, rekonMessageVersion, rekonProfileKey } from './rekon-utils.js';

const CREDENTIAL_HOST = 'eu.svnetzwerk.claimsforce_credentials';
const PLANNING_BUCKETS = [
  { key: 'WITH_FUTURE_APPOINTMENT', hash: 'with-future-appointment', label: 'Mit Termin' },
  { key: 'WITHOUT_APPOINTMENT', hash: 'without-appointment', label: 'Ohne Termin' }
];
const CLAIMS_ORIGINS = ['https://web.claimsforce.com', 'https://claimsforce.eu.auth0.com'];
const SUPPORTED_PROFILES = ['christian', 'holger', 'marc', 'jens'];
const PROFILE_EMAILS = {
  christian: 'cw@sv-schuett.eu',
  holger: 'hr@sv-schuett.eu',
  marc: 'ms@sv-schuett.eu',
  jens: 'ws@sv-schuett.eu'
};
const PROFILE_BADGES = { christian: ['CW'], holger: ['HR'], marc: ['MS'], jens: ['JM', 'WS'] };
const BRIDGE_VERSION = chrome.runtime.getManifest().version;
const PORTAL_TAB_PATTERN = 'https://www.sv-netzwerk.eu/intern/versicherungsfaelle/*';
const PORTAL_URL = 'https://www.sv-netzwerk.eu/intern/versicherungsfaelle/';
const PORTAL_LOGIN_PATTERN = 'https://www.sv-netzwerk.eu/intern/login/*';
const DAILY_IMPORT_ALARM = 'svnet-claimsforce-daily-0300';
const profileKey = value => {
  const profile = String(value || '').trim().toLowerCase();
  if (!SUPPORTED_PROFILES.includes(profile)) throw new Error('Ungültiges ClaimsForce-Profil.');
  return profile;
};
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const normalizeEmail = value => String(value || '').trim().toLowerCase();
const credentialMatchesProfile = (profile, credentials) => normalizeEmail(credentials?.email) === PROFILE_EMAILS[profileKey(profile)];
const tokenEmail = token => {
  try {
    const payload = String(token || '').split('.')[1];
    if (!payload) return '';
    const decoded = JSON.parse(atob(payload.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(payload.length / 4) * 4, '=')));
    return normalizeEmail(decoded.email || decoded.preferred_username || decoded.upn || decoded['https://claimsforce.com/email']);
  } catch { return ''; }
};
const authHeaders = token => ({ Authorization: `Bearer ${token}`, Accept: 'application/json' });
let runningImport = null;
let runningRekonImport = null;
let credentialDiagnostic = 'idle';
const safeRoute = url => { try { return new URL(url).pathname; } catch { return 'unbekannt'; } };

async function activateBridgeVersion() {
  const row = await chrome.storage.local.get(['bridgeActivatedVersion', 'claimsActiveRun']);
  if (row.bridgeActivatedVersion === BRIDGE_VERSION) return;
  if (row.claimsActiveRun?.status === 'running') {
    await chrome.storage.local.set({
      claimsActiveRun: {
        ...row.claimsActiveRun,
        status: 'failed',
        phase: 'CF-EXTENSION-UPDATE',
        error: 'Die Browser-Brücke wurde während des Imports aktualisiert. Bitte den Import im Portal erneut starten.',
        finishedAt: new Date().toISOString()
      }
    });
  }
  await chrome.storage.local.set({ bridgeActivatedVersion: BRIDGE_VERSION });
  const tabs = await chrome.tabs.query({ url: PORTAL_TAB_PATTERN });
  await Promise.all(tabs.filter(tab => Number.isInteger(tab.id)).map(tab => chrome.tabs.reload(tab.id).catch(() => {})));
}
activateBridgeVersion().catch(() => {});

function nextWeekdayImportAt(now = new Date()) {
  const next = new Date(now);
  next.setHours(3, 0, 0, 0);
  if (next <= now) next.setDate(next.getDate() + 1);
  while (next.getDay() === 0 || next.getDay() === 6) next.setDate(next.getDate() + 1);
  return next.getTime();
}

async function scheduleDailyImportAlarm() {
  await chrome.alarms.create(DAILY_IMPORT_ALARM, { when: nextWeekdayImportAt() });
}

async function wakeCentralImportStation() {
  const portalTabs = await chrome.tabs.query({ url: PORTAL_TAB_PATTERN });
  const portalTab = portalTabs.find(tab => Number.isInteger(tab.id));
  if (portalTab) {
    await chrome.tabs.reload(portalTab.id);
    return;
  }
  const loginTabs = await chrome.tabs.query({ url: PORTAL_LOGIN_PATTERN });
  const loginTab = loginTabs.find(tab => Number.isInteger(tab.id));
  if (loginTab) {
    await chrome.tabs.update(loginTab.id, { url: PORTAL_URL, active: false });
    return;
  }
  await chrome.tabs.create({ url: PORTAL_URL, active: false });
}

async function catchUpMorningImport() {
  const now = new Date();
  const clock = now.getHours() * 100 + now.getMinutes();
  if (now.getDay() !== 0 && now.getDay() !== 6 && clock >= 300 && clock < 1000) await wakeCentralImportStation();
}

chrome.alarms.onAlarm.addListener(alarm => {
  if (alarm.name === DAILY_IMPORT_ALARM) wakeCentralImportStation().finally(() => scheduleDailyImportAlarm().catch(() => {}));
});
chrome.runtime.onInstalled.addListener(() => scheduleDailyImportAlarm().then(catchUpMorningImport).catch(() => {}));
chrome.runtime.onStartup.addListener(() => scheduleDailyImportAlarm().then(catchUpMorningImport).catch(() => {}));
scheduleDailyImportAlarm().catch(() => {});

async function diagnostic(run, phase, text, details = {}) {
  const entry = { runId: run.runId, jobId: run.jobId || 0, profile: run.profile, phase, text, details, at: new Date().toISOString() };
  await chrome.storage.local.set({ claimsImportDiagnostic: entry, claimsActiveRun: { ...run, status: 'running', phase, updatedAt: entry.at } });
  await progress(run.portalTabId, `[${phase}] ${text}`, details.current || 0, details.total || 0, { runId: run.runId, jobId: run.jobId || 0, phase, details }).catch(() => {});
}

async function credentialsFor(profile) {
  profile = profileKey(profile);
  credentialDiagnostic = 'vault';
  const saved = await Promise.race([loadCredentials(profile).catch(() => null), sleep(600).then(() => null)]);
  if (saved?.email && saved?.password && credentialMatchesProfile(profile, saved)) { credentialDiagnostic = 'vault-ready'; return { value: saved, source: 'vault' }; }
  if (saved?.email && saved?.password) {
    credentialDiagnostic = 'vault-profile-mismatch';
    await clearCredentials(profile).catch(() => {});
  }
  credentialDiagnostic = 'native-host';
  try {
    const local = await Promise.race([
      chrome.runtime.sendNativeMessage(CREDENTIAL_HOST, { profile }),
      sleep(800).then(() => null)
    ]);
    if (local?.email && local?.password && credentialMatchesProfile(profile, local)) {
      await saveCredentials(profile, local).catch(() => {});
      credentialDiagnostic = 'native-host-ready';
      return { value: local, source: 'native-host' };
    }
    if (local?.email && local?.password) credentialDiagnostic = 'native-host-profile-mismatch';
  } catch {}
  credentialDiagnostic = 'local-config';
  try {
    const configResponse = await fetch(chrome.runtime.getURL('local-config.json'));
    if (!configResponse.ok) { credentialDiagnostic = `local-config-http-${configResponse.status}`; return null; }
    const config = await configResponse.json();
    credentialDiagnostic = 'loopback-request';
    const endpoint = new URL('/credentials', config.url || 'http://127.0.0.1:47831');
    endpoint.searchParams.set('profile', profile);
    const response = await fetch(endpoint, { headers: { 'X-SVNET-Token': config.token } });
    const local = response.ok ? await response.json() : null;
    credentialDiagnostic = response.ok ? (local?.email && local?.password ? (credentialMatchesProfile(profile, local) ? 'loopback-ready' : 'loopback-profile-mismatch') : 'loopback-incomplete') : `loopback-http-${response.status}`;
    if (local?.email && local?.password && credentialMatchesProfile(profile, local)) {
      await saveCredentials(profile, local).catch(() => {});
      return { value: local, source: 'loopback' };
    }
    return null;
  } catch (error) { credentialDiagnostic = `local-fallback-${String(error?.name || 'error').toLowerCase()}`; return null; }
}

async function tokenValue(profile) {
  profile = profileKey(profile);
  const row = await chrome.storage.session.get(['claimsToken', 'claimsTokenProfile']);
  return row.claimsToken && row.claimsTokenProfile === profile ? row.claimsToken : '';
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

async function resetClaimsSession(run) {
  await diagnostic(run, 'CF-AUTH-01', 'Vorhandene ClaimsForce-Anmeldung wird für den eindeutigen Profilwechsel vollständig beendet.', { profileSwitch: 'ClaimsForce und Auth0 zurücksetzen' });
  await chrome.storage.session.remove(['claimsToken', 'claimsTokenProfile', 'claimsLoggedProfile']);
  await chrome.storage.local.remove(['claimsLoggedProfile']);
  let logoutTab = null;
  try {
    logoutTab = await chrome.tabs.create({ url: 'https://web.claimsforce.com/logout', active: false });
    await waitTab(logoutTab.id, 30000);
  } catch {}
  finally { if (Number.isInteger(logoutTab?.id)) await chrome.tabs.remove(logoutTab.id).catch(() => {}); }
  const claimsTabs = await chrome.tabs.query({ url: ['https://web.claimsforce.com/*', 'https://claimsforce.eu.auth0.com/*'] });
  await Promise.all(claimsTabs.filter(tab => Number.isInteger(tab.id)).map(tab => chrome.tabs.remove(tab.id).catch(() => {})));
  await chrome.browsingData.remove(
    { origins: CLAIMS_ORIGINS },
    { cookies: true, cacheStorage: true, indexedDB: true, localStorage: true, serviceWorkers: true }
  );
}

async function claimsTab(profile, run, credential) {
  let [tab] = await chrome.tabs.query({ url: ['https://web.claimsforce.com/*', 'https://claimsforce.eu.auth0.com/*'] });
  if (!tab) tab = await chrome.tabs.create({ url: 'https://web.claimsforce.com/login', active: false });
  tab = await waitTab(tab.id);
  if (safeRoute(tab.url) === '/login') await chrome.storage.session.remove(['claimsToken', 'claimsTokenProfile']);
  await diagnostic(run, 'CF-AUTH-02', 'ClaimsForce-Seite ist geladen.', { route: safeRoute(tab.url) });
  let token = await tokenValue(profile);
  if (!token && safeRoute(tab.url) !== '/login') {
    await diagnostic(run, 'CF-AUTH-02', 'Bestehende ClaimsForce-Sitzung wird einmal neu geladen, damit das Sitzungstoken erneut übernommen werden kann.', { route: safeRoute(tab.url), recovery: 'reload' });
    await chrome.tabs.reload(tab.id);
    tab = await waitTab(tab.id);
    token = await tokenValue(profile);
  }
  if (!token) {
    const route = safeRoute(tab.url);
    if (!String(tab.url || '').includes('claimsforce.eu.auth0.com') && route !== '/login') {
      await diagnostic(run, 'CF-AUTH-02', 'ClaimsForce-Sitzung ist abgelaufen. Die Anmeldeseite wird automatisch geöffnet.', { route, recovery: 'login' });
      await chrome.tabs.update(tab.id, { url: 'https://web.claimsforce.com/login' });
      tab = await waitTab(tab.id);
    }
    const loginDeadline = Date.now() + 60000;
    let helperReady = false;
    while (!token && Date.now() < loginDeadline) {
      if (credential?.value) {
        const requested = await chrome.tabs.sendMessage(tab.id, { type: 'FILL_LOGIN', credentials: credential.value }).catch(() => null);
        helperReady ||= !!requested?.ok;
      }
      await sleep(750);
      token = await tokenValue(profile);
      tab = await chrome.tabs.get(tab.id);
    }
    await diagnostic(run, 'CF-AUTH-02', helperReady ? 'ClaimsForce-Anmeldung wurde automatisch ausgeführt.' : 'ClaimsForce-Anmeldehelfer hat keine vollständige Anmeldung bestätigt.', { route: safeRoute(tab.url), helper: helperReady ? 'bereit' : 'nicht erreichbar' });
    if (!token) throw new Error('ClaimsForce-Anmeldung konnte nicht automatisch abgeschlossen werden. Bitte die gespeicherten Zugangsdaten der Browser-Brücke prüfen.');
  }
  const authenticatedEmail = tokenEmail(token);
  if (authenticatedEmail && authenticatedEmail !== PROFILE_EMAILS[profile]) throw new Error(`[CF-AUTH-03] ClaimsForce hat ${authenticatedEmail} statt des ausgewählten Profils ${PROFILE_EMAILS[profile]} angemeldet.`);
  const badgeDeadline = Date.now() + 10000;
  let badges = [];
  while (Date.now() < badgeDeadline) {
    const identity = await chrome.tabs.sendMessage(tab.id, { type: 'READ_ACCOUNT_IDENTITY' }).catch(() => null);
    badges = Array.isArray(identity?.badges) ? identity.badges : [];
    if (PROFILE_BADGES[profile].some(badge => badges.includes(badge)) || badges.length) break;
    await sleep(400);
  }
  if (!PROFILE_BADGES[profile].some(badge => badges.includes(badge))) throw new Error(`[CF-AUTH-04] ClaimsForce zeigt ${badges.join(', ') || 'keine eindeutige Konto-Kennung'} statt ${PROFILE_BADGES[profile].join('/')} für ${PROFILE_EMAILS[profile]}.`);
  return { tab, token };
}

async function openPlanning(tabId, bucket) {
  const planningBucket = PLANNING_BUCKETS.find(entry => entry.key === bucket);
  if (!planningBucket) throw new Error('Unbekannte ClaimsForce-Planungsansicht.');
  const planningUrl = `https://web.claimsforce.com/planning?bucket=${encodeURIComponent(planningBucket.key)}#${planningBucket.hash}`;
  let tab = await chrome.tabs.get(tabId);
  const current = String(tab.url || '');
  if (!current.includes('/planning') || !current.includes(planningBucket.key)) {
    await chrome.tabs.update(tabId, { url: planningUrl });
    tab = await waitTab(tabId);
  }
  if (!String(tab.url || '').includes('/planning')) throw new Error('ClaimsForce-Planung konnte nicht geöffnet werden.');
  await sleep(2000);
  const opened = await chrome.tabs.sendMessage(tabId, { type: 'OPEN_PLANNING_BUCKET', bucket: planningBucket.key }).catch(() => null);
  await sleep(3000);
  return opened;
}

async function readOpenTasks(tabId) {
  const opened = await chrome.tabs.sendMessage(tabId, { type: 'OPEN_TASKS' }).catch(() => null);
  if (!opened?.ok) return null;
  await sleep(1800);
  for (let attempt = 0; attempt < 20; attempt++) {
    const result = await chrome.tabs.sendMessage(tabId, { type: 'READ_OPEN_TASKS' }).catch(() => null);
    if (Number.isInteger(result?.openTasks)) return Math.max(0, result.openTasks);
    await sleep(250);
  }
  return null;
}

async function requestJson(url, token, optional = false, timeout = 20000) {
  const controller = new AbortController(), timer = setTimeout(() => controller.abort(), timeout);
  try {
    const response = await fetch(url, { headers: authHeaders(token), signal: controller.signal });
    if (optional && response.status === 404) return null;
    if (!response.ok) {
      if (optional) return null;
      throw new Error(`ClaimsForce-Abruf fehlgeschlagen (${response.status}).`);
    }
    return await response.json();
  }
  catch (error) {
    if (optional) return null;
    if (String(error?.message || '').startsWith('ClaimsForce-Abruf fehlgeschlagen')) throw error;
    throw new Error(error?.name === 'AbortError' ? 'ClaimsForce-Abruf hat das Zeitlimit überschritten.' : 'ClaimsForce-Abruf ist fehlgeschlagen.');
  } finally { clearTimeout(timer); }
}

async function portal(tabId, message) {
  const response = await Promise.race([chrome.tabs.sendMessage(tabId, message), sleep(120000).then(() => ({ ok: false, error: 'Das SV-Netzwerk hat innerhalb von 120 Sekunden nicht geantwortet.' }))]);
  if (!response?.ok) throw new Error(response?.error || 'Das SV-Netzwerk hat den Import nicht angenommen.');
  return response;
}

async function progress(tabId, text, current = 0, total = 0, runtime = {}) {
  await chrome.tabs.sendMessage(tabId, { type: 'IMPORT_PROGRESS', text, current, total, runtime }).catch(() => {});
}

async function portalOperation(tabId, message, timeout = 60000) {
  const operationId = String(message.operationId || '');
  const accepted = await portal(tabId, message);
  if (!accepted?.accepted) throw new Error('Das SV-Netzwerk hat die Falloperation nicht angenommen.');
  const deadline = Date.now() + timeout;
  while (Date.now() < deadline) {
    const status = await portal(tabId, { type: 'PORTAL_OPERATION_STATUS', operationId });
    if (status.operation?.status === 'done') return status.operation.result;
    if (status.operation?.status === 'failed') throw new Error(status.operation.error || 'Portal-Falloperation fehlgeschlagen.');
    if (status.operation?.status === 'missing') throw new Error('Die Portal-Falloperation wurde unterbrochen. Bitte den Import manuell erneut starten.');
    await sleep(500);
  }
  throw new Error('Das SV-Netzwerk hat die Falloperation nicht rechtzeitig abgeschlossen.');
}

function unwrap(data, key) {
  return data?.[key] ?? data ?? {};
}

function stable(value) {
  if (Array.isArray(value)) return value.map(stable);
  if (value && typeof value === 'object') return Object.fromEntries(Object.keys(value).sort().map(key => [key, stable(value[key])]));
  return value;
}

async function fingerprint(value) {
  const bytes = new TextEncoder().encode(JSON.stringify(stable(value)));
  const hash = new Uint8Array(await crypto.subtle.digest('SHA-256', bytes));
  return [...hash].map(byte => byte.toString(16).padStart(2, '0')).join('');
}

const fileVersion = file => [file?.id, file?.updatedAt || file?.modifiedAt || file?.createdAt, file?.size || file?.fileSize, file?.name || file?.fileName || file?.originalFilename].map(value => String(value || '')).join('|');
const messageVersion = message => [message?.id, message?.updatedAt || message?.createdAt || message?.sentAt].map(value => String(value || '')).join('|');

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

async function runImport(run) {
  const portalTabId = () => Number(run.portalTabId || 0), profile = profileKey(run.profile);
  run.profile = profile;
  await chrome.storage.session.set({ activeProfile: profile });
  await resetClaimsSession(run);
  const credential = await credentialsFor(profile);
  await diagnostic(run, 'CF-CRED-01', credential ? 'Zugangsdatenquelle ist verfügbar.' : 'Für das Profil ist keine Zugangsdatenquelle verfügbar.', { source: credential?.source || 'keine' });
  if (!credential) throw new Error('[CF-CRED-01] Für dieses ClaimsForce-Profil sind keine vollständigen Zugangsdaten verfügbar.');
  if (!credentialMatchesProfile(profile, credential.value)) throw new Error('[CF-CRED-02] Das gespeicherte ClaimsForce-Konto gehört nicht zum ausgewählten Bearbeiterprofil.');
  const { tab, token } = await claimsTab(profile, run, credential);
  await diagnostic(run, 'CF-TOKEN-03', 'ClaimsForce-Sitzungstoken wurde übernommen.', { route: safeRoute((await chrome.tabs.get(tab.id)).url) });
  const openTasks = await readOpenTasks(tab.id);
  await diagnostic(run, 'CF-TASKS-04', Number.isInteger(openTasks) ? `${openTasks} offene Aufgabe/Aufgaben wurden unter „Aufgaben – Alle“ erkannt.` : 'Der Zähler „Aufgaben – Alle“ konnte nicht sicher gelesen werden.', { openTasks });
  const claimsById = new Map(), bucketCounts = {};
  for (const planningBucket of PLANNING_BUCKETS) {
    const planning = await openPlanning(tab.id, planningBucket.key);
    await diagnostic(run, 'CF-PLAN-04', `Planungsansicht „${planningBucket.label}“ wurde angefordert.`, { bucket: planningBucket.key, strategy: planning?.strategy || 'bestehende Ansicht' });
    const scraped = await chrome.tabs.sendMessage(tab.id, { type: 'SCRAPE_CLAIMS' });
    const bucketClaims = scraped?.claims || [];
    bucketCounts[planningBucket.key] = bucketClaims.length;
    for (const claim of bucketClaims) if (claim?.id) claimsById.set(claim.id, { ...(claimsById.get(claim.id) || {}), ...claim });
    await diagnostic(run, 'CF-LIST-05', `${bucketClaims.length} Auftrag/Aufträge wurden in „${planningBucket.label}“ erkannt.`, { bucket: planningBucket.key, count: bucketClaims.length, combined: claimsById.size, openTasks, observedApi: scraped?.observedClaims || 0, route: scraped?.route || safeRoute((await chrome.tabs.get(tab.id)).url) });
  }
  const claims = [...claimsById.values()];
  await diagnostic(run, 'CF-LIST-05', `${claims.length} unterschiedliche Aufträge mit und ohne Termin wurden erkannt.`, { count: claims.length, bucketCounts, openTasks });
  if (!claims.length) {
    const state = await chrome.tabs.sendMessage(tab.id, { type: 'SESSION_STATE' }).catch(() => ({}));
    throw new Error(`[CF-LIST-05] Keine Aufträge erkannt (Route ${state.route || 'unbekannt'}, API ${state.observedClaims || 0}).`);
  }
  const configController = new AbortController(), configTimer = setTimeout(() => configController.abort(), 15000);
  let config;
  try { config = await (await fetch('https://web.claimsforce.com/config', { signal: configController.signal })).json(); }
  catch (error) { throw new Error(error?.name === 'AbortError' ? 'ClaimsForce-Konfiguration hat das Zeitlimit überschritten.' : 'ClaimsForce-Konfiguration konnte nicht geladen werden.'); }
  finally { clearTimeout(configTimer); }
  let filesDone = 0, messagesDone = 0, appointmentsDone = 0, skipped = 0, updated = 0;
  for (let index = 0; index < claims.length; index++) {
    const item = claims[index], id = item.id;
    const preliminary = { claimsforce_claim_id: id, schaden_nr: String(item.label || '').trim() };
    const preliminaryState = await portal(portalTabId(), { type: 'PORTAL_SYNC_STATE', mapped: preliminary, profile });
    const preliminaryMeta = preliminaryState.result?.meta || {};
    if (preliminaryState.result?.existed && item.listVersion && preliminaryMeta.claimsforce_list_version === item.listVersion) {
      skipped++;
      await progress(portalTabId(), `Auftrag ${index + 1}/${claims.length}: seit dem letzten Import unverändert, wird ohne erneuten Detailabruf übersprungen.`, index + 1, claims.length);
      await diagnostic(run, 'CF-CASE-DELTA-SKIP', `Auftrag ${index + 1}/${claims.length} ist laut ClaimsForce-Änderungsstand unverändert.`, { current: index + 1, total: claims.length, claimIndex: index + 1, skippedCases: skipped });
      continue;
    }
    await diagnostic(run, 'CF-CASE-FETCH', `Auftrag ${index + 1}/${claims.length}: Falldaten werden geladen.`, { current: index, total: claims.length, claimIndex: index + 1 });
    await progress(portalTabId(), `Auftrag ${index + 1}/${claims.length} wird eingelesen …`, index, claims.length);
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
    const files = Array.isArray(unwrap(rawFiles, 'files')) ? unwrap(rawFiles, 'files') : [];
    const messages = Array.isArray(unwrap(rawMessages, 'messages')) ? unwrap(rawMessages, 'messages') : [];
    const mapped = mapClaim(disposition, communication, Array.isArray(appointments) ? appointments : [], rawStakeholders || {});
    const fileVersions = files.map(fileVersion).filter(Boolean);
    const messageVersions = messages.map(messageVersion).filter(Boolean);
    const stableMapped = { ...mapped };
    delete stableMapped.claimsforce_zuletzt_eingelesen;
    const appointmentVersions = (Array.isArray(appointments) ? appointments : []).map(appointment => [appointment?.id, appointment?.updatedAt, appointment?.startDate, appointment?.endDate].map(value => String(value || '')).join('|'));
    const signature = await fingerprint({ mapped: stableMapped, fileVersions, messageVersions, appointmentVersions });
    const state = await portal(portalTabId(), { type: 'PORTAL_SYNC_STATE', mapped, profile });
    const existingMeta = state.result?.meta || {};
    if (state.result?.existed && existingMeta.claimsforce_sync_signature === signature) {
      skipped++;
      await progress(portalTabId(), `Auftrag ${index + 1}/${claims.length}: unverändert, wird übersprungen.`, index + 1, claims.length);
      await diagnostic(run, 'CF-CASE-SKIP', `Auftrag ${index + 1}/${claims.length} ist bereits vollständig und unverändert vorhanden.`, { current: index + 1, total: claims.length, claimIndex: index + 1, skippedCases: skipped });
      continue;
    }
    await diagnostic(run, 'CF-CASE-UPSERT', `Auftrag ${index + 1}/${claims.length}: Portal-Fall wird angelegt oder ergänzt.`, { current: index, total: claims.length, claimIndex: index + 1 });
    const upsert = await portalOperation(portalTabId(), { type: 'PORTAL_UPSERT_ASYNC', operationId: `${run.runId}:upsert:${id}`, mapped, profile, source: { claim: disposition, communication, stakeholders: rawStakeholders || {}, importedAt: new Date().toISOString() } });
    const folderId = upsert.folderId;
    await diagnostic(run, 'CF-CASE-FILES', `Auftrag ${index + 1}/${claims.length}: Anhänge und Nachrichten werden übernommen.`, { current: index, total: claims.length, claimIndex: index + 1 });
    const knownFileVersions = new Set(Array.isArray(existingMeta.claimsforce_file_versions) ? existingMeta.claimsforce_file_versions.map(String) : []);
    for (const file of files) {
      if (!file?.id) continue;
      const version = fileVersion(file);
      if (knownFileVersions.has(version)) continue;
      const name = safeFileName(file.name || file.fileName || file.originalFilename, `ClaimsForce-${file.id}`);
      await progress(portalTabId(), `${mapped.schaden_nr || item.label}: ${name}`, index, claims.length);
      const url = `${config.FILES_API_ENDPOINT}/claims/${encodeURIComponent(id)}/files/${encodeURIComponent(file.id)}?token=${encodeURIComponent(token)}`;
      const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 30000);
      let response, fileBuffer;
      try { response = await fetch(url, { signal: controller.signal }); if (response.ok) fileBuffer = await response.arrayBuffer(); }
      catch (error) { throw new Error(error?.name === 'AbortError' ? `Datei „${name}“ hat das Zeitlimit überschritten.` : `Datei „${name}“ konnte nicht geladen werden.`); }
      finally { clearTimeout(timer); }
      if (!response.ok) throw new Error(`Datei „${name}“ konnte nicht geladen werden (${response.status}).`);
      const uploaded = await uploadBuffer(portalTabId(), folderId, name, file.mimeType || file.contentType || response.headers.get('content-type'), Date.parse(file.updatedAt || file.createdAt || '') || 0, fileBuffer);
      if (!uploaded?.result?.duplicate && !uploaded?.result?.excluded) filesDone++;
    }
    const knownMessageVersions = new Set(Array.isArray(existingMeta.claimsforce_message_versions) ? existingMeta.claimsforce_message_versions.map(String) : []);
    for (const message of messages) {
      const version = messageVersion(message);
      if (knownMessageVersions.has(version)) continue;
      const detail = message?.id ? await requestJson(`${config.COMMUNICATION_API_ENDPOINT}/claims/${id}/messages/${message.id}`, token, true) : message;
      const record = unwrap(detail, 'message');
      const stamp = String(record?.sentAt || record?.createdAt || '').slice(0, 10) || 'ohne-Datum';
      const subject = safeFileName(record?.subject || record?.payload?.subject || record?.id, 'Nachricht');
      const bytes = new TextEncoder().encode(JSON.stringify(record, null, 2));
      const uploaded = await uploadBuffer(portalTabId(), folderId, `Mail_ClaimsForce-Nachricht_${stamp}_${subject}.json`, 'application/json', Date.parse(record?.updatedAt || record?.createdAt || '') || 0, bytes.buffer);
      if (!uploaded?.result?.duplicate && !uploaded?.result?.excluded) messagesDone++;
    }
    if (!['christian', 'jens'].includes(profile)) {
      for (const appointment of Array.isArray(appointments) ? appointments : []) {
        if (!appointment?.startDate) continue;
        const appointmentResult = await portal(portalTabId(), { type: 'PORTAL_APPOINTMENT', folderId, appointment, profile });
        if (!appointmentResult?.result?.skipped) appointmentsDone++;
      }
    }
    await portal(portalTabId(), { type: 'PORTAL_COMMIT_SYNC', folderId, signature, fileVersions, messageVersions, listVersion: item.listVersion || '', profile });
    updated++;
    await diagnostic(run, 'CF-CASE-06', `Auftrag ${index + 1}/${claims.length} wurde vollständig im Portal verarbeitet.`, { current: index + 1, total: claims.length, completedCases: index + 1, folderCreatedOrUpdated: true });
  }
  await progress(portalTabId(), `${claims.length} Aufträge geprüft: ${updated} aktualisiert, ${skipped} unverändert übersprungen, ${filesDone} neue Dateien, ${messagesDone} neue Nachrichten und ${appointmentsDone} neue Termine.`, claims.length, claims.length);
  await chrome.storage.session.set({ claimsLoggedProfile: profile });
  await chrome.storage.local.set({ claimsLoggedProfile: profile });
  return { claims: claims.length, openTasks, updated, skipped, files: filesDone, messages: messagesDone, appointments: appointmentsDone };
}

async function startImport(sender, message) {
  const portalTabId = sender.tab?.id;
  if (!portalTabId) return { ok: false, error: '[CF-RUN-00] Portal-Registerkarte fehlt.' };
  const requested = { runId: message.runId || crypto.randomUUID(), jobId: Number(message.jobId || 0), profile: profileKey(message.profile), portalTabId, startedAt: new Date().toISOString() };
  if (runningImport) {
    if (runningImport.jobId === requested.jobId && runningImport.profile === requested.profile) {
      runningImport.portalTabId = portalTabId;
      await chrome.storage.local.set({ claimsActiveRun: { ...runningImport, status: 'running', updatedAt: new Date().toISOString() } });
      return { ok: true, accepted: true, resumed: true, runId: runningImport.runId };
    }
    return { ok: false, error: '[CF-RUN-00] Ein anderer ClaimsForce-Import läuft bereits.' };
  }
  const saved = (await chrome.storage.local.get('claimsActiveRun')).claimsActiveRun;
  const resumesSaved = saved?.status === 'running' && saved.jobId === requested.jobId && saved.profile === requested.profile;
  const run = resumesSaved ? { ...saved, portalTabId, runId: saved.runId || requested.runId } : requested;
  runningImport = run;
  runImport(run).then(async result => {
    await chrome.storage.local.set({ claimsActiveRun: { ...run, status: 'done', result, finishedAt: new Date().toISOString() } });
    await chrome.tabs.sendMessage(Number(run.portalTabId || 0), { type: 'IMPORT_DONE', result, runtime: { runId: run.runId, jobId: run.jobId, phase: 'CF-DONE-07' } }).catch(() => {});
  }).catch(async error => {
    const message = String(error?.message || 'ClaimsForce-Import fehlgeschlagen.').slice(0, 500);
    await chrome.storage.local.set({ claimsActiveRun: { ...run, status: 'failed', error: message, finishedAt: new Date().toISOString() } });
    await chrome.tabs.sendMessage(Number(run.portalTabId || 0), { type: 'IMPORT_ERROR', error: message, runtime: { runId: run.runId, jobId: run.jobId, phase: 'CF-FAIL-99' } }).catch(() => {});
  }).finally(() => { runningImport = null; });
  return { ok: true, accepted: true, resumed: resumesSaved, runId: run.runId };
}

const REKON_URL = 'https://www.rekoninterschaden-portal.de/dashboard';
const REKON_TAB_PATTERN = 'https://www.rekoninterschaden-portal.de/*';
const REKON_GRAPHQL = 'https://api.www.rekoninterschaden-portal.de/service';
const REKON_TASKS_QUERY = `query Tasks($filter: TasksFilter!, $sort: TasksSort!, $pagination: PaginationInput!, $with_removed: Boolean) {
  tasks(filter: $filter, sort: $sort, pagination: $pagination, with_removed: $with_removed) {
    data { id identifier external_number policy_number reserve created_at state_changed_date
      claimant { id name }
      customer { id first_name name full_name phone phone2 mobile mobile2 email email2 }
      primary_location { street street_no postcode city country { title } }
      primary_form { id template { id title shortcut } }
      visit_type { id title need_location }
      state { id title color }
      appointment { id date_from date_to description event_type { id title } calendar_event { id } }
      owner { id name job_title }
      leader { id name job_title }
    }
    paginatorInfo { total }
  }
}`;
const REKON_FILES_QUERY = `query TaskFoldersAndFiles($task_id: ID!) {
  taskFiles(task_id: $task_id) { data { id name original_file_name size mime_type created_at updated_at url folder_id } }
  taskFolders(task_id: $task_id) { id name task_id parent_id folder_type }
}`;
const REKON_EMAILS_QUERY = `query TaskEmails($taskId: ID!) {
  emails(task_id: $taskId) { data { id subject body body_preview state_id send_date
    created_from_client { id name email }
    attachments { id file { id name size mime_type created_at updated_at url_download } }
    contacts { id address name type }
  } }
}`;
const REKON_LOGS_QUERY = `query TaskLogs($taskId: ID!) {
  taskLogs(task_id: $taskId) { id title created_at log_state_id state { id title color } client { name job_title } sms_message { body } }
}`;

async function rekonProgress(tabId, text, current = 0, total = 0) {
  await chrome.tabs.sendMessage(tabId, { type: 'REKON_IMPORT_PROGRESS', text, current, total }).catch(() => {});
}

async function rekonGraph(query, variables, token, optional = false) {
  const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 120000);
  try {
    const response = await fetch(REKON_GRAPHQL, {
      method: 'POST',
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({ query, variables }),
      signal: controller.signal
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload.errors?.length) {
      if (optional) return null;
      throw new Error(`Rekon-Abruf fehlgeschlagen (${response.status || 'GraphQL'}).`);
    }
    return payload.data || {};
  } catch (error) {
    if (optional) return null;
    if (String(error?.message || '').startsWith('Rekon-Abruf fehlgeschlagen')) throw error;
    throw new Error(error?.name === 'AbortError' ? 'Rekon-Abruf hat das Zeitlimit überschritten.' : 'Rekon-Abruf ist fehlgeschlagen.');
  } finally { clearTimeout(timer); }
}

async function rekonTokenValue() {
  const row = await chrome.storage.session.get(['rekonToken', 'rekonTokenAt']);
  return row.rekonToken && Date.now() - Number(row.rekonTokenAt || 0) < 12 * 60 * 60 * 1000 ? row.rekonToken : '';
}

async function rekonSession(profile, portalTabId) {
  let [tab] = await chrome.tabs.query({ url: REKON_TAB_PATTERN });
  if (!tab) tab = await chrome.tabs.create({ url: REKON_URL, active: true });
  tab = await waitTab(tab.id, 60000);
  let token = await rekonTokenValue();
  let session = await chrome.tabs.sendMessage(tab.id, { type: 'REKON_SESSION_STATE' }).catch(() => null);
  if (!token || !session?.ok) {
    await rekonProgress(portalTabId, 'Rekon-Sitzung wird im Microsoft Edge neu eingelesen …');
    await chrome.tabs.reload(tab.id);
    tab = await waitTab(tab.id, 60000);
    const deadline = Date.now() + 30000;
    while (!token && Date.now() < deadline) { await sleep(500); token = await rekonTokenValue(); }
    session = await chrome.tabs.sendMessage(tab.id, { type: 'REKON_SESSION_STATE' }).catch(() => null);
  }
  if (!session?.ok || !token) throw new Error('Rekon ist im Microsoft Edge nicht vollständig angemeldet. Bitte den geöffneten Rekon-Tab prüfen.');
  if (!ownerMatchesRekonProfile(session.identity, profile)) throw new Error(`Rekon zeigt „${session.identity || 'kein eindeutiges Profil'}“ statt des ausgewählten Profils ${profile === 'marc' ? 'Marc Schütt' : 'Holger Roth'}.`);
  return { tab, token };
}

async function readRekonTasks(token) {
  const all = [];
  for (let skip = 0, total = 1; skip < total; skip += 100) {
    const data = await rekonGraph(REKON_TASKS_QUERY, { filter: { logic: 'and', filters: [] }, sort: { columns: [] }, pagination: { take: 100, skip }, with_removed: false }, token);
    const page = data.tasks?.data || [];
    total = Number(data.tasks?.paginatorInfo?.total || page.length);
    all.push(...page);
    if (!page.length) break;
  }
  return all.filter(isActiveRekonTask);
}

async function downloadRekonFile(file, token) {
  const url = String(file?.url_download || file?.url || '').trim();
  if (!url) throw new Error(`Rekon-Datei ${file?.name || file?.id || ''} besitzt keinen Downloadlink.`);
  const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 120000);
  try {
    let response = await fetch(url, { headers: { Authorization: `Bearer ${token}` }, signal: controller.signal });
    if (!response.ok) response = await fetch(url, { signal: controller.signal });
    if (!response.ok) throw new Error(`Rekon-Datei konnte nicht geladen werden (${response.status}).`);
    return { buffer: await response.arrayBuffer(), mime: file.mime_type || response.headers.get('content-type') || 'application/octet-stream' };
  } finally { clearTimeout(timer); }
}

async function runRekonImport(run) {
  const profile = rekonProfileKey(run.profile), portalTabId = Number(run.portalTabId || 0);
  await rekonProgress(portalTabId, 'Rekon-Import wird im Microsoft Edge vorbereitet …');
  const { token } = await rekonSession(profile, portalTabId);
  const tasks = await readRekonTasks(token);
  if (!tasks.length) throw new Error('Rekon hat keine aktiven Aufträge geliefert. Der Import wurde ohne Änderungen beendet.');
  const wrongOwner = tasks.find(task => !ownerMatchesRekonProfile(task?.owner?.name, profile));
  if (wrongOwner) throw new Error(`Rekon-Auftrag ${wrongOwner.id} gehört zu „${wrongOwner?.owner?.name || 'unbekannt'}“ und nicht zum ausgewählten Zielprofil.`);
  let updated = 0, skipped = 0, filesDone = 0, messagesDone = 0, appointmentsDone = 0;
  for (let index = 0; index < tasks.length; index++) {
    const task = tasks[index], mapped = mapRekonTask(task), id = String(task.id);
    await rekonProgress(portalTabId, `Rekon ${mapped.schaden_nr || id}: Dateien und E-Mails werden geprüft …`, index, tasks.length);
    const [fileData, emailData, logData] = await Promise.all([
      rekonGraph(REKON_FILES_QUERY, { task_id: id }, token),
      rekonGraph(REKON_EMAILS_QUERY, { taskId: id }, token, true),
      rekonGraph(REKON_LOGS_QUERY, { taskId: id }, token, true)
    ]);
    const files = fileData?.taskFiles?.data || [], emails = emailData?.emails?.data || [], logs = logData?.taskLogs || [];
    const fileVersions = files.map(rekonFileVersion).filter(Boolean);
    const messageVersions = emails.map(rekonMessageVersion).filter(Boolean);
    const appointmentVersions = mapped.rekon_termin ? [mapped.rekon_termin.id, mapped.rekon_termin.startDate, mapped.rekon_termin.endDate].map(String) : [];
    const stableMapped = { ...mapped }; delete stableMapped.rekon_zuletzt_eingelesen;
    const signature = await fingerprint({ mapped: stableMapped, fileVersions, messageVersions, appointmentVersions, logs });
    const sync = await portal(portalTabId, { type: 'PORTAL_SYNC_STATE', mapped });
    const existingMeta = sync.result?.meta || {};
    if (existingMeta.rekon_sync_signature === signature) { skipped++; continue; }
    const saved = await portalOperation(portalTabId, { type: 'PORTAL_UPSERT_ASYNC', operationId: crypto.randomUUID(), mapped, profile, source: mapped.rekon_quelle, sourceType: 'rekon' }, 120000);
    const folderId = saved.folderId;
    const knownFiles = new Set(Array.isArray(existingMeta.rekon_file_versions) ? existingMeta.rekon_file_versions.map(String) : []);
    for (const file of files) {
      const version = rekonFileVersion(file);
      if (knownFiles.has(version)) continue;
      const content = await downloadRekonFile(file, token);
      const uploaded = await uploadBuffer(portalTabId, folderId, file.original_file_name || file.name || `Rekon-Datei-${file.id}`, content.mime, Date.parse(file.updated_at || file.created_at || '') || 0, content.buffer);
      if (!uploaded?.result?.duplicate && !uploaded?.result?.excluded) filesDone++;
    }
    const knownMessages = new Set(Array.isArray(existingMeta.rekon_message_versions) ? existingMeta.rekon_message_versions.map(String) : []);
    for (const email of emails) {
      const version = rekonMessageVersion(email);
      if (!knownMessages.has(version)) {
        const stamp = String(email.send_date || '').replace(/[^0-9]/g, '').slice(0, 14) || id;
        const subject = safeFileName(email.subject || `E-Mail-${email.id}`, 'Rekon-E-Mail').slice(0, 80);
        const bytes = new TextEncoder().encode(JSON.stringify({ source: 'Rekon', task_id: id, ...email }, null, 2));
        const uploaded = await uploadBuffer(portalTabId, folderId, `Mail_Rekon-Nachricht_${stamp}_${subject}.json`, 'application/json', Date.parse(email.send_date || '') || 0, bytes.buffer);
        if (!uploaded?.result?.duplicate && !uploaded?.result?.excluded) messagesDone++;
      }
      for (const attachment of email.attachments || []) {
        const file = attachment?.file;
        if (!file || knownFiles.has(rekonFileVersion(file))) continue;
        const content = await downloadRekonFile(file, token);
        const uploaded = await uploadBuffer(portalTabId, folderId, file.name || `Rekon-Mail-Anhang-${file.id}`, content.mime, Date.parse(file.updated_at || file.created_at || email.send_date || '') || 0, content.buffer);
        if (!uploaded?.result?.duplicate && !uploaded?.result?.excluded) filesDone++;
      }
    }
    const auditBytes = new TextEncoder().encode(JSON.stringify({ source: 'Rekon', task, folders: fileData?.taskFolders || [], logs }, null, 2));
    await uploadBuffer(portalTabId, folderId, `00_Rekon-Auftragsakte_${id}.json`, 'application/json', Date.parse(task.updated_at || task.state_changed_date || task.created_at || '') || 0, auditBytes.buffer);
    if (mapped.rekon_termin?.startDate) {
      const result = await portal(portalTabId, { type: 'PORTAL_APPOINTMENT', folderId, appointment: mapped.rekon_termin, profile, sourceType: 'rekon' });
      if (!result?.result?.skipped) appointmentsDone++;
    }
    await portal(portalTabId, { type: 'PORTAL_COMMIT_SYNC', folderId, signature, fileVersions, messageVersions, listVersion: String(task.updated_at || task.state_changed_date || ''), profile, sourceType: 'rekon' });
    updated++;
  }
  await rekonProgress(portalTabId, `${tasks.length} aktive Rekon-Aufträge geprüft: ${updated} aktualisiert, ${skipped} unverändert, ${filesDone} neue Dateien, ${messagesDone} neue Nachrichten und ${appointmentsDone} neue Termine.`, tasks.length, tasks.length);
  return { tasks: tasks.length, updated, skipped, files: filesDone, messages: messagesDone, appointments: appointmentsDone };
}

async function startRekonImport(sender, message) {
  const portalTabId = sender.tab?.id;
  if (!portalTabId) return { ok: false, error: 'Portal-Registerkarte fehlt.' };
  const run = { runId: message.runId || crypto.randomUUID(), profile: rekonProfileKey(message.profile), portalTabId, startedAt: new Date().toISOString() };
  if (runningRekonImport) return { ok: false, error: 'Ein Rekon-Import läuft bereits.' };
  runningRekonImport = run;
  runRekonImport(run).then(result => chrome.tabs.sendMessage(portalTabId, { type: 'REKON_IMPORT_DONE', result }).catch(() => {})).catch(error => chrome.tabs.sendMessage(portalTabId, { type: 'REKON_IMPORT_ERROR', error: String(error?.message || 'Rekon-Import fehlgeschlagen.').slice(0, 500) }).catch(() => {})).finally(() => { runningRekonImport = null; });
  return { ok: true, accepted: true, runId: run.runId };
}

chrome.runtime.onConnect.addListener(port => {
  if (port.name !== 'claims-import-keepalive') return;
  port.onMessage.addListener(() => {});
});

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  if (message?.type === 'REKON_TOKEN') {
    chrome.storage.session.set({ rekonToken: message.token, rekonTokenAt: Date.now() }).then(() => sendResponse({ ok: true })).catch(error => sendResponse({ ok: false, error: error.message }));
    return true;
  }
  if (message?.type === 'CLAIMS_TOKEN') {
    chrome.storage.session.get('activeProfile').then(row => chrome.storage.session.set({ claimsToken: message.token, claimsTokenProfile: profileKey(row.activeProfile), claimsTokenAt: Date.now() })).then(() => sendResponse({ ok: true })).catch(error => sendResponse({ ok: false, error: error.message }));
    return true;
  }
  if (message?.type === 'GET_CREDENTIALS') {
    chrome.storage.session.get('activeProfile').then(async row => {
      const profile = profileKey(row.activeProfile);
      const found = await credentialsFor(profile);
      return found?.value || null;
    }).then(value => sendResponse(value || {})).catch(() => sendResponse({}));
    return true;
  }
  if (message?.type === 'CHECK_PROFILE_CREDENTIALS') {
    let profile;
    try { profile = profileKey(message.profile); }
    catch (error) { sendResponse({ ok: false, profile: '', phase: 'invalid-profile', error: error.message }); return; }
    credentialsFor(profile).then(found => sendResponse({
      ok: !!found,
      profile,
      phase: credentialDiagnostic
    })).catch(error => sendResponse({ ok: false, profile, phase: credentialDiagnostic || 'credential-check-error', error: error.message }));
    return true;
  }
  if (message?.type === 'GET_PORTAL_CREDENTIALS') {
    Promise.race([loadPortalCredentials().catch(() => null), sleep(600).then(() => null)]).then(async value => {
      if (value?.email && value?.password) return value;
      return (await credentialsFor('christian'))?.value || null;
    }).then(value => sendResponse(value || {}));
    return true;
  }
  if (message?.type === 'GET_CREDENTIAL_DIAGNOSTIC') { sendResponse({ ok: true, phase: credentialDiagnostic }); return; }
  if (message?.type === 'GET_RUNTIME_STATUS') {
    Promise.all([chrome.storage.local.get('claimsActiveRun'), chrome.storage.local.get('claimsImportDiagnostic')]).then(([active, diagnostic]) => {
      const saved = active.claimsActiveRun || null;
      sendResponse({ ok: true, active: saved, diagnostic: diagnostic.claimsImportDiagnostic || null });
    });
    return true;
  }
  if (message?.type === 'OPEN_OPTIONS') {
    try {
      const profile = profileKey(message.profile);
      chrome.storage.session.set({ activeProfile: profile }).then(() => chrome.runtime.openOptionsPage());
      sendResponse({ ok: true });
    } catch (error) { sendResponse({ ok: false, error: error.message }); }
    return;
  }
  if (message?.type === 'START_IMPORT' && sender.tab?.id) {
    startImport(sender, message).then(sendResponse).catch(error => sendResponse({ ok: false, error: error.message }));
    return true;
  }
  if (message?.type === 'START_REKON_IMPORT' && sender.tab?.id) {
    startRekonImport(sender, message).then(sendResponse).catch(error => sendResponse({ ok: false, error: error.message }));
    return true;
  }
});
