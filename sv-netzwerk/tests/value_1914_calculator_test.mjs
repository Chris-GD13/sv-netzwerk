import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { buildingRate, calculateBuildingValuation } from '../public/intern/value-1914.js';

const expected = {
  no_basement: {
    flat: [160,160,135,135,135,135,135],
    attic_unfinished: [160,140,135,135,135,135,135],
    attic_finished: [140,130,125,125,125,125,125],
  },
  basement: {
    flat: [190,190,150,150,135,130,130],
    attic_unfinished: [190,165,150,150,130,130,130],
    attic_finished: [165,150,140,135,130,130,130],
  },
};
for (const [basement, roofs] of Object.entries(expected)) {
  for (const [roof, rates] of Object.entries(roofs)) {
    rates.forEach((rate, index) => assert.equal(buildingRate(basement, roof, index + 1), rate, `${basement}/${roof}/${index + 1}`));
  }
}
assert.equal(buildingRate('basement','flat',0),0);
assert.equal(buildingRate('basement','flat',8),0);
const result = calculateBuildingValuation({basement:'basement',roof:'attic_finished',floors:2,area:100,basementArea:10,garages:1,carports:1,underground:1,specialValue:22600,outbuildingValue:11300,factor:22.6,surcharges:['premium_facade','premium_sanitary']});
assert.equal(result.valid, true);
assert.equal(result.rate, 150);
assert.equal(result.surcharge, 11);
assert.equal(result.mainValue1914, 16100);
assert.equal(result.total1914, 19850);
assert.equal(result.currentValue, 448610);
const deduplicated = calculateBuildingValuation({basement:'basement',roof:'attic_finished',floors:2,area:100,factor:22.6,surcharges:['premium_facade','premium_facade']});
assert.equal(deduplicated.surcharge,5);
const page = await readFile(new URL('../src/pages/intern/wert-1914/index.astro', import.meta.url), 'utf8');
for (const token of ['Ermittlung der Versicherungssumme 1914','Automatisch in der Fallakte gespeichert','v14-pdf','upload_case_document','window.print()','value-1914-pdf.php','inFlight','Rechenfaktor 2026','Baupreisindex 2.263,6 ÷ 100']) assert(page.includes(token));
const pdf = await readFile(new URL('../public/intern/api/value-1914-pdf.php', import.meta.url), 'utf8');
assert(pdf.includes("vtext($ops,45,793,'SV',26,true,$orange)"),'PDF trägt das orange SV-Logo');
assert(pdf.includes('/Encoding /WinAnsiEncoding'),'PDF verwendet eine definierte Zeichenkodierung');
console.log('Gebäudewertermittlung 1914 vollständig geprüft.');
