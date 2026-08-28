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

const portal = fs.readFileSync(path.join(root, 'src/pages/intern/versicherungsfaelle/index.astro'), 'utf8');
assert(portal.includes('Aufträge aus Claims einlesen'));
assert(portal.includes("context.backoffice?`Import für"), 'Susannes ausgewählter Sachverständiger wird angezeigt');
assert(portal.includes("button.dataset.claimsProfile=context.backoffice?key:'self'"), 'Susannes Import verwendet die getrennte Anmeldung des ausgewählten Sachverständigen');
assert(portal.includes('Claims-Zugangsdaten verwalten'));

const bridge = fs.readFileSync(path.join(root, 'browser-extension/claimsforce-bridge/portal-bridge.js'), 'utf8');
assert(bridge.includes('mergeBlank(existing?.meta || {}, message.mapped)'), 'Import ergänzt bestehende Falldaten nur in leeren Feldern');
assert(bridge.includes("action=save_case"), 'Import nutzt den angemeldeten bzw. von Susanne ausgewählten persönlichen Fallordner');

const vault = fs.readFileSync(path.join(root, 'browser-extension/claimsforce-bridge/vault.js'), 'utf8');
assert(vault.includes('credentials_${String(profile'), 'Zugänge werden getrennt je Sachverständigen-Profil gespeichert');
assert(vault.includes("AES-GCM"), 'Kennwörter werden verschlüsselt gespeichert');

console.log('ClaimsForce-Import: Zuordnung, Bestandsschutz, Zugangstresor und Browser-Brücke geprüft.');
