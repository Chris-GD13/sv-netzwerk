const finite = (value) => {
  const parsed = Number(String(value ?? '').replace(',', '.'));
  return Number.isFinite(parsed) ? parsed : 0;
};

export function calculateValue1914(input = {}) {
  const amount = Math.max(0, finite(input.amount));
  const index = Math.max(0, finite(input.index));
  const direction = input.direction === 'to1914' ? 'to1914' : 'toCurrent';
  if (amount <= 0 || index <= 0) return { valid: false, error: 'Betrag und Baupreisindex müssen größer als 0 sein.' };
  const result = direction === 'toCurrent' ? amount * index / 100 : amount * 100 / index;
  return { valid: true, amount, index, direction, result };
}

export function calculateUnderinsurance(input = {}) {
  const insured = Math.max(0, finite(input.insured));
  const required = Math.max(0, finite(input.required));
  const claim = Math.max(0, finite(input.claim));
  if (insured <= 0 || required <= 0) return { valid: false, error: 'Versicherter und erforderlicher Wert müssen größer als 0 sein.' };
  const ratio = Math.min(1, insured / required);
  return { valid: true, insured, required, claim, ratio, shortfallRate: 1 - ratio, estimatedCompensation: claim * ratio, estimatedReduction: claim * (1 - ratio) };
}
