import fs from 'node:fs';
import path from 'node:path';

const root=path.resolve(import.meta.dirname,'..');
const page=fs.readFileSync(path.join(root,'src/pages/intern/arbeitszentrale/index.astro'),'utf8');
const api=fs.readFileSync(path.join(root,'public/intern/api/work-center.php'),'utf8');
const layout=fs.readFileSync(path.join(root,'src/layouts/InternalLayout.astro'),'utf8');
const assert=(condition,message)=>{if(!condition)throw new Error(message)};

assert(!layout.includes("label: 'Arbeitszentrale'")&&layout.includes("label: 'Tagescockpit'"),'Tagescockpit und Arbeitszentrale müssen als ein Menübereich erscheinen');
assert(page.includes('currentPath="/intern/tagescockpit/"')&&page.includes('Tagescockpit Ansichten')&&page.includes('Arbeitsorganisation'),'Arbeitsorganisation muss als zweite Ansicht des Tagescockpits erscheinen');
for(const label of ['Globale Fallsuche','Qualitätsprüfung','Vor Ort','Controlling'])assert(page.includes(label),`${label} fehlt`);
assert(!page.includes('data-tab="tasks"')&&page.includes('class="wc-system-strip"'),'Die Wiedervorlagen müssen auf Seite 1 liegen und der Systemstatus auf Seite 2 kompakt erscheinen');
for(const system of ['drive','outlook','claims','revenue'])assert(page.includes(`data-system="${system}"`),`${system} fehlt im kompakten Systemstatus`);
assert(api.includes('requireAuth()')&&api.includes('svnetSelectedProfile'),'API muss Anmeldung und ausgewähltes Profil erzwingen');
assert(api.includes("WHERE profile=:p")&&api.includes("AND profile=:p"),'Wiedervorlagen müssen strikt nach Profil getrennt sein');
assert(api.includes('requireCaseFolderAccess($folder,$caseUser)'),'Fallbezogene Daten müssen die Fallberechtigung prüfen');
assert(page.includes('search_cases&q=')&&page.includes('load_case&id='),'Fallsuche muss vorhandene geschützte Drive-Endpunkte nutzen');
assert(page.includes('upload_case_document'),'Vor-Ort-Dateien müssen den vorhandenen deduplizierenden Upload nutzen');
assert(page.includes("300 € als Selbstbehalt behandeln")&&page.includes('backoffice@meygeneralbau.de'),'Bekannte kritische Berichts- und KVA-Regeln fehlen');
assert(page.includes('Sie verändert und sperrt keine Berichts- oder Versandfunktion'),'Qualitätsprüfung muss additiv und nicht blockierend bleiben');
assert(page.includes('keine Umsatz- oder Leistungsabrechnung'),'Controlling darf nicht als Finanzabrechnung dargestellt werden');
assert(layout.includes("/sw.js?v=20260905-7"),'Neue Arbeitsorganisation muss den Portalcache erneuern');

console.log('work_center_test: ok');
