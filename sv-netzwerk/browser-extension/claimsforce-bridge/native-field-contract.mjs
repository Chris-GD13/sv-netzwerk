// Shared contract for native Claimsforce field identity.
// Labels are read from the rendered original form. Never infer by position.
export const NATIVE_PROFILES = Object.freeze(['christian','holger','marc','jens']);
export function normalizeFieldLabel(value) {
  return String(value ?? '').normalize('NFKC').replace(/\s+/gu, ' ').trim().toLocaleLowerCase('de-DE');
}
export function assertFieldLabels(fields, requiredLabels = []) {
  if (!Array.isArray(fields) || !fields.length) throw Error('NATIVE_FIELDS_MISSING');
  const seen = new Set();
  for (const field of fields) {
    const key = normalizeFieldLabel(field?.label);
    if (!key) throw Error('NATIVE_FIELD_LABEL_MISSING');
    if (seen.has(key)) throw Error('NATIVE_FIELD_LABEL_DUPLICATE');
    seen.add(key);
  }
  for (const required of requiredLabels) {
    if (!seen.has(normalizeFieldLabel(required))) throw Error('NATIVE_REQUIRED_FIELD_LABEL_MISSING');
  }
  return true;
}
export function sameFieldLabel(expected, actual) {
  return normalizeFieldLabel(expected) !== '' && normalizeFieldLabel(expected) === normalizeFieldLabel(actual);
}
