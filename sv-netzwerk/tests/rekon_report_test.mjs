import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const portal = fs.readFileSync(path.join(root, 'src/pages/intern/versicherungsfaelle/index.astro'), 'utf8');
const generator = fs.readFileSync(path.join(root, 'public/intern/api/gf-ai-generate.php'), 'utf8');
const core = fs.readFileSync(path.join(root, 'public/intern/api/gf-ai-generate-core.php'), 'utf8');
const transientStatus = fs.readFileSync(path.join(root, 'public/intern/transient-calculation-status.js'), 'utf8');

assert(portal.includes('value="rekon_schaden"> Rekon-Schaden <small>(ohne Bilder)</small>'), 'Rekon-Schaden muss als dritter, exklusiver Berichtstyp auswählbar sein');
assert(portal.includes("rekon_schaden:'Rekon-Schaden'"), 'Der Laufstatus muss den verständlichen Rekon-Namen anzeigen');
assert(portal.includes('<option value="rekon_schaden">Rekon-Schaden</option>'), 'Auch Plaud-Zusammenfassungen müssen Rekon-Schaden gezielt auswählen können');

const headings = [
  'Basisdaten Erfassung',
  'Schadenhergang, Plausibilität und Ursache',
  'Bestimmungswidriger Leitungswasseraustritt',
  'Ersatzpflicht',
  'Schadenhöhe und Umfang',
  'Prüfung Versicherungssumme (überschlägig)',
  'Regress',
  'Mehrfachversicherung',
  'Bankverbindung / Zahlweise',
  'Weitere Vorgehensweise',
];
let previous = -1;
for (const heading of headings) {
  const position = generator.indexOf(`'${heading}' => [`);
  assert(position > previous, `Rekon-Abschnitt fehlt oder steht falsch: ${heading}`);
  previous = position;
}

for (const field of [
  'Datum der Besichtigung',
  'Teilnehmer Ortstermin',
  'Feststellung des Außenregulierers vor Ort',
  'Weitere Angaben zum Wasseraustritt (Etage, Name, Räume)',
  'Reserveempfehlung',
  'Wurde der schadenursächliche Gegenstand gesichert',
  'Keine Mehrfachversicherung möglich weil',
  'Wir empfehlen',
]) assert(generator.includes(`'${field}'`), `Rekon-Feld fehlt: ${field}`);

assert(generator.includes("'rekon_schaden'=>'Rekon-Schadenbericht'"), 'Server muss einen eigenen Rekon-Dokumenttyp führen');
assert(generator.includes("'erstbericht_sv_gf','rekon_schaden','schadenprotokoll'"), 'Rekon-Schaden muss serverseitig freigegeben sein');
assert(generator.includes("rekon_schaden')return gfRekonValidateResult"), 'Rekon-Ausgaben benötigen eine eigene Reihenfolge-QS');
assert(generator.includes("Rekon-Schadenbericht')return gfRekonDocumentHtml"), 'Rekon benötigt eine eigene Word-Ausgabe');
assert(generator.includes('Ausgabe ohne Bilder und Dokumentanhänge.'), 'Die Word-Ausgabe muss den bildlosen Rekon-Modus eindeutig dokumentieren');
assert(!generator.slice(generator.indexOf('function gfRekonDocumentHtml'), generator.indexOf('// Zwei getrennte Erstbericht-Typen')).includes('<img'), 'Der Rekon-Word-Renderer darf keine Bilder einbetten');
assert.equal((core.match(/function gfEngelValidate\(/g) || []).length, 1, 'Die Rekon-QS benötigt genau einen eindeutigen Laufzeit-Anker');
assert.equal((core.match(/function gfDocumentHtml\(/g) || []).length, 1, 'Die Rekon-Word-Ausgabe benötigt genau einen eindeutigen Renderer-Anker');
assert(core.includes("$allowed=['dokumentenindex','rechnungsregister','erstbericht','schadenprotokoll'"), 'Die serverseitige Dokumentfreigabe muss eindeutig erweiterbar bleiben');
assert(generator.includes("$rekonOnly=count($outputs)===1"), 'Rekon muss eine eigene, kostenarme Quellenroute verwenden');
assert(generator.includes("!str_starts_with(mb_strtolower((string)($file['mimeType']??'')"), 'Rekon muss Bilddateien vor dem KI-Upload ausschließen');
assert(generator.includes('$knowledgeFiles=$rekonOnly?[]:gfKnowledgeFiles()'), 'Rekon darf die allgemeine Regelwerksammlung nicht hochladen');
assert(generator.includes("$caseFilesNeedle = '$knowledgeFiles=$rekonOnly?[]:gfKnowledgeFiles();'"), 'Die Reserve-Anbindung muss den bereits eingesetzten Rekon-Quellenanker verwenden');
assert(generator.includes("$key===\\'rekon_schaden\\'?12000:null"), 'Das Rekon-Ausgabebudget muss begrenzt sein');
assert(!transientStatus.includes('ChatGPT arbeitet'), 'Die fehlerhafte unbestimmte Hängeanzeige darf nicht mehr verwendet werden');
assert(transientStatus.includes('Zum Kostenschutz erfolgt keine weitere automatische Wiederholung'), 'Die Wiederaufnahme muss gegen Mehrfachaufrufe geschützt sein');
assert(transientStatus.includes("action:'status'"), 'Die Wiederaufnahme muss den tatsächlichen Jobstatus weiter abfragen');
assert(transientStatus.includes('recoverOnce(false)'), 'Ein unterbrochener Auftrag muss auch nach einem Seitenneuladen wiedergefunden werden');

console.log('Rekon-Schadenbericht: Auswahl, Formularreihenfolge, kostengeschützte Wiederaufnahme und bildlose Word-Ausgabe geprüft.');
