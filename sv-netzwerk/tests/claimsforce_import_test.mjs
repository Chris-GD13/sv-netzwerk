import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { mergeOnlyBlank, mapClaim, safeFileName } from '../browser-extension/claimsforce-bridge/import-utils.js';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

const merged = mergeOnlyBlank(
  { schaden_nr: '26-123456-1', email: 'manuell@example.de', mobil: '', sanierer_firma: 'Manuell GmbH' },
  { schaden_nr: 'CF-falsch', email: 'claims@example.de', mobil: '+4917012345', sanierer_firma: 'Claims GmbH' }
);
assert.equal(merged.schaden_nr, '26-123456-1', 'manuell geänderte Schadennummer bleibt erhalten');
assert.equal(merged.email, 'manuell@example.de', 'manuell geänderte E-Mail bleibt erhalten');
assert.equal(merged.sanierer_firma, 'Manuell GmbH', 'manuell geänderte Saniererdaten bleiben erhalten');
assert.equal(merged.mobil, '+4917012345', 'nur leere Felder werden ergänzt');

const mapped = mapClaim({
  id: '7a5f5b64-36e1-4a04-a122-85e313f5f42a', insurerClaimId: '26-654321-2', policyNumber: 'VS-44',
  policyholder: { firstName: 'Lena', lastName: 'Prunkl', email: 'vn@example.de', address: { street: 'Brunnenstr. 7', postalCode: '72661', city: 'Grafenberg' } },
  damageLocation: { street: 'Schönbeinstr. 11', postalCode: '72555', city: 'Metzingen' }
}, {}, [{ id: 'termin-1', startDate: '2026-09-01T08:00:00Z', endDate: '2026-09-01T09:00:00Z' }]);
assert.equal(mapped.vn_objekt, 'Lena Prunkl');
assert.equal(mapped.schaden_ort, 'Metzingen');
assert.equal(mapped.claimsforce_termin.id, 'termin-1');

const nestedAddress = mapClaim({
  id: 'claim-2',
  damage: { location: { address: { streetName: 'Musterweg', houseNumber: '8', zip: '70173', locality: 'Stuttgart' } } }
});
assert.deepEqual(
  [nestedAddress.schaden_strasse, nestedAddress.schaden_plz, nestedAddress.schaden_ort],
  ['Musterweg 8', '70173', 'Stuttgart'],
  'Verschachtelte ClaimsForce-Schadenorte werden übernommen'
);

const appointmentAddress = mapClaim({ id: 'claim-3' }, {}, [{
  id: 'termin-2', startDate: '2026-09-02T08:00:00Z', location: 'Terminweg 4, 71522 Backnang'
}]);
assert.deepEqual(
  [appointmentAddress.schaden_strasse, appointmentAddress.schaden_plz, appointmentAddress.schaden_ort],
  ['Terminweg 4', '71522', 'Backnang'],
  'Die echte ClaimsForce-Terminadresse wird verwendet, ohne auf die VN-Anschrift zurückzufallen'
);
assert.equal(safeFileName('KVA: Angebot?.pdf'), 'KVA- Angebot-.pdf');

const manifest = JSON.parse(fs.readFileSync(path.join(root, 'browser-extension/claimsforce-bridge/manifest.json'), 'utf8'));
assert.equal(manifest.manifest_version, 3);
assert.equal(manifest.version, '1.3.1', 'grundlegend reparierte Brücke muss als neue Laufzeitversion erkennbar sein');
assert(manifest.content_scripts.some(entry => entry.matches.includes('https://www.sv-netzwerk.eu/intern/versicherungsfaelle/*')));
assert(manifest.content_scripts.some(entry => entry.matches.includes('https://claimsforce.eu.auth0.com/*')));
assert(manifest.content_scripts.some(entry => entry.js.includes('login-helper.js') && entry.matches.includes('https://*.claimsforce.com/*') && !entry.exclude_matches), 'ClaimsForce-Anmeldehilfe muss auch auf web.claimsforce.com/login laufen');
const claimsLogin = fs.readFileSync(path.join(root, 'browser-extension/claimsforce-bridge/login-helper.js'), 'utf8');
assert(claimsLogin.includes('if (email.value && password.value)') && claimsLogin.includes('button.click()'), 'Bereits von Chrome ausgefüllte ClaimsForce-Zugangsdaten werden automatisch abgesendet');
assert(claimsLogin.includes('svnetSubmittedAt'), 'ClaimsForce-Anmeldeformular darf nicht unkontrolliert doppelt abgesendet werden');
assert(claimsLogin.includes("message?.type !== 'FILL_LOGIN'") && claimsLogin.includes('suppliedCredentials'), 'Service Worker muss Zugangsdaten direkt und nur innerhalb der Erweiterung an die Loginseite übergeben können');
assert(claimsLogin.includes('if (!response?.email || !response?.password) { setTimeout(submit, 250); return; }'), 'Nicht auslesbares Chrome-Autofill wird durch einmaliges Absenden übernommen');
assert(manifest.content_scripts.some(entry => entry.matches.includes('https://www.sv-netzwerk.eu/intern/login/*') && entry.js.includes('portal-login-helper.js')), 'Automatische Prüfportal-Anmeldung ist in der Brücke registriert');
assert(manifest.content_scripts.some(entry => entry.js.includes('portal-login-helper.js') && entry.run_at === 'document_idle'), 'Portal-Anmeldehilfe startet erst am vorhandenen Loginformular');

const portal = fs.readFileSync(path.join(root, 'src/pages/intern/versicherungsfaelle/index.astro'), 'utf8');
assert(portal.includes('Aufträge aus Claims einlesen'));
assert(portal.includes("context.backoffice?`Import für"), 'Susannes ausgewählter Sachverständiger wird angezeigt');
assert(portal.includes("button.dataset.claimsProfile=context.backoffice?key:(context.claims_profile||'self')"), 'Portal verwendet für Susanne und den angemeldeten Sachverständigen das richtige gespeicherte Profil');
assert(portal.includes('Claims-Zugangsdaten verwalten'));
assert(portal.includes('claimsforce-central.js?v=20260828-6'), 'Portal lädt die aktuelle Warteschlangen- und Laufzeitdiagnose ohne alten Browsercache');

const bridge = fs.readFileSync(path.join(root, 'browser-extension/claimsforce-bridge/portal-bridge.js'), 'utf8');
assert(bridge.includes('mergeBlank(existing?.meta || {}, message.mapped)'), 'Import ergänzt bestehende Falldaten nur in leeren Feldern');
assert(bridge.includes("action=save_case"), 'Import nutzt den angemeldeten bzw. von Susanne ausgewählten persönlichen Fallordner');
assert(bridge.includes('claimsforce_calendar_appointment_ids') && !bridge.includes('if (meta.calendar_event ||'), 'Alle ClaimsForce-Termine werden einzeln und ohne Überschreiben eines manuellen Kalendertermins übernommen');
assert(bridge.includes("SVNET_CLAIMS_BRIDGE_READY', version: BRIDGE_VERSION"), 'Portal-Brücke meldet ihre tatsächlich geladene Version');
assert(bridge.includes('SVNET_CLAIMS_RUNTIME_PING') && bridge.includes('GET_RUNTIME_STATUS'), 'Portal kann den persistenten Service-Worker-Laufzustand abfragen');
assert(bridge.includes('data-svnet-claims-runtime') && bridge.includes("join('|')"), 'Persistenter Browserlauf besitzt einen geheimnisfreien DOM-Phasenmarker');
assert(bridge.includes('setInterval(reportRuntime, 5000)') && bridge.includes('reportRuntime();'), 'Brücke aktualisiert ihren Laufmarker unabhängig vom Portalcache');
assert(bridge.includes('PORTAL_UPSERT_ASYNC') && bridge.includes('PORTAL_OPERATION_STATUS') && bridge.includes('operations.set'), 'Lange Portal-Fallanlage wird als kurz abfragbare, idempotente Operation ausgeführt');
const claimsPageBridge = fs.readFileSync(path.join(root, 'browser-extension/claimsforce-bridge/claims-bridge.js'), 'utf8');
assert(claimsPageBridge.includes('waitForDay') && claimsPageBridge.includes('Date.now() + 5000'), 'Brücke wartet nach dem Öffnen eines Termintags auf verzögert geladene Aufträge');
assert(claimsPageBridge.includes('Montag|Dienstag|Mittwoch|Donnerstag|Freitag|Samstag|Sonntag'), 'Nur echte Termintage und keine Termin-Schaltflächen werden geöffnet');
assert(claimsPageBridge.includes("message?.type === 'OPEN_PLANNING'") && claimsPageBridge.includes("link.click()"), 'ClaimsForce-Planung wird innerhalb der angemeldeten Anwendung geöffnet');
assert(claimsPageBridge.includes('WITH_FUTURE_APPOINTMENT') && claimsPageBridge.includes('observedClaims'), 'Aktuelle virtuelle ClaimsForce-Planung wird über Route und beobachtete API-Fallliste ausgelesen');
const claimsMain = fs.readFileSync(path.join(root, 'browser-extension/claimsforce-bridge/claims-main.js'), 'utf8');
assert(claimsMain.includes('response.clone().json()') && claimsMain.includes('CLAIMS_SNAPSHOT'), 'Fall-IDs werden geheimnisfrei aus den bereits geladenen ClaimsForce-Antworten erfasst');

const vault = fs.readFileSync(path.join(root, 'browser-extension/claimsforce-bridge/vault.js'), 'utf8');
assert(vault.includes('credentials_${String(profile'), 'Zugänge werden getrennt je Sachverständigen-Profil gespeichert');
const options = fs.readFileSync(path.join(root, 'browser-extension/claimsforce-bridge/options.js'), 'utf8');
assert(options.includes("OPTIONS_CODE_VERSION = '1.3.0'") && options.includes('chrome.runtime.reload()'), 'Neue entpackte Brückendateien aktivieren ihren Service Worker einmalig selbst');
assert(options.includes("password.value || currentCredentials?.password"), 'Ein bereits gespeichertes Kennwort darf durch erneutes Speichern nicht geleert werden');
assert(options.includes('Kennwort ist verschlüsselt gespeichert'), 'Gespeichertes Kennwort muss ohne Klartext sichtbar bestätigt werden');
assert(options.includes('savePortalCredentials') && options.includes('automatische Prüfportal-Anmeldung'), 'Prüfportal-Zugang kann einmalig verschlüsselt gespeichert werden');
const portalLogin = fs.readFileSync(path.join(root, 'browser-extension/claimsforce-bridge/portal-login-helper.js'), 'utf8');
assert(portalLogin.includes('GET_PORTAL_CREDENTIALS') && portalLogin.includes('requestSubmit'), 'Prüfportal wird nach einem Sitzungsablauf automatisch wieder angemeldet');
assert(portalLogin.includes('if (email.value && password.value)') && portalLogin.includes("button[type=\"submit\"]") , 'Bereits vom Browser ausgefüllte Portal-Zugangsdaten werden automatisch abgesendet');
assert(portalLogin.includes('if (!document.documentElement)') && portalLogin.includes('watchPortalLogin'), 'Portal-Anmeldehilfe muss auch bei document_start auf das entstehende Formular warten');
assert(portalLogin.includes('data-svnet-portal-login') && portalLogin.includes("mark('submitted')"), 'Portal-Anmeldehilfe liefert geheimnisfreie DOM-Laufzeitphasen');

const drive = fs.readFileSync(path.join(root, 'public/intern/api/google-drive-sync.php'), 'utf8');
assert(drive.includes("'claims_profile'=>\$claimsProfile"), 'Status liefert das Claims-Profil des angemeldeten Sachverständigen');
assert(drive.includes("==='administrator'"), 'Administratoren können die Claims-Anmeldungen aller Sachverständigen zentral bedienen');
assert(drive.includes("'claims_agent'=>"), 'Administrator wird als zentrale Importstation erkannt');
const queue = fs.readFileSync(path.join(root, 'public/intern/api/claimsforce-queue.php'), 'utf8');
assert(queue.includes('claimsforce_import_jobs') && queue.includes("$action==='claim'"), 'Zentrale serverseitige Importwarteschlange fehlt');
assert(queue.includes('heartbeat_at') && queue.includes("$action==='heartbeat'") && queue.includes("$action==='active'"), 'Verwaiste Browserimporte müssen per Heartbeat erkannt und wiederaufgenommen werden');
assert(queue.includes("phase='CF-RECOVER'") && queue.includes('attempt_count=attempt_count+1'), 'Wiederaufnahme muss als eigener Versuch nachvollziehbar sein');
assert(queue.includes("$action==='mine'") && queue.includes('WHERE requested_by=:u ORDER BY id DESC LIMIT 20'), 'Eigene Warteschlangenläufe müssen nach einem Portal-Neuladen wiedergefunden werden');
const central = fs.readFileSync(path.join(root, 'public/intern/claimsforce-central.js'), 'utf8');
assert(central.includes("action=status&id=") && central.includes("SVNET_CLAIMS_IMPORT_START"), 'Portal kann zentrale Importe starten und verfolgen');
assert(central.includes("timeZone:'Europe/Berlin'") && central.includes("!==3") && central.includes("['christian','jens','marc','holger']"), 'Täglicher 03:00-Uhr-Import plant alle vier ClaimsForce-Zugänge in Berliner Zeit ein');
assert(central.includes("job.profile==='jens'?'christian':job.profile"), 'Ehemalige Jens-Maurer-Fälle werden ausschließlich Christians Portalordner zugeordnet');
assert(central.includes("profile==='christian'?['christian','jens']:[profile]"), 'Manueller Christian-Import liest auch die ehemaligen Jens-Maurer-Fälle ein');
assert(central.includes("post('active')") && central.includes("post('heartbeat'"), 'Zentrale Station verbindet sich nach einem Browserneustart wieder mit dem laufenden Import');
assert(central.includes("action=mine") && central.includes('resumeWatch()'), 'Portal stellt die sichtbare Überwachung bereits eingereihter Importe wieder her');
assert(central.includes("job.status==='done'?'abgeschlossen':'fehlgeschlagen'") && central.includes("slice(0,4)"), 'Portal zeigt auch die letzten terminalen Profilaufträge mit Phase und Ergebnis an');
assert(central.includes('setTimeout(()=>show(text,failed),1000)'), 'Terminaler Importstatus bleibt nach dem einmaligen alten Bridge-Ready-Ereignis sichtbar');
assert(central.includes('data-svnet-claims-jobs') && central.includes("`${job.id}|${job.profile}|${job.status}|${job.phase"), 'Letzte Serverergebnisse sind unabhängig von UI-Listenern geheimnisfrei im DOM prüfbar');
assert(central.includes('data-svnet-claims-results') && central.includes('resultOf'), 'Der Live-Nachweis enthält sichere Fall-, Datei-, Nachrichten- und Terminzahlen');
assert(central.includes('SVNET_CLAIMS_RUNTIME_STATUS') && central.includes('SVNET_CLAIMS_RUNTIME_PING'), 'Portal zeigt den persistenten Browserlauf auch nach einem Worker-Neustart an');
assert(central.includes('Browser-Brücke 1.3.0 erforderlich') && central.includes("versionAtLeast(bridgeVersion,'1.3.0')"), 'Veraltete geladene Erweiterungen dürfen keine neuen Warteschlangenläufe starten');
const optionsHtml = fs.readFileSync(path.join(root, 'browser-extension/claimsforce-bridge/options.html'), 'utf8');
assert(optionsHtml.includes('Ehem. Jens Maurer → Christian Wächter'), 'Jens-Zugang ist in der verschlüsselten Zugangsverwaltung auswählbar');
assert(manifest.permissions.includes('nativeMessaging'), 'Lokaler Zugangsdaten-Leser ist für den unbeaufsichtigten Import freigegeben');
assert(fs.readFileSync(path.join(root, 'browser-extension/claimsforce-bridge/service-worker.js'), 'utf8').includes('sendNativeMessage'), 'Brücke kann Zugangsdaten aus der freigegebenen lokalen Datei lesen');
assert(fs.readFileSync(path.join(root, 'browser-extension/claimsforce-bridge/service-worker.js'), 'utf8').includes("chrome.runtime.getURL('local-config.json')"), 'Brücke besitzt einen lokalen 127.0.0.1-Fallback für die Zugangsdaten');
assert(fs.readFileSync(path.join(root, 'browser-extension/claimsforce-bridge/service-worker.js'), 'utf8').includes('sleep(800).then(() => null)'), 'Ein hängender nativer Zugangsdatenkanal darf den Loopback-Fallback nicht blockieren');
const serviceWorker = fs.readFileSync(path.join(root, 'browser-extension/claimsforce-bridge/service-worker.js'), 'utf8');
assert(serviceWorker.includes('loadCredentials(profile).catch(() => null), sleep(600)') && serviceWorker.includes('loadPortalCredentials().catch(() => null), sleep(600)'), 'Ein hängender Browser-Tresor darf weder ClaimsForce- noch Portal-Anmeldung blockieren');
assert(serviceWorker.includes('GET_CREDENTIAL_DIAGNOSTIC') && serviceWorker.includes('loopback-http-'), 'Zugangsdatenkette liefert geheimnisfreie Laufzeitstufen');
assert(serviceWorker.includes("type: 'FILL_LOGIN'") && serviceWorker.includes('claimsTab(profile, run, credential)'), 'Der Importlauf muss den ClaimsForce-Anmeldehelfer aktiv anstoßen');
assert(serviceWorker.includes("!currentProfile && profile === 'christian' && openSession") && serviceWorker.includes('claimsLoggedProfile: profile'), 'Nur eine vorhandene unbekannte Christian-Sitzung darf ohne Profilwechsel übernommen werden');
assert(serviceWorker.includes("{ type: 'OPEN_PLANNING' }") && !serviceWorker.includes("chrome.tabs.update(tab.id, { url: PLANNING_URL })"), 'Erfolgreiche ClaimsForce-Anmeldung darf nicht durch einen vollständigen Seitenwechsel verloren gehen');
assert(manifest.host_permissions.includes('http://127.0.0.1:47831/*'), 'Lokaler Zugangsdaten-Helfer ist auf den festgelegten Loopback-Port begrenzt');
assert(serviceWorker.includes('accepted: true') && serviceWorker.includes('claims-import-keepalive'), 'Ein Import darf nicht mehr an einem einzigen lang laufenden Erweiterungs-Nachrichtenkanal hängen');
assert(serviceWorker.includes('CF-CASE-06') && serviceWorker.includes('completedCases'), 'Jeder vollständig verarbeitete Portal-Fall wird ohne Geheimnisse als Laufzeitbeleg gemeldet');
assert(serviceWorker.includes('CF-CASE-FETCH') && serviceWorker.includes('controller.abort()') && serviceWorker.includes('innerhalb von 30 Sekunden'), 'Externe Fall- und Portalabrufe besitzen sichtbare Laufphasen und feste Zeitgrenzen');
assert(serviceWorker.includes('return await response.json()') && serviceWorker.includes('fileBuffer = await response.arrayBuffer()') && serviceWorker.includes('CF-CASE-UPSERT'), 'Zeitgrenzen umfassen Antwortkörper und der Portal-Speicherschritt ist separat sichtbar');
assert(serviceWorker.includes("saved?.status === 'running'") && serviceWorker.includes("startImport({ tab: { id: Number(saved.portalTabId) } }, saved)"), 'Ein neu geweckter MV3-Worker nimmt seinen persistenten Lauf automatisch wieder auf');
assert(serviceWorker.includes('portalOperation') && serviceWorker.includes('PORTAL_OPERATION_STATUS') && serviceWorker.includes('CF-CASE-FILES'), 'Worker wartet über kurze Statusabfragen auf Portal-Fallanlage und fährt danach mit Anlagen fort');
assert(serviceWorker.includes('for (const appointment of Array.isArray(appointments)') && serviceWorker.includes('appointmentsDone'), 'Alle vorhandenen ClaimsForce-Termine eines Falls werden übernommen');
assert(!serviceWorker.includes('console.log') && !serviceWorker.includes('console.error'), 'ClaimsForce-Laufzeit darf keine Zugangsdaten oder Tokens protokollieren');
assert(vault.includes("AES-GCM"), 'Kennwörter werden verschlüsselt gespeichert');

console.log('ClaimsForce-Import: Zuordnung, Bestandsschutz, Zugangstresor und Browser-Brücke geprüft.');
