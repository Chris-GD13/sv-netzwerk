import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { calculateValue1914, calculateUnderinsurance } from '../public/intern/value-1914.js';

const forward = calculateValue1914({ amount: 20000, index: 2263.6 });
assert.equal(forward.valid, true);
assert.equal(forward.result, 452720);
const reverse = calculateValue1914({ amount: 452720, index: 2263.6, direction: 'to1914' });
assert(Math.abs(reverse.result - 20000) < 0.001);
const under = calculateUnderinsurance({ insured: 8000, required: 10000, claim: 50000 });
assert.equal(under.ratio, 0.8);
assert.equal(under.estimatedCompensation, 40000);
const page = await readFile(new URL('../src/pages/intern/wert-1914/index.astro', import.meta.url), 'utf8');
const nav = await readFile(new URL('../src/components/internal/CalculationTypeNav.astro', import.meta.url), 'utf8');
const cases = await readFile(new URL('../src/pages/intern/versicherungsfaelle/index.astro', import.meta.url), 'utf8');
assert(page.includes('[2026,2263.6]') && page.includes('Unterversicherungs-Check'));
assert(nav.includes("href: '/intern/wert-1914/'"));
assert(page.includes('svnet-report-text-transfer') && cases.includes('svnet-report-text-transfer'));
console.log('Wert-1914-Rechner: Vorwärts-, Rückwärts- und Unterversicherungsberechnung geprüft.');
