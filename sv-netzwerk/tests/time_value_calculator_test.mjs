import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { calculateTimeValue, buildTimeValueText } from '../public/intern/time-value.js';

const page = await readFile(new URL('../src/pages/intern/zeitwertberechnung/index.astro', import.meta.url), 'utf8');
const layout = await readFile(new URL('../src/layouts/InternalLayout.astro', import.meta.url), 'utf8');
const calculation = await readFile(new URL('../src/pages/intern/kalkulation/index.astro', import.meta.url), 'utf8');
const catalog = JSON.parse(await readFile(new URL('../src/data/bte-life-expectancy.json', import.meta.url), 'utf8'));
const paritaetCatalog = JSON.parse(await readFile(new URL('../src/data/paritaet-life-expectancy.json', import.meta.url), 'utf8'));

const roof = calculateTimeValue({ newValueNet: 10000, age: 30, lifetime: 50, correctionFactor: 1.2, vatRate: 19 });
assert.equal(roof.valid, true);
assert.equal(roof.depreciationRate, 0.72);
assert.equal(Math.round(roof.timeValueNet), 2800);

const community = calculateTimeValue({ newValueNet: 1000, age: 3, lifetime: 60, limitedRemainingLife: 3, correctionFactor: 1 });
assert.equal(community.effectiveLifetime, 6);
assert.equal(community.timeValueRate, 0.5);

const repair = calculateTimeValue({ newValueNet: 1000, age: 30, lifetime: 75, noLifeExtension: true });
assert.equal(repair.timeValueRate, 1);
assert.equal(repair.deductionNet, 0);

assert.match(buildTimeValueText('Dachziegel', roof, { page: 11 }), /Abzug „Neu für Alt“/);
assert.match(buildTimeValueText('Dachziegel', roof, { page: 11 }), /Richtwerte/);
assert(catalog.length >= 240, 'Der vollständige BTE-Katalog muss bereitstehen.');
assert(catalog.some((row) => row.code === '8.2.5' && row.name === 'Dachziegel' && row.mean === 60));
assert(catalog.some((row) => row.code === '4.3.9' && /Fertigparkett/.test(row.name) && row.mean === 40));
assert(paritaetCatalog.length >= 120, 'Die paritätische Lebensdauertabelle muss vollständig bereitstehen.');
assert(paritaetCatalog.some((row) => row.name === 'Furnierparkett' && row.mean === 12));
assert(paritaetCatalog.some((row) => /Duschkabine, Glaswände/.test(row.name) && row.mean === 25));
assert(page.includes('Korrekturfaktor 0,8–1,2') && page.includes('Begrenzende Restlebensdauer') && page.includes('Teilreparatur ohne Verlängerung der Nutzungsdauer'));
assert(page.includes('Paritätische Lebensdauertabelle') && page.includes('Gewerbe, stark beansprucht −50 %'));
assert(page.includes('svnet-time-value-transfer') && calculation.includes('svnet-time-value-transfer'));
assert(layout.includes("label: 'Zeitwertberechnung'") && layout.includes("href: '/intern/zeitwertberechnung/'"));

console.log('Zeitwertberechnung: BTE-Katalog, Formeln, Begründungstext und Kalkulationsübernahme geprüft.');
