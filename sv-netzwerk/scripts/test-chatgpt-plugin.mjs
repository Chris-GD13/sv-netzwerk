import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const repo = resolve(root, '..');
const read = (path) => readFile(resolve(repo, path), 'utf8');

const manifest = JSON.parse(await read('plugins/sv-netzwerk-schadenberichte/.codex-plugin/plugin.json'));
const mcp = JSON.parse(await read('plugins/sv-netzwerk-schadenberichte/.mcp.json'));
assert.equal(manifest.interface.displayName, 'SV-Netzwerk Schadenberichte');
assert.equal(mcp.mcpServers['sv-netzwerk'].url, 'https://www.sv-netzwerk.eu/intern/mcp/');

const normal = await read('plugins/sv-netzwerk-schadenberichte/skills/schadenbericht/references/normaler-schadenbericht.md');
const ordered = [
  '**Risiko**',
  '**Schaden**',
  '**Ersatzpflicht**',
  '**Regress**',
  '**Obliegenheitsverletzungen**',
  '**Doppelversicherung**',
  '**Polizei**',
  '**Handlungsempfehlungen**',
];
let last = -1;
for (const heading of ordered) {
  const index = normal.indexOf(heading);
  assert.ok(index > last, `Berichtsreihenfolge verletzt bei ${heading}`);
  last = index;
}
assert.match(normal, /zuerst Versicherungsverhältnisse, danach Risikoverhältnisse/);
assert.match(normal, /zuerst Schadenhergang und Schadenursache/);
assert.match(normal, /keine freistehenden Kapitel `Schadenhöhe`, `Kalkulation`, `Abgeltung` oder `Reserve`/);

const svGf = await read('plugins/sv-netzwerk-schadenberichte/skills/schadenbericht/references/sv-gf-schaden.md');
assert.match(svGf, /normale Schadenbericht-Reihenfolge nicht verwenden/);
assert.match(svGf, /Engel-\/Originalvorlage/);

const onboardingApi = await read('sv-netzwerk/public/intern/api/chatgpt-onboarding.php');
assert.match(onboardingApi, /ws@sv-schuett\.eu/);
assert.match(onboardingApi, /'shared_account'=>true/);
assert.match(onboardingApi, /'account_owner'=>'Christian Wächter'/);
assert.match(onboardingApi, /SV_CHATGPT_PLUGIN_INSTALL_URL/);
assert.match(onboardingApi, /SV_CHATGPT_PLUGIN_LAUNCH_URL/);
assert.match(onboardingApi, /open-app\?target=plugin&plugin_id=Plugin_636243ee5d9481919ece3f9a5af9adc3/);
assert.match(onboardingApi, /'direct_install'=>true/);

const onboardingJs = await read('sv-netzwerk/public/intern/chatgpt-onboarding.js');
assert.doesNotMatch(onboardingJs, /\.zip|\.exe/i);
assert.match(onboardingJs, /Verbindung prüfen/);
assert.match(onboardingJs, /open-app\?target=plugin&plugin_id=Plugin_636243ee5d9481919ece3f9a5af9adc3/);
assert.match(onboardingJs, /Plugin in ChatGPT öffnen/);
assert.match(onboardingJs, /Noch keine persönliche Portalverbindung erkannt/);

const oauth = await read('sv-netzwerk/public/intern/oauth/token.php');
assert.match(oauth, /grant === 'refresh_token'/);
assert.match(oauth, /oauth_refresh_tokens/);

const mcpServer = await read('sv-netzwerk/public/intern/mcp/index.php');
assert.match(mcpServer, /'name'=>'read_case_file'/);
assert.match(mcpServer, /requireCaseFolderAccess\(\$folder,\$user\)/);
assert.match(mcpServer, /mcpDriveFindCaseFile/);

const portal = await read('sv-netzwerk/src/pages/intern/versicherungsfaelle/index.astro');
assert.match(portal, /persönlichen ChatGPT-\/Work-Zugang als kopierfertiger Text/);
assert.match(portal, /personalReportKeys=new Set\(\['erstbericht','erstbericht_sv_gf','rekon_schaden','zwischenbericht','schlussbericht','nachtrag_stellungnahme'\]\)/);
assert.match(portal, /navigator\.clipboard\.writeText\(prompt\)/);
assert.match(portal, /setup\.required&&!setup\.installed/);

console.log('ChatGPT-Plugin, persönliche Einrichtung, Susanne-Ausnahme und Berichtsreihenfolge: OK');
