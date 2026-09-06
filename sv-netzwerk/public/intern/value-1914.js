const finite = (value) => {
  if (typeof value === 'number') return Number.isFinite(value) ? value : 0;
  const raw = String(value ?? '').trim();
  const parsed = Number(raw.includes(',') ? raw.replace(/\./g, '').replace(',', '.') : raw);
  return Number.isFinite(parsed) ? parsed : 0;
};

export const BUILDING_RATES = Object.freeze({
  no_basement: Object.freeze({
    flat: Object.freeze([[1, 2, 160], [3, 7, 135]]),
    attic_unfinished: Object.freeze([[1, 1, 160], [2, 2, 140], [3, 7, 135]]),
    attic_finished: Object.freeze([[1, 1, 140], [2, 2, 130], [3, 7, 125]]),
  }),
  basement: Object.freeze({
    flat: Object.freeze([[1, 2, 190], [3, 4, 150], [5, 5, 135], [6, 7, 130]]),
    attic_unfinished: Object.freeze([[1, 1, 190], [2, 2, 165], [3, 4, 150], [5, 7, 130]]),
    attic_finished: Object.freeze([[1, 1, 165], [2, 2, 150], [3, 3, 140], [4, 4, 135], [5, 7, 130]]),
  }),
});

export const SURCHARGES = Object.freeze({
  natural_stone_roof: 4,
  premium_facade: 5,
  stucco_wood_panelling: 6,
  premium_floors: 4,
  mullioned_windows: 4,
  hardwood_doors: 3,
  premium_sanitary: 6,
  heating_solar: 6,
  premium_kitchen: 4,
});

export function buildingRate(basement, roof, floors) {
  const value = Math.trunc(finite(floors));
  const rows = BUILDING_RATES[basement]?.[roof] || [];
  return rows.find(([min, max]) => value >= min && value <= max)?.[2] || 0;
}

export function calculateBuildingValuation(input = {}) {
  const rate = buildingRate(input.basement, input.roof, input.floors);
  const area = Math.max(0, finite(input.area));
  const basementArea = Math.max(0, finite(input.basementArea));
  const garages = Math.max(0, Math.trunc(finite(input.garages)));
  const carports = Math.max(0, Math.trunc(finite(input.carports)));
  const underground = Math.max(0, Math.trunc(finite(input.underground)));
  const specialValue = Math.max(0, finite(input.specialValue));
  const outbuildingValue = Math.max(0, finite(input.outbuildingValue));
  const factor = Math.max(0, finite(input.factor));
  const selected = [...new Set(Array.isArray(input.surcharges) ? input.surcharges : [])];
  const surcharge = selected.reduce((sum, key) => sum + (SURCHARGES[key] || 0), 0);
  if (!rate) return { valid: false, error: 'Bitte Keller, Dachform und Geschosszahl vollständig auswählen.' };
  if (!area) return { valid: false, error: 'Bitte die Wohn-/Gewerbefläche eingeben.' };
  if (!factor) return { valid: false, error: 'Der Baupreisindex muss größer als 0 sein.' };
  const rateWithSurcharge = rate + surcharge;
  const mainValue1914 = rateWithSurcharge * area;
  const basementValue1914 = basementArea * 20;
  const garageValue1914 = garages * 700;
  const carportValue1914 = carports * 350;
  const undergroundValue1914 = underground * 1000;
  const specialValue1914 = specialValue / factor;
  const outbuildingValue1914 = outbuildingValue / factor;
  const total1914 = mainValue1914 + basementValue1914 + garageValue1914 + carportValue1914 + undergroundValue1914 + specialValue1914 + outbuildingValue1914;
  const currentValue = total1914 * factor;
  return { valid: true, rate, surcharge, rateWithSurcharge, area, basementArea, garages, carports, underground, specialValue, outbuildingValue, factor, mainValue1914, basementValue1914, garageValue1914, carportValue1914, undergroundValue1914, specialValue1914, outbuildingValue1914, total1914, currentValue };
}

// Bestehende Exporte bleiben für andere Portalbestandteile kompatibel.
export function calculateValue1914(input = {}) {
  const amount = Math.max(0, finite(input.amount));
  const index = Math.max(0, finite(input.index));
  const direction = input.direction === 'to1914' ? 'to1914' : 'toCurrent';
  if (!amount || !index) return { valid: false, error: 'Betrag und Baupreisindex müssen größer als 0 sein.' };
  const result = direction === 'toCurrent' ? amount * index / 100 : amount * 100 / index;
  return { valid: true, amount, index, direction, result };
}

export function calculateUnderinsurance(input = {}) {
  const insured = Math.max(0, finite(input.insured));
  const required = Math.max(0, finite(input.required));
  const claim = Math.max(0, finite(input.claim));
  if (!insured || !required) return { valid: false, error: 'Versicherter und erforderlicher Wert müssen größer als 0 sein.' };
  const ratio = Math.min(1, insured / required);
  return { valid: true, insured, required, claim, ratio, shortfallRate: 1 - ratio, estimatedCompensation: claim * ratio, estimatedReduction: claim * (1 - ratio) };
}
