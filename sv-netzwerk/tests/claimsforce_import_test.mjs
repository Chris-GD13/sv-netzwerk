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

const nestedStakeholder = mapClaim({ insurerClaimId: '65-1196231-20' }, {}, [], { stakeholders: [{ stakeholderType: 'VERSICHERUNGSNEHMER', firstName: 'Rolf', lastName: 'Philipp', address: { street: 'Saaleweg', houseNumber: '5', postalCode: '71522', city: 'Backnang' }, contractNumber: '12-5392833-60' }] });
assert.equal(nestedStakeholder.versicherungsschein_nr, '12-5392833-60', 'Verschachtelte ClaimsForce-Vertragsnummer muss übernommen werden');
assert.equal(nestedStakeholder.vn_objekt, 'Rolf Philipp', 'Verschachtelter Versicherungsnehmer muss übernommen werden');
assert.deepEqual([nestedStakeholder.strasse, nestedStakeholder.plz, nestedStakeholder.ort], ['Saaleweg 5', '71522', 'Backnang'], 'Verschachtelte VN-Anschrift muss übernommen werden');

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
assert.equal(manifest.version, '1.3.17', 'die gegen Profilverwechslung und Ausfall der Morgenplanung abgesicherte Brücke muss als neue Laufzeitversion erkennbar sein');
assert(manifest.content_scripts.some(entry => entry.matches.includes('https://www.sv-netzwerk.eu/intern/versicherungsfaelle/*')));
assert(manifest.content_scripts.some(entry => entry.matches.includes('https://claimsforce.eu.auth0.com/*')));
assert(manifest.content_scripts.some(entry => entry.js.includes('login-helper.js') && entry.matches.includes('https://*.claimsforce.com/*') && !entry.exclude_matches), 'ClaimsForce-Anmeldehilfe muss auch auf web.claimsforce.com/login laufen');
const claimsLogin = fs.readFileSync(path.join(root, 'browser-extension/claimsforce-bridge/login-helper.js'), 'utf8');
assert(claimsLogin.includes('if (email.value && password.value)') && claimsLogin.includes('button.click()'), 'Bereits von Chrome ausgefüllte ClaimsForce-Zugangsdaten werden automatisch abgesendet');
assert(claimsLogin.includes('submissionAttempted = false') && claimsLogin.includes('if (submitLoop || submissionAttempted) return') && claimsLogin.includes('submissionAttempted = true'), 'Eine geladene ClaimsForce-Anmeldeseite darf höchstens einen einzigen Anmeldeversuch auslösen');
assert(claimsLogin.indexOf("const response = credentials ||") < claimsLogin.indexOf('if (email.value && password.value)'), 'Ausdrücklich gewählte Profilzugänge müssen Chrome-Autofill vor dem Absenden überschreiben');
assert(claimsLogin.includes("message?.type !== 'FILL_LOGIN'") && claimsLogin.includes('suppliedCredentials'), 'Service Worker muss Zugangsdaten direkt und nur innerhalb der Erweiterung an die Loginseite übergeben können');
assert(claimsLogin.includes('if (!response?.email || !response?.password)') && claimsLogin.includes('if (email.value && password.value) submitWhenReady()'), 'Chrome-Autofill wird nur verwendet, wenn keine ausdrücklich gewählten Profilzugänge verfügbar sind');
assert(claimsLogin.includes("button && !button.disabled") && claimsLogin.includes('attempts < 30') && !claimsLogin.includes('form.requestSubmit?.()'), 'Die Brücke wartet nur auf den verzögert aktivierten ClaimsForce-Anmeldebutton und sendet niemals über einen zweiten Formularweg ab');
assert(claimsLogin.includes('node._valueTracker?.setValue(previous)') && claimsLogin.includes("new InputEvent('input'"), 'ClaimsForce-React muss die eingesetzten Profilzugänge als echte Eingabe erkennen');
assert(claimsLogin.includes('input[inputmode="email"]') && claimsLogin.includes('if (!password || !button) return'), 'Die aktuelle ClaimsForce-Anmeldeseite ohne HTML-Formular und mit Text-E-Mailfeld wird unterstützt');
assert(claimsLogin.includes('new MutationObserver(() => fillLogin())') && !claimsLogin.includes('setInterval('), 'Die Felder dürfen nach einem verzögerten ClaimsForce-Neuaufbau erkannt werden, ohne eine endlose Anmeldeschleife zu starten');
assert(claimsLogin.includes('sendResponse({ ok: !!ready })'), 'Der Service Worker erhält nur dann eine Anmeldebestätigung, wenn die Felder tatsächlich gefunden wurden');
assert(manifest.content_scripts.some(entry => entry.matches.includes('https://www.sv-netzwerk.eu/intern/login/*') && entry.js.includes('portal-login-helper.js')), 'Automatische Prüfportal-Anmeldung ist in der Brücke registriert');
assert(manifest.content_scripts.some(entry => entry.js.includes('portal-login-helper.js') && entry.run_at === 'document_idle'), 'Portal-Anmeldehilfe startet erst am vorhandenen Loginformular');

const portal = fs.readFileSync(path.join(root, 'src/pages/intern/versicherungsfaelle/index.astro'), 'utf8');
const internEntry = fs.readFileSync(path.join(root, 'src/pages/intern/index.astro'), 'utf8');
const internLayout = fs.readFileSync(path.join(root, 'src/layouts/InternalLayout.astro'), 'utf8');
assert(internEntry.includes("location.replace('/intern/login/')"), 'Der Aufruf von /intern/ führt ohne Umweg auf die Anmeldeseite');
assert(internLayout.includes('class="intern-header__login" href="/intern/login/"') && internLayout.includes('Anmelden</a>'), 'Neben der Abmeldung ist dauerhaft ein direkter Anmeldebutton sichtbar');
assert(portal.includes('Aufträge aus Claims einlesen'));
assert(portal.includes('target.textContent=`Import für ${names[raw]}${folder?` · Ziel: ${folder}`'), 'Ausgewählter Sachverständiger und persönlicher Fallordner werden als Importziel angezeigt');
assert(portal.includes('button.dataset.claimsProfile=raw') && portal.includes("supported.includes(raw)"), 'Portal übergibt ausschließlich ein validiertes Bearbeiterprofil');
assert(portal.includes('Claims-Zugangsdaten verwalten'));
assert(portal.includes('claimsforce-central.js?v=20260903-3'), 'Portal lädt die kompatible Brückensteuerung ohne alten Browsercache');
for (const [key, label] of [['christian','Christian Wächter'],['holger','Holger Roth'],['marc','Marc Schütt'],['jens','Jens Maurer']]) assert(portal.includes(`<option value="${key}">${label}</option>`), `${label} ist als Bearbeiterprofil auswählbar`);
assert(!portal.includes('<option value="susanne"') && !portal.includes('Susanne Wächter</option>'), 'Susanne darf nicht als eigenes Bearbeiterprofil erscheinen');
assert(portal.includes("sessionStorage.removeItem('svnet-case')") && portal.includes("localStorage.removeItem('svnet-case')"), 'Profilwechsel löscht den aktiven Fall aus beiden Browser-Speichern');

const bridge = fs.readFileSync(path.join(root, 'browser-extension/claimsforce-bridge/portal-bridge.js'), 'utf8');
assert(bridge.includes('mergeBlank(existing?.meta || {}, message.mapped)'), 'Import ergänzt bestehende Falldaten nur in leeren Feldern');
assert(bridge.includes("SUPPORTED_PROFILES = ['christian', 'holger', 'marc', 'jens']") && bridge.includes('profileKey(event.data.profile)'), 'Portal-Brücke übernimmt nur das ausdrücklich übergebene unterstützte Profil');
assert(!bridge.includes("profile: event.data.profile || 'self'") && !bridge.includes("profile: event.data.profile || 'christian'"), 'Portal-Brücke darf ein übergebenes Profil nicht stillschweigend ersetzen');
assert(bridge.includes('PORTAL_SYNC_STATE') && bridge.includes('PORTAL_COMMIT_SYNC'), 'Portal stellt einen dauerhaften Änderungsstand pro ClaimsForce-Fall bereit');
assert(bridge.includes("action=save_case"), 'Import nutzt den angemeldeten bzw. von Susanne ausgewählten persönlichen Fallordner');
assert(bridge.includes('claimsforce_calendar_appointment_ids') && !bridge.includes('if (meta.calendar_event ||'), 'Alle ClaimsForce-Termine werden einzeln und ohne Überschreiben eines manuellen Kalendertermins übernommen');
assert(bridge.includes("form.append('claims_profile', profile)"), 'Jeder ClaimsForce-Termin übergibt ausschließlich sein validiertes Sachverständigenprofil an die Kalender-API');
assert(bridge.includes('merged.claimsforce_profile = profile') && bridge.includes('meta.claimsforce_profile = profile'), 'Das validierte ClaimsForce-Profil wird dauerhaft in den Falldaten gespeichert');
assert(bridge.includes("SVNET_CLAIMS_BRIDGE_READY', version: BRIDGE_VERSION"), 'Portal-Brücke meldet ihre tatsächlich geladene Version');
assert(bridge.includes('SVNET_CLAIMS_RUNTIME_PING') && bridge.includes('GET_RUNTIME_STATUS'), 'Portal kann den persistenten Service-Worker-Laufzustand abfragen');
assert(bridge.includes('data-svnet-claims-runtime') && bridge.includes("join('|')"), 'Persistenter Browserlauf besitzt einen geheimnisfreien DOM-Phasenmarker');
assert(bridge.includes('setInterval(reportRuntime, 5000)') && bridge.includes('reportRuntime();'), 'Brücke aktualisiert ihren Laufmarker unabhängig vom Portalcache');
assert(bridge.includes('invalidExtensionContext') && bridge.includes('safeSendResponse') && bridge.includes('CF-EXTENSION-RELOAD'), 'Ein nach Erweiterungsaktualisierung ungültiger Kontext wird abgefangen und verständlich gemeldet');
assert(!bridge.includes('chrome.runtime.sendMessage(activeRequest)'), 'Ein Verbindungsabbruch darf denselben Import nicht automatisch erneut starten');
assert(bridge.includes('PORTAL_UPSERT_ASYNC') && bridge.includes('PORTAL_OPERATION_STATUS') && bridge.includes('operations.set'), 'Lange Portal-Fallanlage wird als kurz abfragbare, idempotente Operation ausgeführt');
const claimsPageBridge = fs.readFileSync(path.join(root, 'browser-extension/claimsforce-bridge/claims-bridge.js'), 'utf8');
assert(claimsPageBridge.includes('waitForDay') && claimsPageBridge.includes('Date.now() + 5000'), 'Brücke wartet nach dem Öffnen eines Termintags auf verzögert geladene Aufträge');
assert(claimsPageBridge.includes('Montag|Dienstag|Mittwoch|Donnerstag|Freitag|Samstag|Sonntag'), 'Nur echte Termintage und keine Termin-Schaltflächen werden geöffnet');
assert(claimsPageBridge.includes("message?.type === 'OPEN_PLANNING'") && claimsPageBridge.includes("link.click()"), 'ClaimsForce-Planung wird innerhalb der angemeldeten Anwendung geöffnet');
assert(claimsPageBridge.includes('WITH_FUTURE_APPOINTMENT') && claimsPageBridge.includes('observedClaims'), 'Aktuelle virtuelle ClaimsForce-Planung wird über Route und beobachtete API-Fallliste ausgelesen');
assert(claimsPageBridge.includes("message?.type === 'OPEN_TASKS'") && claimsPageBridge.includes("message?.type === 'READ_OPEN_TASKS'"), 'Die Brücke öffnet ausdrücklich den Reiter Aufgaben und liest dort die Kachel „Alle“');
assert(claimsPageBridge.includes('openTasks: count') && !claimsPageBridge.includes('allCount: readAllCount()'), 'Die Schadenanzahl aus der Planung darf nicht mehr als offene Aufgaben ausgegeben werden');
const claimsMain = fs.readFileSync(path.join(root, 'browser-extension/claimsforce-bridge/claims-main.js'), 'utf8');
assert(claimsMain.includes('response.clone().json()') && claimsMain.includes('CLAIMS_SNAPSHOT'), 'Fall-IDs werden geheimnisfrei aus den bereits geladenen ClaimsForce-Antworten erfasst');
assert(claimsMain.includes('inspectTokenCache') && claimsMain.includes('inspectStorage(localStorage)'), 'Ein vorhandenes ClaimsForce-Token wird nach einem Worker-Neustart auch aus dem Auth-Cache wiederhergestellt');

const vault = fs.readFileSync(path.join(root, 'browser-extension/claimsforce-bridge/vault.js'), 'utf8');
assert(vault.includes('credentials_${profile}') && vault.includes("SUPPORTED_PROFILES = ['christian', 'holger', 'marc', 'jens']"), 'Zugänge werden nur für die vier unterstützten Sachverständigen-Profile getrennt gespeichert');
const options = fs.readFileSync(path.join(root, 'browser-extension/claimsforce-bridge/options.js'), 'utf8');
assert(options.includes("OPTIONS_CODE_VERSION = '1.3.17'"), 'Die Optionsseite kennzeichnet die aktuelle Brückenversion');
assert(options.includes('profileEmails') && options.includes('Die E-Mail-Adresse gehört nicht zum ausgewählten Bearbeiterprofil'), 'Die Optionsseite verhindert das Speichern eines fremden ClaimsForce-Kontos unter dem gewählten SV-Profil');
assert(!options.includes('chrome.runtime.reload()'), 'Die Optionsseite darf den laufenden Erweiterungskontext nicht selbst entwerten');
assert(options.includes("password.value || currentCredentials?.password"), 'Ein bereits gespeichertes Kennwort darf durch erneutes Speichern nicht geleert werden');
assert(options.includes('Kennwort ist verschlüsselt gespeichert'), 'Gespeichertes Kennwort muss ohne Klartext sichtbar bestätigt werden');
assert(options.includes('savePortalCredentials') && options.includes('automatische Prüfportal-Anmeldung'), 'Prüfportal-Zugang kann einmalig verschlüsselt gespeichert werden');
const portalLogin = fs.readFileSync(path.join(root, 'browser-extension/claimsforce-bridge/portal-login-helper.js'), 'utf8');
assert(portalLogin.includes('GET_PORTAL_CREDENTIALS') && portalLogin.includes('requestSubmit'), 'Prüfportal wird nach einem Sitzungsablauf automatisch wieder angemeldet');
assert(portalLogin.includes('if (email.value && password.value)') && portalLogin.includes("button[type=\"submit\"]") , 'Bereits vom Browser ausgefüllte Portal-Zugangsdaten werden automatisch abgesendet');
assert(portalLogin.includes('if (!document.documentElement)') && portalLogin.includes('watchPortalLogin'), 'Portal-Anmeldehilfe muss auch bei document_start auf das entstehende Formular warten');
assert(portalLogin.includes('data-svnet-portal-login') && portalLogin.includes("mark('submitted')"), 'Portal-Anmeldehilfe liefert geheimnisfreie DOM-Laufzeitphasen');

const drive = fs.readFileSync(path.join(root, 'public/intern/api/google-drive-sync.php'), 'utf8');
const routing = fs.readFileSync(path.join(root, 'public/intern/api/profile-routing.php'), 'utf8');
const calendarApi = fs.readFileSync(path.join(root, 'public/intern/api/outlook-case-calendar.php'), 'utf8');
assert(calendarApi.includes("function ocTargetProfileKey") && calendarApi.includes("$_SESSION['svnet_selected_expert']"), 'Die Kalender-API routet die zentrale Importstation nach dem ausgewählten Sachverständigen');
assert(calendarApi.includes('ocAssertClaimsProfile') && calendarApi.includes('ClaimsForce-Profil und persönlicher Fallordner stimmen nicht überein'), 'Claims-Profil, persönlicher Fallordner und Zielkalender müssen serverseitig zusammenpassen');
assert(calendarApi.includes("$requestedClaimsProfile==='christian'") && calendarApi.includes('Termine für Christian werden ausschließlich von Susanne händisch erfasst'), 'Die Kalender-API blockiert automatische Claims-Termine nur für Christians Zielkalender');
assert(calendarApi.includes("$action==='delete_claimsforce_event'") && calendarApi.includes("'Calender Christian'") && calendarApi.includes("'cw@sv-schuett.eu'"), 'Die Bereinigung darf nur einen exakt belegten fremden ClaimsForce-Termin aus Christians Kalender löschen');
assert(drive.includes("'claims_profile'=>\$claimsProfile"), 'Status liefert das Claims-Profil des angemeldeten Sachverständigen');
assert(drive.includes("$isBackoffice=gdIsSusanne($portalUser)") && drive.includes("$claimsAgent=$isBackoffice") && routing.includes("(string)($user['role'] ?? '') === 'administrator'"), 'Susanne und der Administratorzugang werden als zentrale Backoffice-Importstation erkannt');
assert(routing.includes("'jens' => array_merge") && routing.includes("'full_name'=>'Jens Maurer'") && routing.includes("'svnet_expert_profile'=>'jens'"), 'Jens besitzt eine eigenständige Backend-Identität');
assert(routing.includes("'jens' => 'Schadenfälle Jens Maurer'") && drive.includes("if($profile==='christian')return"), 'Jens erhält einen eigenen Fallordner und nur Christian verwendet den Legacy-Ordner');
assert(routing.includes("default => throw new InvalidArgumentException") && routing.includes('Das gespeicherte Bearbeiterprofil ist ungültig'), 'Unbekannte Profile dürfen nicht auf Christian zurückfallen');
assert(routing.includes("if ($profile === '') return 'christian'"), 'Christian ist ausschließlich bei fehlender Auswahl der Backoffice-Fallback');
assert(drive.includes("gdUserKey($portalUser).'|'.$claimsProfile.'|'.$selectedExpert"), 'Der Statuscache darf die Brückenberechtigung verschiedener Administratoren nicht vermischen');
const driveStatusCache = fs.readFileSync(path.join(root, 'public/intern/drive-status-cache.js'), 'utf8');
assert(driveStatusCache.includes('window.svnetDriveStatus=load') && driveStatusCache.includes('pending') && driveStatusCache.includes('Date.now()+45000'), 'Parallele Portalabfragen teilen sich einen einzigen Drive-Status und behalten ihn 45 Sekunden');
assert(internLayout.includes('drive-status-cache.js?v=20260829-1'), 'Der gemeinsame Drive-Status steht vor allen Portalmodulen bereit');
assert(drive.includes('gdStatusCacheRead') && drive.includes('DATE_SUB(NOW(),INTERVAL 45 SECOND)') && drive.includes('gdStatusCacheWrite'), 'Auch mehrere Portalregister verwenden serverseitig höchstens alle 45 Sekunden eine vollständige Drive-Abfrage');
assert(drive.includes('$indexed=searchCaseFolderIndex') && drive.includes('if($indexed)return gdSortCaseSearchRows'), 'Die Fallsuche antwortet bei einem lokalen Indextreffer ohne eine zusätzliche Drive-Abfrage');
assert(portal.includes('window.svnetDriveStatus?window.svnetDriveStatus()'), 'Die Versicherungsfallseite fasst ihre mehrfachen Drive-Statusabfragen zusammen');
const queue = fs.readFileSync(path.join(root, 'public/intern/api/claimsforce-queue.php'), 'utf8');
assert(queue.includes('claimsforce_import_jobs') && queue.includes("$action==='claim'"), 'Zentrale serverseitige Importwarteschlange fehlt');
assert(queue.includes('heartbeat_at') && queue.includes("$action==='heartbeat'") && queue.includes("$action==='active'"), 'Verwaiste Browserimporte müssen per Heartbeat erkannt werden');
assert(queue.includes("phase='CF-FAIL-STALE'") && queue.includes('cqFailStaleRuns()'), 'Unterbrochene Importe müssen sicher fehlschlagen statt erneut eingeplant zu werden');
assert(queue.includes("phase='CF-FAIL-REPLACED'") && queue.includes("status IN ('queued','running')"), 'Ein neuer manueller Import muss ältere eigene Profilaufträge eindeutig ersetzen');
assert(queue.includes("$action==='schedule'") && queue.includes("new DateTimeZone('Europe/Berlin')"), 'Werktagsautomatik muss serverseitig in Berliner Zeit geplant werden');
assert(queue.includes('$weekday>5||$clock<300||$clock>=1000'), 'Automatik darf nur Montag bis Freitag ab 03:00 Uhr innerhalb des Nachholfensters eingeplant werden');
assert(queue.includes('uq_claims_schedule_key') && queue.includes('INSERT IGNORE INTO claimsforce_import_jobs'), 'Pro Arbeitstag und Profil darf höchstens ein Automatikauftrag entstehen');
assert(queue.includes('foreach(svnetSupportedProfiles()as$profile)') && queue.includes("'CF-AUTO-QUEUED'"), 'Werktagsautomatik reiht alle unterstützten ClaimsForce-Profile ein');
assert(queue.includes('claimsforce_task_status') && queue.includes("$action==='summary'"), 'Die offenen Claims-Aufgaben werden je Profil dauerhaft gespeichert und portalweit bereitgestellt');
assert(queue.includes("'jens'=>'Jens'"), 'Jens besitzt einen eigenständigen Aufgabenstatus');
assert(queue.includes("VALUES('christian',1,NOW(),NULL),('jens',6,NOW(),NULL)"), 'Die vom Benutzer bestätigten Ausgangswerte Christian 1 und Maurer 6 werden einmalig gesetzt');
assert(queue.includes('function cqIsSusanne') && queue.includes('return cqIsSusanne($user)'), 'Die Queue erkennt ausschließlich Susanne als zentrale Backoffice-Station');
assert(queue.includes('$allowed=array_keys(cqVisibleProfiles($user))') && queue.includes('ClaimsForce-Profil und ausgewählter Sachverständiger stimmen nicht überein'), 'Auch die Queue darf nur das aktuell ausgewählte Profil annehmen');
assert(queue.includes("if(!cqIsCentralAgent($user))apiError(403,'Nur Susannes zentrale Backoffice-Importstation darf Aufträge übernehmen.')"), 'Die Queue erzwingt Susannes zentrale Backoffice-Station serverseitig');
assert(queue.includes("is_numeric($result['openTasks']??null)") && queue.includes("(int)$result['openTasks']") && queue.includes('ON DUPLICATE KEY UPDATE open_count=VALUES(open_count)'), 'Ein erfolgreicher Import speichert ausschließlich den Zähler Aufgaben – Alle');
assert(routing.includes("return ['christian', 'holger', 'marc', 'jens']"), 'Backoffice darf alle vier Bearbeiterprofile gezielt auswählen');
assert(queue.includes("$action==='mine'") && queue.includes('WHERE requested_by=:u ORDER BY id DESC LIMIT 20'), 'Eigene Warteschlangenläufe müssen nach einem Portal-Neuladen wiedergefunden werden');
const central = fs.readFileSync(path.join(root, 'public/intern/claimsforce-central.js'), 'utf8');
assert(central.includes('window.svnetDriveStatus?window.svnetDriveStatus()'), 'Die Claims-Zentrale verwendet den gemeinsamen Drive-Status statt eines eigenen Abrufs');
assert(central.includes("action=status&id=") && central.includes("SVNET_CLAIMS_IMPORT_START"), 'Portal kann zentrale Importe starten und verfolgen');
assert(!central.includes("job.profile==='jens'?'christian':job.profile") && central.includes("const target=String(job.profile||'')"), 'Jens darf beim Import nicht auf Christians Portalordner umgeschrieben werden');
assert(central.includes("supportedProfiles=['christian','holger','marc','jens']") && central.includes('selectedProfile()'), 'Zentrale Importstation übernimmt das validierte ausgewählte Profil');
assert(!central.includes('automaticImport') && !central.includes("['christian','jens','marc','holger']"), 'Unsichere browserlokale Mehrprofil-Automatik muss abgeschaltet bleiben');
assert(central.includes("await post('schedule')"), 'Zentrale Station muss den idempotenten serverseitigen Werktagsauftrag abfragen');
assert(central.includes("post('enqueue',{profile})") && !central.includes("profile==='christian'?['christian','jens']:[profile]"), 'Ein manueller Klick darf genau einen Profilimport einreihen');
assert(central.includes("post('active')") && central.includes("post('heartbeat'"), 'Zentrale Station zeigt einen aktiven manuellen Import weiter an');
assert(!central.includes('if(active.job)await launch'), 'Ein vorhandener Serverlauf darf nach einem Portal-Neuladen nicht automatisch neu gestartet werden');
assert(central.includes("action=mine") && central.includes('resumeWatch()'), 'Portal stellt die sichtbare Überwachung bereits eingereihter Importe wieder her');
assert(central.includes("job.status==='done'?'abgeschlossen':'fehlgeschlagen'") && central.includes("slice(0,4)"), 'Portal zeigt auch die letzten terminalen Profilaufträge mit Phase und Ergebnis an');
assert(central.includes('setTimeout(()=>show(text,failed),1000)'), 'Terminaler Importstatus bleibt nach dem einmaligen alten Bridge-Ready-Ereignis sichtbar');
assert(central.includes('data-svnet-claims-jobs') && central.includes("`${job.id}|${job.profile}|${job.status}|${job.phase"), 'Letzte Serverergebnisse sind unabhängig von UI-Listenern geheimnisfrei im DOM prüfbar');
assert(central.includes('data-svnet-claims-results') && central.includes('resultOf'), 'Der Live-Nachweis enthält sichere Fall-, Datei-, Nachrichten- und Terminzahlen');
assert(central.includes('SVNET_CLAIMS_RUNTIME_STATUS') && central.includes('SVNET_CLAIMS_RUNTIME_PING'), 'Portal zeigt den persistenten Browserlauf auch nach einem Worker-Neustart an');
assert(central.includes("minimumBridgeVersion='1.3.17'") && central.includes('versionAtLeast(bridgeVersion,minimumBridgeVersion)'), 'Die zentrale Importstation darf nur mit der gegen Profilverwechslung abgesicherten Brücke laufen');
assert(central.includes("runtime.status==='failed'") && central.includes('Browserlauf wurde abgebrochen'), 'Ein im Browser bereits fehlgeschlagener Lauf muss den noch aktiven Serverauftrag sicher beenden');
assert(central.includes("currentBridgeVersion='1.3.17'") && central.includes('steht als empfohlenes Update bereit'), 'Die aktuelle Brücke muss weiterhin als empfohlenes Update angezeigt werden');
assert(central.includes('context.claims_agent&&!bridge') && central.includes('Diese zentrale Importstation ist nicht bereit'), 'Eine veraltete zentrale Brücke darf keinen weiteren dauerhaft wartenden Importauftrag erzeugen');
assert(central.includes('!d.backoffice&&supportedProfiles.includes') && central.includes('settings?.remove()') && central.includes('download?.remove()'), 'Alle vier Sachverständigen nutzen Susannes zentrale Brücke statt einer eigenen Installation');
assert(central.includes('Dein ClaimsForce-Import wird zentral ausgeführt'), 'Sachverständige erhalten statt einer Installationsaufforderung den Hinweis auf die zentrale Ausführung');
assert(central.includes("new CustomEvent('svnet:claims-summary-update')"), 'Nach einem Claims-Import wird die Kopfzeilenanzeige unmittelbar aktualisiert');
assert(internLayout.includes('id="intern-claims-summary"') && internLayout.includes('Deine offenen Aufgaben bei Claims') && internLayout.includes("claimsforce-queue.php?action=summary"), 'Das Prüfportal zeigt die offenen Claims-Aufträge in orangefarbenen Kopfzeilenkästen');
const optionsHtml = fs.readFileSync(path.join(root, 'browser-extension/claimsforce-bridge/options.html'), 'utf8');
for (const [key, label] of [['christian','Christian Wächter'],['holger','Holger Roth'],['marc','Marc Schütt'],['jens','Jens Maurer']]) assert(optionsHtml.includes(`<option value="${key}">${label}</option>`), `${label} ist in der verschlüsselten Zugangsverwaltung auswählbar`);
assert(!optionsHtml.includes('value="self"') && !optionsHtml.includes('Susanne Wächter'), 'Optionsseite bietet weder Susanne noch ein unbestimmtes eigenes Profil an');
assert(manifest.permissions.includes('nativeMessaging'), 'Lokaler Zugangsdaten-Leser ist für den unbeaufsichtigten Import freigegeben');
assert(manifest.permissions.includes('alarms'), 'Die Brücke darf die werktägliche 03:00-Uhr-Planung zuverlässig wecken');
assert(fs.readFileSync(path.join(root, 'browser-extension/claimsforce-bridge/service-worker.js'), 'utf8').includes('sendNativeMessage'), 'Brücke kann Zugangsdaten aus der freigegebenen lokalen Datei lesen');
assert(fs.readFileSync(path.join(root, 'browser-extension/claimsforce-bridge/service-worker.js'), 'utf8').includes("chrome.runtime.getURL('local-config.json')"), 'Brücke besitzt einen lokalen 127.0.0.1-Fallback für die Zugangsdaten');
assert(fs.readFileSync(path.join(root, 'browser-extension/claimsforce-bridge/service-worker.js'), 'utf8').includes('sleep(800).then(() => null)'), 'Ein hängender nativer Zugangsdatenkanal darf den Loopback-Fallback nicht blockieren');
const serviceWorker = fs.readFileSync(path.join(root, 'browser-extension/claimsforce-bridge/service-worker.js'), 'utf8');
assert(serviceWorker.includes("SUPPORTED_PROFILES = ['christian', 'holger', 'marc', 'jens']") && serviceWorker.includes('profileKey(message.profile)'), 'Service Worker verwendet das angeforderte Profil nur nach Whitelist-Prüfung');
assert(!serviceWorker.includes("message.profile || 'self'") && !serviceWorker.includes("row.activeProfile || 'self'"), 'Service Worker darf ein fehlendes oder unbekanntes Profil nicht als self behandeln');
assert(serviceWorker.includes('loadCredentials(profile).catch(() => null), sleep(600)') && serviceWorker.includes('loadPortalCredentials().catch(() => null), sleep(600)'), 'Ein hängender Browser-Tresor darf weder ClaimsForce- noch Portal-Anmeldung blockieren');
assert(serviceWorker.includes('PROFILE_EMAILS') && serviceWorker.includes('credentialMatchesProfile') && serviceWorker.includes('clearCredentials(profile)'), 'Ein unter dem falschen SV-Profil gespeicherter Zugang wird verworfen und nicht zur Anmeldung verwendet');
assert(serviceWorker.includes('CF-CRED-02'), 'Eine verbleibende Profil-Zugang-Abweichung muss vor der ClaimsForce-Anmeldung sichtbar abbrechen');
assert(serviceWorker.includes('GET_CREDENTIAL_DIAGNOSTIC') && serviceWorker.includes('loopback-http-'), 'Zugangsdatenkette liefert geheimnisfreie Laufzeitstufen');
assert(serviceWorker.includes("type: 'FILL_LOGIN'") && serviceWorker.includes('claimsTab(profile, run, credential)'), 'Der Importlauf muss den ClaimsForce-Anmeldehelfer aktiv anstoßen');
assert(serviceWorker.includes('readOpenTasks(tab.id)') && serviceWorker.includes('CF-TASKS-04') && serviceWorker.includes('return { claims: claims.length, openTasks'), 'Das Importergebnis übergibt ausschließlich den Zähler Aufgaben – Alle an das Portal');
assert(serviceWorker.includes("type: 'PORTAL_APPOINTMENT', folderId, appointment, profile"), 'Der Service Worker bindet jeden Termin an das aktuell importierte ClaimsForce-Profil');
assert(serviceWorker.includes("if (!['christian', 'jens'].includes(profile))"), 'Christian und das in Christians Kalender umgeleitete Jens-Profil erzeugen keine automatischen Termine');
assert(manifest.permissions.includes('browsingData') && serviceWorker.includes('chrome.browsingData.remove') && serviceWorker.includes('CLAIMS_ORIGINS'), 'Beim Profilwechsel werden ausschließlich ClaimsForce- und Auth0-Sitzungsdaten vollständig entfernt');
assert(serviceWorker.includes('await resetClaimsSession(run)') && serviceWorker.includes('chrome.tabs.remove(tab.id)') && !serviceWorker.includes('bestehende Sitzung gebunden'), 'Vor jeder namentlichen Anmeldung müssen alle alten ClaimsForce-Seiten samt flüchtigem Sitzungskontext geschlossen werden');
assert(serviceWorker.includes('tokenEmail') && serviceWorker.includes('CF-AUTH-03'), 'Eine im Sitzungstoken erkennbare fremde ClaimsForce-Identität wird vor dem Einlesen gesperrt');
assert(serviceWorker.includes("PLANNING_URL = 'https://web.claimsforce.com/planning?bucket=WITH_FUTURE_APPOINTMENT#with-future-appointment'") && serviceWorker.includes('chrome.tabs.update(tabId, { url: PLANNING_URL })'), 'Nach erfolgreicher Anmeldung wird die von ClaimsForce benötigte Termin-Planungsseite deterministisch geöffnet');
assert(manifest.host_permissions.includes('http://127.0.0.1:47831/*'), 'Lokaler Zugangsdaten-Helfer ist auf den festgelegten Loopback-Port begrenzt');
assert(serviceWorker.includes('accepted: true') && serviceWorker.includes('claims-import-keepalive'), 'Ein Import darf nicht mehr an einem einzigen lang laufenden Erweiterungs-Nachrichtenkanal hängen');
assert(serviceWorker.includes('bridgeActivatedVersion') && serviceWorker.includes('CF-EXTENSION-UPDATE') && serviceWorker.includes('chrome.tabs.query({ url: PORTAL_TAB_PATTERN })'), 'Eine automatisch aktualisierte Brücke lädt offene Portalseiten kontrolliert neu und kennzeichnet einen unterbrochenen Import');
assert(serviceWorker.includes('CF-CASE-06') && serviceWorker.includes('completedCases'), 'Jeder vollständig verarbeitete Portal-Fall wird ohne Geheimnisse als Laufzeitbeleg gemeldet');
assert(serviceWorker.includes('CF-CASE-FETCH') && serviceWorker.includes('controller.abort()') && serviceWorker.includes('innerhalb von 120 Sekunden'), 'Externe Fall- und Portalabrufe besitzen sichtbare Laufphasen und ausreichend lange feste Zeitgrenzen');
assert(serviceWorker.includes("DAILY_IMPORT_ALARM = 'svnet-claimsforce-daily-0300'") && serviceWorker.includes('nextWeekdayImportAt') && serviceWorker.includes('setHours(3, 0, 0, 0)'), 'Die Brücke plant jeden Werktag einen lokalen 03:00-Uhr-Weckruf');
assert(serviceWorker.includes('chrome.runtime.onStartup') && serviceWorker.includes('catchUpMorningImport') && serviceWorker.includes('clock >= 300 && clock < 1000'), 'Ein späterer Browserstart holt die Morgenplanung innerhalb des sicheren Zeitfensters nach');
assert(serviceWorker.includes('await chrome.tabs.reload(tab.id)') && serviceWorker.includes("recovery: 'reload'"), 'Eine bestehende ClaimsForce-Sitzung wird zur erneuten Token-Übernahme kontrolliert neu geladen');
assert(serviceWorker.includes("recovery: 'login'") && serviceWorker.includes("url: 'https://web.claimsforce.com/login'"), 'Eine abgelaufene ClaimsForce-Sitzung wird automatisch zur Anmeldung geführt');
assert(serviceWorker.includes('claimsforce_sync_signature') && serviceWorker.includes('CF-CASE-SKIP'), 'Unveränderte bereits importierte Fälle werden ohne erneute Dateiübertragung übersprungen');
assert(serviceWorker.includes('claimsforce_file_versions') && serviceWorker.includes('claimsforce_message_versions'), 'Geänderte Fälle übertragen nur neue oder geänderte Dateien und Nachrichten');
assert(serviceWorker.includes('await saveCredentials(profile, local)'), 'Vom lokalen Zugangsdaten-Helfer gelesene Zugänge werden anschließend verschlüsselt im Browser behalten');
assert(serviceWorker.includes('return await response.json()') && serviceWorker.includes('fileBuffer = await response.arrayBuffer()') && serviceWorker.includes('CF-CASE-UPSERT'), 'Zeitgrenzen umfassen Antwortkörper und der Portal-Speicherschritt ist separat sichtbar');
assert(!serviceWorker.includes("startImport({ tab: { id: Number(saved.portalTabId) } }, saved)"), 'Ein neu geweckter MV3-Worker darf einen gespeicherten Lauf nicht automatisch neu starten');
assert(serviceWorker.includes("status.operation?.status === 'missing'"), 'Eine verlorene Portaloperation muss sofort sichtbar fehlschlagen statt bis zum Zeitlimit zu hängen');
assert(serviceWorker.includes('portalOperation') && serviceWorker.includes('PORTAL_OPERATION_STATUS') && serviceWorker.includes('CF-CASE-FILES'), 'Worker wartet über kurze Statusabfragen auf Portal-Fallanlage und fährt danach mit Anlagen fort');
assert(serviceWorker.includes('for (const appointment of Array.isArray(appointments)') && serviceWorker.includes('appointmentsDone'), 'Holger- und Marc-Termine werden weiterhin automatisch übernommen');
assert(!serviceWorker.includes('console.log') && !serviceWorker.includes('console.error'), 'ClaimsForce-Laufzeit darf keine Zugangsdaten oder Tokens protokollieren');
assert(vault.includes("AES-GCM"), 'Kennwörter werden verschlüsselt gespeichert');

console.log('ClaimsForce-Import: Zuordnung, Bestandsschutz, Zugangstresor und Browser-Brücke geprüft.');
