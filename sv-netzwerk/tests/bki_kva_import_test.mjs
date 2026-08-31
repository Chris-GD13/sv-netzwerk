import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const page = fs.readFileSync(path.join(root, 'src/pages/intern/kalkulation/index.astro'), 'utf8');
const api = fs.readFileSync(path.join(root, 'public/intern/api/bki-calculator.php'), 'utf8');
const noteHelper = fs.readFileSync(path.join(root, 'public/intern/calculation-note-helper.js'), 'utf8');
const settlementPdf = fs.readFileSync(path.join(root, 'public/intern/api/bki-settlement-pdf.php'), 'utf8');

assert(page.includes('id="bk-kva-file"') && page.includes('Datei vom Gerät auswählen'), 'Die normale Dateiauswahl muss auf Mobilgeräten ausdrücklich verfügbar sein.');
assert(!/id="bk-kva-file"[^>]*capture=/u.test(page), 'Die normale Dateiauswahl darf nicht die Kamera erzwingen.');
assert(/id="bk-kva-camera"[^>]*capture="environment"/u.test(page), 'Für das direkte Fotografieren muss es einen getrennten Kameraweg geben.');
assert(page.includes("file.files?.[0]||camera.files?.[0]"), 'Datei- und Kameraauswahl müssen an denselben KVA-Import übergeben werden.');
assert(page.includes('Alle Positionen 1:1 in die Kalkulation übernehmen'), 'Alle erkannten KVA-Positionen müssen ohne BKI-Treffer 1:1 übernommen werden können.');
assert(page.includes('offeredUnit||(offeredTotal/quantity)||0'), 'Der 1:1-Import muss den angebotenen Einheitspreis oder den aus der Positionssumme abgeleiteten Preis verwenden.');
assert(page.includes("positions.forEach((row,index)=>"), 'Der 1:1-Import darf keine KVA-Position wegen eines fehlenden BKI-Treffers auslassen.');
assert(page.includes("line.position_code=String(++position)"), 'Kalkulationspositionen müssen automatisch fortlaufend neu nummeriert werden.');
assert(page.includes("bridge.addSection=value=>") && page.includes("addSection?.(groupTitle())"), 'Vor importierten KVA-Positionen muss eine eigene Firmen- oder Tätigkeitsüberschrift eingefügt werden.');
assert(page.includes("const groupTitle=()=>String($('bk-kva-title').textContent||'KVA-Positionen').trim()"), 'Die Gruppenüberschrift muss Aussteller und KVA-Nummer vollständig übernehmen.');
assert(page.includes("line.source_position_code=line.source_position_code||line.position_code||''"), 'Die ursprüngliche KVA-Positionsnummer muss als Herkunftsinformation erhalten bleiben.');
assert(page.includes("addSection?.('Eigene Kalkulation nach BKI')") && page.includes('quickSectionOpen=false'), 'Schnellkalkulationspositionen müssen in einem eigenen BKI-Abschnitt beginnen.');
assert(page.includes("isLegacyQuick=/^(INDEX|BAUM-|ASBEST)/i") && page.includes("grouped.push({type:'section',description:'Eigene Kalkulation nach BKI'"), 'Bestehende ungruppierte Schnellkalkulationspositionen müssen beim Laden nachträglich einen eigenen Abschnitt erhalten.');
assert(page.includes("[/rollladenpanzer pvc/,{material:72,hours:1.75,labor:72}]") && page.includes("[/rollladenpanzer aluminium/,{material:115,hours:1.75,labor:72}]"), 'Die Rollladenpanzer müssen den KVA-belegten höheren Montageaufwand berücksichtigen.');
assert(page.includes("[/attika|blechverwahrung|anschlussblech/,216]") && page.includes("kvaFloor=/attika|blechverwahrung|anschlussblech/.test(label)?216:0"), 'Individuelle Blechverwahrungen dürfen den KVA-belegten Mindestansatz nicht unterschreiten.');
assert(page.includes("{c:'Insektenschutz',l:'Insektenschutz-Spannrahmen'") && page.includes("l:'Insektenschutz-Drehtür'") && page.includes("l:'Insektenschutz-Pendeltür'") && page.includes("l:'Insektenschutz-Schiebetür'") && page.includes("l:'Insektenschutz-Lichtschachtabdeckung'"), 'Die fünf üblichen Insektenschutzarten müssen in der Schnellkalkulation vorhanden sein.');
assert(page.includes("const area=Math.max(1,height*width),ep=number(p.base)*area"), 'Insektenschutzpreise müssen aus Höhe mal Breite mit mindestens einem Quadratmeter Grundpreis berechnet werden.');
assert(page.includes("description:`${p.l} – maßgefertigt, geliefert und montiert`") && !page.includes("description:`${p.l} – ${height}"), 'Die verwendeten Insektenschutzmaße dürfen nicht in der Kalkulationsposition erscheinen.');

assert(api.includes('function bkDriveBelongsToCase('), 'Die Fallzuordnung muss über den vollständigen Drive-Unterordnerpfad geprüft werden.');
assert(api.includes("if(hash_equals($folderId,$parent))return true"), 'Ein KVA in einem Unterordner des aktiven Falls muss zugelassen werden.');
assert(api.includes("if(!bkDriveBelongsToCase($fileId,$folder))"), 'Die rekursive Fallprüfung muss beim KVA-Import verwendet werden.');

assert(api.includes("'type'=>'input_image'"), 'Fotos müssen als Bild und nicht als Dokument an die Auswertung übergeben werden.');
assert(api.includes("'type'=>'input_file'"), 'PDF- und Word-KVA müssen weiterhin als Dokument ausgewertet werden.');
assert(api.includes("['image/jpeg','image/png','image/webp','image/gif']"), 'Die tatsächlich unterstützten Bildformate müssen eindeutig begrenzt sein.');
assert(api.includes('KI-API-Guthaben aufgebraucht') && api.includes('Datei und Fallzuordnung sind in Ordnung'), 'Ein erschöpftes API-Guthaben darf nicht mehr als Datei- oder Fallfehler erscheinen.');
assert(api.includes("($item['type']??'')==='section'") && api.includes('class="section"'), 'KVA-Überschriften müssen im Fallaktenarchiv als preislose Abschnitte erscheinen.');
assert(settlementPdf.includes("($line['type']??'')==='section'") && settlementPdf.includes('$position++'), 'KVA-Überschriften dürfen in der Abgeltungs-PDF nicht als berechnete Position gezählt werden.');
assert(settlementPdf.includes("textLine($ops,45,793,'SV',26,true,$orange)") && settlementPdf.includes("'sv-netzwerk.eu'"), 'Die Abgeltungs-PDF muss das farbige SV-Netzwerk-Logo tragen.');
assert(settlementPdf.includes('drawPageHeader($ops,count($pages)>0)') && settlementPdf.includes("'Seite '.($index+1).' von '.$pageCount"), 'Mehrseitige PDFs müssen einen wiederholten Kopf und Seitenzahlen erhalten.');
assert(settlementPdf.includes("$basisText=numberValue($quantity)") && settlementPdf.includes("'Betrag brutto'") && settlementPdf.includes("'Regulierung'"), 'Menge, Einheit, Preis, Bruttobetrag und Regulierung müssen übersichtlich getrennt werden.');
assert(page.includes("card.querySelector('.bk-collapse-toggle')?.addEventListener('click'") && page.includes("data.calculations||data.items||[]"), 'Gespeicherte Kalkulationen müssen beim Aufklappen zuverlässig neu geladen werden.');

assert(noteHelper.includes("if(line.type==='section')return;"), 'Abschnittsüberschriften dürfen nicht in die Abgeltungsberechnung eingehen.');
assert(!noteHelper.includes("if(event.target.matches?.('input[data-k]'))updateSettlementNote()"), 'Positionsänderungen dürfen den Mustertext nicht automatisch verändern.');
assert(!noteHelper.includes("if(getLines().length)updateSettlementNote()"), 'Laden, Bearbeiten und Umsatzsteueränderungen dürfen den Mustertext nicht automatisch verändern.');
assert(noteHelper.includes("button.addEventListener('click',()=>insertTemplate(button))"), 'Der Mustertext muss weiterhin ausdrücklich über seinen Button eingefügt werden.');

console.log('KVA-Import: Dateiauswahl, Gruppierung, fortlaufende Nummerierung und expliziter Mustertext abgesichert.');
