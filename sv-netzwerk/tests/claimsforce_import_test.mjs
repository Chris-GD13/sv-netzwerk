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
assert.equal(safeFileName('KVA: Angebot?.pdf'), 'KVA- Angebot-.pdf');

const manifest = JSON.parse(fs.readFileSync(path.join(root, 'browser-extension/claimsforce-bridge/manifest.json'), 'utf8'));
assert.equal(manifest.manifest_version, 3);
assert(manifest.content_scripts.some(entry => entry.matches.includes('https://www.sv-netzwerk.eu/intern/versicherungsfaelle/*')));
assert(manifest.content_scripts.some(entry => entry.matches.includes('https://claimsforce.eu.auth0.com/*')));
assert(manifest.content_scripts.some(entry => entry.matches.includes('https://www.sv-netzwerk.eu/intern/login/*') && entry.js.includes('portal-login-helper.js')), 'Automatische Prüfportal-Anmeldung ist in der Brücke registriert');

const portal = fs.readFileSync(path.join(root, 'src/pages/intern/versicherungsfaelle/index.astro'), 'utf8');
assert(portal.includes('Aufträge aus Claims einlesen'));
assert(portal.includes("context.backoffice?`Import für"), 'Susannes ausgewählter Sachverständiger wird angezeigt');
assert(portal.includes("button.dataset.claimsProfile=context.backoffice?key:(context.claims_profile||'self')"), 'Portal verwendet für Susanne und den angemeldeten Sachverständigen das richtige gespeicherte Profil');
assert(portal.includes('Claims-Zugangsdaten verwalten'));

const bridge = fs.readFileSync(path.join(root, 'browser-extension/claimsforce-bridge/portal-bridge.js'), 'utf8');
assert(bridge.includes('mergeBlank(existing?.meta || {}, message.mapped)'), 'Import ergänzt bestehende Falldaten nur in leeren Feldern');
assert(bridge.includes("action=save_case"), 'Import nutzt den angemeldeten bzw. von Susanne ausgewählten persönlichen Fallordner');
const claimsPageBridge = fs.readFileSync(path.join(root, 'browser-extension/claimsforce-bridge/claims-bridge.js'), 'utf8');
assert(claimsPageBridge.includes('waitForDay') && claimsPageBridge.includes('Date.now() + 5000'), 'Brücke wartet nach dem Öffnen eines Termintags auf verzögert geladene Aufträge');
assert(claimsPageBridge.includes('Montag|Dienstag|Mittwoch|Donnerstag|Freitag|Samstag|Sonntag'), 'Nur echte Termintage und keine Termin-Schaltflächen werden geöffnet');

const vault = fs.readFileSync(path.join(root, 'browser-extension/claimsforce-bridge/vault.js'), 'utf8');
assert(vault.includes('credentials_${String(profile'), 'Zugänge werden getrennt je Sachverständigen-Profil gespeichert');
const options = fs.readFileSync(path.join(root, 'browser-extension/claimsforce-bridge/options.js'), 'utf8');
assert(options.includes("password.value || currentCredentials?.password"), 'Ein bereits gespeichertes Kennwort darf durch erneutes Speichern nicht geleert werden');
assert(options.includes('Kennwort ist verschlüsselt gespeichert'), 'Gespeichertes Kennwort muss ohne Klartext sichtbar bestätigt werden');
assert(options.includes('savePortalCredentials') && options.includes('automatische Prüfportal-Anmeldung'), 'Prüfportal-Zugang kann einmalig verschlüsselt gespeichert werden');
const portalLogin = fs.readFileSync(path.join(root, 'browser-extension/claimsforce-bridge/portal-login-helper.js'), 'utf8');
assert(portalLogin.includes('GET_PORTAL_CREDENTIALS') && portalLogin.includes('requestSubmit'), 'Prüfportal wird nach einem Sitzungsablauf automatisch wieder angemeldet');

const drive = fs.readFileSync(path.join(root, 'public/intern/api/google-drive-sync.php'), 'utf8');
assert(drive.includes("'claims_profile'=>\$claimsProfile"), 'Status liefert das Claims-Profil des angemeldeten Sachverständigen');
assert(drive.includes("==='administrator'"), 'Administratoren können die Claims-Anmeldungen aller Sachverständigen zentral bedienen');
assert(drive.includes("'claims_agent'=>"), 'Administrator wird als zentrale Importstation erkannt');
const queue = fs.readFileSync(path.join(root, 'public/intern/api/claimsforce-queue.php'), 'utf8');
assert(queue.includes('claimsforce_import_jobs') && queue.includes("$action==='claim'"), 'Zentrale serverseitige Importwarteschlange fehlt');
const central = fs.readFileSync(path.join(root, 'public/intern/claimsforce-central.js'), 'utf8');
assert(central.includes("action=status&id=") && central.includes("SVNET_CLAIMS_IMPORT_START"), 'Portal kann zentrale Importe starten und verfolgen');
assert(central.includes("now.getHours()!==3") && central.includes("['christian','marc','holger']"), 'Täglicher 03:00-Uhr-Import plant alle drei Profile ein');
assert(vault.includes("AES-GCM"), 'Kennwörter werden verschlüsselt gespeichert');

console.log('ClaimsForce-Import: Zuordnung, Bestandsschutz, Zugangstresor und Browser-Brücke geprüft.');
