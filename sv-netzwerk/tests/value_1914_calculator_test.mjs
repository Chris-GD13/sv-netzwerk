import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { buildingRate, calculateBuildingValuation } from '../public/intern/value-1914.js';

assert.equal(buildingRate('no_basement', 'flat', 1), 160);
assert.equal(buildingRate('no_basement', 'attic_finished', 7), 125);
assert.equal(buildingRate('basement', 'flat', 5), 135);
assert.equal(buildingRate('basement', 'attic_unfinished', 2), 165);
assert.equal(buildingRate('basement', 'attic_finished', 4), 135);
const result = calculateBuildingValuation({basement:'basement',roof:'attic_finished',floors:2,area:100,basementArea:10,garages:1,carports:1,underground:1,specialValue:22600,outbuildingValue:11300,factor:22.6,surcharges:['premium_facade','premium_sanitary']});
assert.equal(result.valid, true);
assert.equal(result.rate, 150);
assert.equal(result.surcharge, 11);
assert.equal(result.mainValue1914, 16100);
assert.equal(result.total1914, 19850);
assert.equal(result.currentValue, 448610);
const page = await readFile(new URL('../src/pages/intern/wert-1914/index.astro', import.meta.url), 'utf8');
for (const token of ['Ermittlung der Versicherungssumme 1914','Automatisch in der Fallakte gespeichert','v14-pdf','upload_case_document','window.print()']) assert(page.includes(token));
console.log('Gebäudewertermittlung 1914 vollständig geprüft.');
