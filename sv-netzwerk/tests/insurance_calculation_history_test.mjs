import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const page = fs.readFileSync(path.join(root, 'src/pages/intern/kalkulation/versicherungsschaeden.astro'), 'utf8');
const api = fs.readFileSync(path.join(root, 'public/intern/api/bki-calculator.php'), 'utf8');

assert(page.includes('id="vs-history"') && page.includes('Gespeicherte Kalkulationen'), 'Die Versicherungsschaden-Kalkulation braucht eine sichtbare Speicherübersicht.');
assert(page.includes('data-view-calc') && page.includes('>Öffnen</button>'), 'Gespeicherte Kalkulationen müssen lesbar geöffnet werden können.');
assert(page.includes('data-edit-calc') && page.includes('>Weiter bearbeiten</button>'), 'Gespeicherte Kalkulationen müssen zur Weiterbearbeitung geladen werden können.');
assert(page.includes('data-delete-calc') && page.includes('>Löschen</button>'), 'Gespeicherte Kalkulationen müssen aus der Übersicht gelöscht werden können.');
assert(page.includes("lines=(item.items||[]).map") && page.includes("$('vs-vat').value=item.vat_rate??19") && page.includes("$('vs-note').value=item.note||''"), 'Weiter bearbeiten muss Positionen, Umsatzsteuer und Hinweise wiederherstellen.');
assert(page.includes('await loadSaved()') && page.includes('loadSaved()})();'), 'Die Übersicht muss nach dem Speichern und beim Seitenstart aktualisiert werden.');
assert(api.includes('function bkCalculationForUser') && api.includes('requireCaseFolderAccess($folder,$user)'), 'Gespeicherte Fallkalkulationen müssen über die Fallberechtigung geöffnet werden.');
assert(api.includes('(folder_id IS NULL OR folder_id="") AND created_by=:u'), 'Freie Kalkulationen dürfen nicht benutzerübergreifend aufgelistet werden.');
assert(api.includes("'item_count'=>is_array($savedItems)?count($savedItems):0"), 'Die Übersicht muss die Zahl der gespeicherten Positionen liefern.');

console.log('insurance_calculation_history_test: ok');
