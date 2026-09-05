import fs from 'node:fs';
import path from 'node:path';

const root=path.resolve(import.meta.dirname,'..');
const cockpit=fs.readFileSync(path.join(root,'src/pages/intern/tagescockpit/index.astro'),'utf8');
const layout=fs.readFileSync(path.join(root,'src/layouts/InternalLayout.astro'),'utf8');
const sw=fs.readFileSync(path.join(root,'public/sw.js'),'utf8');
const client=fs.readFileSync(path.join(root,'src/lib/internal/client.ts'),'utf8');
const assert=(condition,message)=>{if(!condition)throw new Error(message)};

assert(layout.includes("'/intern/tagescockpit/'")&&layout.includes("label: 'Tagescockpit'"),'Tagescockpit fehlt als erster eigener Portalbereich');
assert(layout.indexOf("'/intern/tagescockpit/'")<layout.indexOf("'/intern/versicherungsfaelle/'"),'Tagescockpit muss vor den Versicherungsfällen stehen');
assert(client.includes("redirectTo('/intern/tagescockpit/')"),'Nach erfolgreicher Anmeldung muss das Tagescockpit starten');
assert(!client.includes('INACTIVITY_MS')&&!client.includes("reason=inactivity"),'Das Portal darf angemeldete Benutzer nicht wegen Inaktivität automatisch abmelden');
assert(client.includes("querySelector<HTMLButtonElement>('#header-logout')")&&client.includes('await apiLogout()'),'Die Abmeldung muss weiterhin bewusst über den manuellen Abmeldeknopf erfolgen');
assert(layout.includes('id="intern-logout"')&&layout.includes("auth.php?action=logout"),'Auch die neue Portalnavigation muss den manuellen Abmeldeknopf behalten');
assert(cockpit.includes('google-drive-sync.php?action=status')&&cockpit.includes('google-drive-sync.php?action=recent_cases'),'Cockpit muss Drive-Status und letzte Fälle lesen');
assert(cockpit.includes('outlook-case-calendar.php?action=list'),'Cockpit muss die bestehende Kalenderansicht lesen');
assert(cockpit.includes('claimsforce-queue.php?action=summary')&&cockpit.includes('claimsforce-queue.php?action=mine'),'Cockpit muss Aufgabenstand und Warteschlange getrennt anzeigen');
assert(cockpit.includes('class="dc-summary-card" href="#dc-cases-section"')&&cockpit.includes('href="#dc-today-section"'),'Fall-, Hinweis- und Terminkennzahlen müssen zu ihren sichtbaren Detailbereichen führen');
assert(cockpit.includes('href="https://web.claimsforce.com/tasks"')&&cockpit.includes('In ClaimsForce bearbeiten'),'Claims-Aufgaben müssen zur tatsächlich bearbeitbaren ClaimsForce-Aufgabenansicht führen');
assert(cockpit.includes('id="dc-phone-number"')&&cockpit.includes('data-dc-phone-action="answer"')&&cockpit.includes('data-dc-phone-action="drop"'),'Das Tagescockpit muss Wählen, Annehmen und Auflegen kompakt bereitstellen');
assert(cockpit.includes("location.href=`${mobile()?'tel':'callto'}:${clean}`")&&cockpit.includes('svnet-xtelsio://${action}'),'Die kompakte Telefonsteuerung muss dieselben geprüften Protokolle wie die Telefonzentrale verwenden');
assert(cockpit.includes('phoneContacts(meta)')&&cockpit.includes('Kein aktiver Schadenfall.'),'Kontakte des aktiven Schadenfalls müssen im Telefonbereich erscheinen');
assert(cockpit.includes('revenue-summary-v2.php?action=access'),'Cockpit muss den rollenbezogenen Abrechnungsstand lesen');
assert(cockpit.includes('id="dc-quick-settlement"')&&cockpit.includes("hidden=data.show_settlement_link!==true"),'Abrechnungen dürfen im Cockpit nur mit bestätigter Berechtigung erscheinen');
assert(!cockpit.includes("method:'POST'")&&!cockpit.includes('method: "POST"'),'Cockpit darf beim Laden keine schreibende Aktion ausführen');
assert(cockpit.includes("sessionStorage.setItem('svnet-case'")&&cockpit.includes("location.assign('/intern/versicherungsfaelle/')"),'Fallübergabe muss in den bestehenden Fallbereich führen');
assert(cockpit.includes('data-system="drive"')&&cockpit.includes('data-system="outlook"')&&cockpit.includes('data-system="claims"')&&cockpit.includes('data-system="revenue"'),'Alle vier Systemampeln müssen vorhanden sein');
assert(layout.includes("/sw.js?v=20260904-8")&&sw.includes("CACHE_VERSION = '20260904-8'"),'Portalcache muss für das Cockpit eindeutig erneuert werden');

console.log('daily_cockpit_test: ok');
