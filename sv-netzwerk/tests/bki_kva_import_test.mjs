import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const page = fs.readFileSync(path.join(root, 'src/pages/intern/kalkulation/index.astro'), 'utf8');
const api = fs.readFileSync(path.join(root, 'public/intern/api/bki-calculator.php'), 'utf8');

assert(page.includes('id="bk-kva-file"') && page.includes('Datei vom Gerät auswählen'), 'Die normale Dateiauswahl muss auf Mobilgeräten ausdrücklich verfügbar sein.');
assert(!/id="bk-kva-file"[^>]*capture=/u.test(page), 'Die normale Dateiauswahl darf nicht die Kamera erzwingen.');
assert(/id="bk-kva-camera"[^>]*capture="environment"/u.test(page), 'Für das direkte Fotografieren muss es einen getrennten Kameraweg geben.');
assert(page.includes("file.files?.[0]||camera.files?.[0]"), 'Datei- und Kameraauswahl müssen an denselben KVA-Import übergeben werden.');

assert(api.includes('function bkDriveBelongsToCase('), 'Die Fallzuordnung muss über den vollständigen Drive-Unterordnerpfad geprüft werden.');
assert(api.includes("if(hash_equals($folderId,$parent))return true"), 'Ein KVA in einem Unterordner des aktiven Falls muss zugelassen werden.');
assert(api.includes("if(!bkDriveBelongsToCase($fileId,$folder))"), 'Die rekursive Fallprüfung muss beim KVA-Import verwendet werden.');

assert(api.includes("'type'=>'input_image'"), 'Fotos müssen als Bild und nicht als Dokument an die Auswertung übergeben werden.');
assert(api.includes("'type'=>'input_file'"), 'PDF- und Word-KVA müssen weiterhin als Dokument ausgewertet werden.');
assert(api.includes("['image/jpeg','image/png','image/webp','image/gif']"), 'Die tatsächlich unterstützten Bildformate müssen eindeutig begrenzt sein.');
assert(api.includes('KI-API-Guthaben aufgebraucht') && api.includes('Datei und Fallzuordnung sind in Ordnung'), 'Ein erschöpftes API-Guthaben darf nicht mehr als Datei- oder Fallfehler erscheinen.');

console.log('KVA-Import: Unterordner, mobile Dateiauswahl und Fotoauswertung abgesichert.');
