import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const core = await readFile(new URL('../public/intern/api/gf-ai-generate-core.php', import.meta.url), 'utf8');
const runtime = await readFile(new URL('../public/intern/api/gf-ai-generate.php', import.meta.url), 'utf8');
const portal = await readFile(new URL('../src/pages/intern/versicherungsfaelle/index.astro', import.meta.url), 'utf8');

assert.match(core, /offizielles\\s\+SV-Schadenprotokoll\\s\+Privat/);
assert.match(core, /offizielles\\s\+SV-Schadenprotokoll\\s\+Gewerbe/);
assert.doesNotMatch(core, /\(gewerbe\|betrieb\|firma\|geschäft\)/, 'metadata key sanierer_firma must not select the Gewerbe template');
assert.match(core, /function gfDocxPopulateProtocol\(/);
assert.match(core, /gfDocxSetTableCell\(\$dom,\$xp,1,1,1,\$fields\['schaden_nr'\]\)/);
assert.match(core, /gfDocxSetTableCell\(\$dom,\$xp,1,1,3,\$fields\['versicherungsnummer'\]\)/);
assert.match(core, /gfDocxSetTableCell\(\$dom,\$xp,2,4,0,\$fields\['regulierer'\],1\)/);
assert.match(core, /\$isGewerbe=str_contains\(gfNorm\(\$templateText\),'gesellschaftsform'\)/);
assert.match(core, /function gfProtocolValidateDocx\(/);
assert.match(core, /Kopffeld nicht an der vorgesehenen Position/);
assert.doesNotMatch(core, /Reserve wird in Zusammenfassung oder offenen Punkten erwähnt/, 'internal summaries must not block a Word document when the reserve field itself is valid');
assert.match(runtime, /\$protocolValidationReplacement/);
assert.match(runtime, /Schadenprotokoll-QS konnte nicht angebunden werden/);
assert.match(runtime, /"form_fields":\{"meldetag":"","gespraechspartner":"","schadenhergang":""/);
const protocolPromptIndex = runtime.indexOf("if($key==='schadenprotokoll')$responseRule=");
const calculationPromptIndex = runtime.indexOf("if($key==='kalkulation')$responseRule=");
const bkiTernaryIndex = runtime.indexOf(";$bkiRule=$bkiRequested?", calculationPromptIndex);
assert.ok(protocolPromptIndex >= 0 && protocolPromptIndex < calculationPromptIndex, 'protocol prompt must be inserted before the calculation prompt');
assert.ok(calculationPromptIndex < bkiTernaryIndex, 'all response rules must be complete before the existing BKI ternary continues');
assert.match(portal, /queue=\[primary,\.\.\.additional,protocol\]\.filter\(Boolean\)/);
assert.doesNotMatch(portal, /navigator\.clipboard\.writeText\(prompt\)/);

console.log('Schadenprotokoll-Vorlagenwahl, Kopfpositionen, Privat-/Gewerbe-Zuordnung und Portalauftrag: OK');
