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
assert(cockpit.includes('google-drive-sync.php?action=status')&&cockpit.includes('google-drive-sync.php?action=recent_cases'),'Cockpit muss Drive-Status und letzte Fälle lesen');
assert(cockpit.includes('outlook-case-calendar.php?action=list'),'Cockpit muss die bestehende Kalenderansicht lesen');
assert(cockpit.includes('claimsforce-queue.php?action=summary')&&cockpit.includes('claimsforce-queue.php?action=mine'),'Cockpit muss Aufgabenstand und Warteschlange getrennt anzeigen');
assert(cockpit.includes('revenue-summary-v2.php?action=access'),'Cockpit muss den rollenbezogenen Abrechnungsstand lesen');
assert(cockpit.includes('id="dc-quick-settlement"')&&cockpit.includes("hidden=data.show_settlement_link!==true"),'Abrechnungen dürfen im Cockpit nur mit bestätigter Berechtigung erscheinen');
assert(!cockpit.includes("method:'POST'")&&!cockpit.includes('method: "POST"'),'Cockpit darf beim Laden keine schreibende Aktion ausführen');
assert(cockpit.includes("sessionStorage.setItem('svnet-case'")&&cockpit.includes("location.assign('/intern/versicherungsfaelle/')"),'Fallübergabe muss in den bestehenden Fallbereich führen');
assert(cockpit.includes('data-system="drive"')&&cockpit.includes('data-system="outlook"')&&cockpit.includes('data-system="claims"')&&cockpit.includes('data-system="revenue"'),'Alle vier Systemampeln müssen vorhanden sein');
assert(layout.includes("/sw.js?v=20260904-8")&&sw.includes("CACHE_VERSION = '20260904-8'"),'Portalcache muss für das Cockpit eindeutig erneuert werden');

console.log('daily_cockpit_test: ok');
