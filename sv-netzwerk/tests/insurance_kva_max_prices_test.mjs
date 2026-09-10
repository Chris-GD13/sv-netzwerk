import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {bestAutomaticMatch,canonicalUnit,cappedUnitPrice,offeredUnitPrice,rankedMatches} from '../public/intern/kva-calculation-import.js';

const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const page=fs.readFileSync(path.join(root,'src/pages/intern/kalkulation/versicherungsschaeden.astro'),'utf8');
const script=fs.readFileSync(path.join(root,'public/intern/kva-calculation-import.js'),'utf8');
const prices=JSON.parse(fs.readFileSync(path.join(root,'src/data/insurance-damage-prices.json'),'utf8'));

assert.equal(canonicalUnit('qm'),'m²','Quadratmeter müssen mit der Höchstpreisliste vergleichbar sein.');
assert.equal(canonicalUnit('m²'),'m²','Die Schreibweise der Höchstpreisliste muss erhalten bleiben.');
assert.equal(offeredUnitPrice({quantity:4,offered_unit_price:null,offered_total:120}),30,'Fehlende KVA-Einheitspreise müssen aus Menge und Positionssumme ableitbar sein.');
assert.equal(cappedUnitPrice({quantity:2,offered_unit_price:80},{price:50}),50,'Ein KVA-Preis oberhalb der Höchstliste muss gekappt werden.');
assert.equal(cappedUnitPrice({quantity:2,offered_unit_price:40},{price:50}),40,'Ein günstigerer KVA-Preis darf nicht auf den Höchstpreis angehoben werden.');

const drying={description:'Raumtrocknung mit Kondensationstrockner',unit:'St'};
const dryingMatch=bestAutomaticMatch(drying,prices);
assert.notEqual(dryingMatch,null,'Eine eindeutige Raumtrocknung muss automatisch zugeordnet werden.');
assert.equal(prices[dryingMatch].code,'02.03.000','Die Raumtrocknung muss auf die richtige Höchstpreisposition zeigen.');
const paintMatch=bestAutomaticMatch({description:'Wand- und Deckenflächen mit Dispersionsfarbe deckend streichen',unit:'QM'},prices);
assert.notEqual(paintMatch,null,'Ein identischer Leistungstext muss trotz ähnlicher Listenpositionen eindeutig erkannt werden.');
assert.equal(prices[paintMatch].code,'09.00.001','Ein identischer Dispersionsanstrich muss auf die exakte Höchstpreisposition zeigen.');
assert.equal(rankedMatches({description:'Raumtrocknung',unit:'m²'},prices)[0]?.candidate.code,undefined,'Positionen mit unvereinbarer Einheit dürfen nicht automatisch zugeordnet werden.');
assert.equal(bestAutomaticMatch({description:'Türarbeiten im Schadenbereich',unit:'St'},prices),null,'Mehrdeutige Arbeiten müssen zur manuellen Prüfung offen bleiben.');
assert.equal(bestAutomaticMatch({description:'Herd nach Einbau der Küche anschließen',unit:'Stk'},prices),null,'Allgemeine Wörter wie Einbau dürfen keine fachfremde automatische Zuordnung auslösen.');

for(const id of['vs-kva-select','vs-kva-file','vs-kva-read','vs-kva-lines','vs-kva-remap','vs-kva-import','vs-kva-offered-net','vs-kva-approved-net','vs-kva-difference'])assert(page.includes(`id="${id}"`),`KVA-Nachkalkulation benötigt ${id}.`);
assert(page.includes('/intern/kva-calculation-import.js?v=20260910-2'),'Die Höchstpreis-Nachkalkulation muss mit der aktuellen Cache-Version geladen werden.');
assert(script.includes("/intern/api/bki-calculator.php?action=analyze_kva"),'Der KVA muss über die beleggesicherte Positionsauslesung laufen.');
assert(script.includes('Math.min(offered,maximum)'),'Der Prüfpreis muss der niedrigere Wert aus KVA und Höchstpreis sein.');
assert(script.includes('wurde positionsweise anhand der hinterlegten Höchstpreisliste nachkalkuliert'),'Die Übernahme muss eine nachvollziehbare Prüfnotiz in der Kalkulation hinterlassen.');
assert(script.includes('Noch nicht eindeutig zugeordnet')&&page.includes('nicht automatisch angesetzt'),'Unsichere Zuordnungen müssen sichtbar offen bleiben.');
assert(page.includes("!/^KVA\\b/i.test(input.value)"),'KVA-Originalbeschreibungen dürfen nicht nachträglich durch Kurztexte überschrieben werden.');

console.log('KVA-Nachkalkulation gegen die Höchstpreisliste abgesichert.');
