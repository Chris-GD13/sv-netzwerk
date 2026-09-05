import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const page = readFileSync(new URL('../src/pages/intern/telefon/index.astro', import.meta.url), 'utf8');
const api = readFileSync(new URL('../public/intern/api/phonebook.php', import.meta.url), 'utf8');

assert(page.includes('Telefonbuch pflegen und Kontakte importieren'), 'Telefonbuchpflege fehlt.');
assert(page.includes('ph-setup-compact') && !page.includes('Einmalige Windows-Einrichtung für Annehmen und Auflegen</summary>'), 'Windows-Einrichtung muss platzsparend im Wählbereich stehen.');
assert(page.includes('accept=".csv,.vcf'), 'CSV- und VCF-Import fehlt.');
assert(page.includes("PHONEBOOK_API='/intern/api/phonebook.php'"), 'Telefonbuch-API ist nicht angebunden.');
assert(page.includes("action=save") && page.includes("action=delete") && page.includes("action=import"), 'Pflegeaktionen sind unvollständig.');
assert(page.includes("addToSpeedDial"), 'Übernahme in die persönliche Kurzwahl fehlt.');
assert(page.includes('Promise.allSettled'), 'Telefonbuch- und Fallsuche müssen bei einem Teilfehler getrennt weiterlaufen.');
assert(api.includes('CREATE TABLE IF NOT EXISTS phonebook_contacts'), 'Zentrale Telefonbuchtabelle fehlt.');
assert(api.includes("$action === 'list'") && api.includes("$action === 'save'") && api.includes("$action === 'delete'") && api.includes("$action === 'import'"), 'API-Aktionen sind unvollständig.');
console.log('phonebook_page_test: ok');
