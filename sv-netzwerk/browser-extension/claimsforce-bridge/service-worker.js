import { clearCredentials, loadCredentials, loadPortalCredentials, saveCredentials } from './vault.js';
import { mapClaim, safeFileName } from './import-utils.js';

const CREDENTIAL_HOST = 'eu.svnetzwerk.claimsforce_credentials';
const PLANNING_URL = 'https://web.claimsforce.com/planning?bucket=WITH_FUTURE_APPOINTMENT#with-future-appointment';
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
  if (alarm.name !== DAILY_IMPORT_ALARM) return;
  wakeCentralImportStation().finally(() => scheduleDailyImportAlarm().catch(() => {}));
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

async function openPlanning(tabId) {
  let tab = await chrome.tabs.get(tabId);
  const current = String(tab.url || '');
  if (!current.includes('/planning') || !current.includes('WITH_FUTURE_APPOINTMENT')) {
    await chrome.tabs.update(tabId, { url: PLANNING_URL });
    tab = await waitTab(tabId);
  }
  if (!String(tab.url || '').includes('/planning')) throw new Error('ClaimsForce-Planung konnte nicht geöffnet werden.');
  await sleep(2000);
  const opened = await chrome.tabs.sendMessage(tabId, { type: 'OPEN_FUTURE_APPOINTMENTS' }).catch(() => null);
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
  const portalTabId = run.portalTabId, profile = profileKey(run.profile);
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
  const planning = await openPlanning(tab.id);
  await diagnostic(run, 'CF-PLAN-04', 'Planungsansicht „Mit Termin“ wurde angefordert.', { strategy: planning?.strategy || 'bestehende Ansicht' });
  const scraped = await chrome.tabs.sendMessage(tab.id, { type: 'SCRAPE_CLAIMS' });
  const claims = scraped?.claims || [];
  await diagnostic(run, 'CF-LIST-05', `${claims.length} Auftrag/Aufträge wurden in der Planungsansicht erkannt.`, { count: claims.length, openTasks, observedApi: scraped?.observedClaims || 0, route: scraped?.route || safeRoute((await chrome.tabs.get(tab.id)).url) });
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
    const preliminaryState = await portal(portalTabId, { type: 'PORTAL_SYNC_STATE', mapped: preliminary, profile });
    const preliminaryMeta = preliminaryState.result?.meta || {};
    if (preliminaryState.result?.existed && item.listVersion && preliminaryMeta.claimsforce_list_version === item.listVersion) {
      skipped++;
      await progress(portalTabId, `Auftrag ${index + 1}/${claims.length}: seit dem letzten Import unverändert, wird ohne erneuten Detailabruf übersprungen.`, index + 1, claims.length);
      await diagnostic(run, 'CF-CASE-DELTA-SKIP', `Auftrag ${index + 1}/${claims.length} ist laut ClaimsForce-Änderungsstand unverändert.`, { current: index + 1, total: claims.length, claimIndex: index + 1, skippedCases: skipped });
      continue;
    }
    await diagnostic(run, 'CF-CASE-FETCH', `Auftrag ${index + 1}/${claims.length}: Falldaten werden geladen.`, { current: index, total: claims.length, claimIndex: index + 1 });
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
    const files = Array.isArray(unwrap(rawFiles, 'files')) ? unwrap(rawFiles, 'files') : [];
    const messages = Array.isArray(unwrap(rawMessages, 'messages')) ? unwrap(rawMessages, 'messages') : [];
    const mapped = mapClaim(disposition, communication, Array.isArray(appointments) ? appointments : [], rawStakeholders || {});
    const fileVersions = files.map(fileVersion).filter(Boolean);
    const messageVersions = messages.map(messageVersion).filter(Boolean);
    const stableMapped = { ...mapped };
    delete stableMapped.claimsforce_zuletzt_eingelesen;
    const appointmentVersions = (Array.isArray(appointments) ? appointments : []).map(appointment => [appointment?.id, appointment?.updatedAt, appointment?.startDate, appointment?.endDate].map(value => String(value || '')).join('|'));
    const signature = await fingerprint({ mapped: stableMapped, fileVersions, messageVersions, appointmentVersions });
    const state = await portal(portalTabId, { type: 'PORTAL_SYNC_STATE', mapped, profile });
    const existingMeta = state.result?.meta || {};
    if (state.result?.existed && existingMeta.claimsforce_sync_signature === signature) {
      skipped++;
      await progress(portalTabId, `Auftrag ${index + 1}/${claims.length}: unverändert, wird übersprungen.`, index + 1, claims.length);
      await diagnostic(run, 'CF-CASE-SKIP', `Auftrag ${index + 1}/${claims.length} ist bereits vollständig und unverändert vorhanden.`, { current: index + 1, total: claims.length, claimIndex: index + 1, skippedCases: skipped });
      continue;
    }
    await diagnostic(run, 'CF-CASE-UPSERT', `Auftrag ${index + 1}/${claims.length}: Portal-Fall wird angelegt oder ergänzt.`, { current: index, total: claims.length, claimIndex: index + 1 });
    const upsert = await portalOperation(portalTabId, { type: 'PORTAL_UPSERT_ASYNC', operationId: `${run.runId}:upsert:${id}`, mapped, profile, source: { claim: disposition, communication, stakeholders: rawStakeholders || {}, importedAt: new Date().toISOString() } });
    const folderId = upsert.folderId;
    await diagnostic(run, 'CF-CASE-FILES', `Auftrag ${index + 1}/${claims.length}: Anhänge und Nachrichten werden übernommen.`, { current: index, total: claims.length, claimIndex: index + 1 });
    const knownFileVersions = new Set(Array.isArray(existingMeta.claimsforce_file_versions) ? existingMeta.claimsforce_file_versions.map(String) : []);
    for (const file of files) {
      if (!file?.id) continue;
      const version = fileVersion(file);
      if (knownFileVersions.has(version)) continue;
      const name = safeFileName(file.name || file.fileName || file.originalFilename, `ClaimsForce-${file.id}`);
      await progress(portalTabId, `${mapped.schaden_nr || item.label}: ${name}`, index, claims.length);
      const url = `${config.FILES_API_ENDPOINT}/claims/${encodeURIComponent(id)}/files/${encodeURIComponent(file.id)}?token=${encodeURIComponent(token)}`;
      const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 30000);
      let response, fileBuffer;
      try { response = await fetch(url, { signal: controller.signal }); if (response.ok) fileBuffer = await response.arrayBuffer(); }
      catch (error) { throw new Error(error?.name === 'AbortError' ? `Datei „${name}“ hat das Zeitlimit überschritten.` : `Datei „${name}“ konnte nicht geladen werden.`); }
      finally { clearTimeout(timer); }
      if (!response.ok) throw new Error(`Datei „${name}“ konnte nicht geladen werden (${response.status}).`);
      const uploaded = await uploadBuffer(portalTabId, folderId, name, file.mimeType || file.contentType || response.headers.get('content-type'), Date.parse(file.updatedAt || file.createdAt || '') || 0, fileBuffer);
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
      const uploaded = await uploadBuffer(portalTabId, folderId, `Mail_ClaimsForce-Nachricht_${stamp}_${subject}.json`, 'application/json', Date.parse(record?.updatedAt || record?.createdAt || '') || 0, bytes.buffer);
      if (!uploaded?.result?.duplicate && !uploaded?.result?.excluded) messagesDone++;
    }
    if (!['christian', 'jens'].includes(profile)) {
      for (const appointment of Array.isArray(appointments) ? appointments : []) {
        if (!appointment?.startDate) continue;
        const appointmentResult = await portal(portalTabId, { type: 'PORTAL_APPOINTMENT', folderId, appointment, profile });
        if (!appointmentResult?.result?.skipped) appointmentsDone++;
      }
    }
    await portal(portalTabId, { type: 'PORTAL_COMMIT_SYNC', folderId, signature, fileVersions, messageVersions, listVersion: item.listVersion || '', profile });
    updated++;
    await diagnostic(run, 'CF-CASE-06', `Auftrag ${index + 1}/${claims.length} wurde vollständig im Portal verarbeitet.`, { current: index + 1, total: claims.length, completedCases: index + 1, folderCreatedOrUpdated: true });
  }
  await progress(portalTabId, `${claims.length} Aufträge geprüft: ${updated} aktualisiert, ${skipped} unverändert übersprungen, ${filesDone} neue Dateien, ${messagesDone} neue Nachrichten und ${appointmentsDone} neue Termine.`, claims.length, claims.length);
  await chrome.storage.session.set({ claimsLoggedProfile: profile });
  await chrome.storage.local.set({ claimsLoggedProfile: profile });
  return { claims: claims.length, openTasks, updated, skipped, files: filesDone, messages: messagesDone, appointments: appointmentsDone };
}

async function startImport(sender, message) {
  const portalTabId = sender.tab?.id;
  if (!portalTabId) return { ok: false, error: '[CF-RUN-00] Portal-Registerkarte fehlt.' };
  const requested = { runId: message.runId || crypto.randomUUID(), jobId: Number(message.jobId || 0), profile: profileKey(message.profile), portalTabId, startedAt: new Date().toISOString() };
  if (runningImport) {
    if (runningImport.jobId === requested.jobId && runningImport.profile === requested.profile) return { ok: true, accepted: true, resumed: false, runId: runningImport.runId };
    return { ok: false, error: '[CF-RUN-00] Ein anderer ClaimsForce-Import läuft bereits.' };
  }
  const saved = (await chrome.storage.local.get('claimsActiveRun')).claimsActiveRun;
  const resumesSaved = saved?.status === 'running' && saved.jobId === requested.jobId && saved.profile === requested.profile;
  const run = resumesSaved ? { ...saved, portalTabId, runId: saved.runId || requested.runId } : requested;
  runningImport = run;
  runImport(run).then(async result => {
    await chrome.storage.local.set({ claimsActiveRun: { ...run, status: 'done', result, finishedAt: new Date().toISOString() } });
    await chrome.tabs.sendMessage(portalTabId, { type: 'IMPORT_DONE', result, runtime: { runId: run.runId, jobId: run.jobId, phase: 'CF-DONE-07' } }).catch(() => {});
  }).catch(async error => {
    const message = String(error?.message || 'ClaimsForce-Import fehlgeschlagen.').slice(0, 500);
    await chrome.storage.local.set({ claimsActiveRun: { ...run, status: 'failed', error: message, finishedAt: new Date().toISOString() } });
    await chrome.tabs.sendMessage(portalTabId, { type: 'IMPORT_ERROR', error: message, runtime: { runId: run.runId, jobId: run.jobId, phase: 'CF-FAIL-99' } }).catch(() => {});
  }).finally(() => { runningImport = null; });
  return { ok: true, accepted: true, resumed: resumesSaved, runId: run.runId };
}

chrome.runtime.onConnect.addListener(port => {
  if (port.name !== 'claims-import-keepalive') return;
  port.onMessage.addListener(() => {});
});

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
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
});
