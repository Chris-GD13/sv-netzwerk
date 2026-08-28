export const isBlank = value => value == null || (typeof value === 'string' && value.trim() === '');

export function mergeOnlyBlank(existing, incoming) {
  const merged = { ...(existing || {}) };
  Object.entries(incoming || {}).forEach(([key, value]) => { if (isBlank(merged[key]) && !isBlank(value)) merged[key] = value; });
  return merged;
}

const text = value => typeof value === 'string' || typeof value === 'number' ? String(value).trim() : '';
const path = (object, value) => value.split('.').reduce((current, part) => current?.[part], object);
const first = (object, paths) => { for (const candidate of paths) { const value = text(path(object, candidate)); if (value) return value; } return ''; };
const personName = value => text(value) || [value?.firstName, value?.lastName].map(text).filter(Boolean).join(' ');

export function mapClaim(claim, communication = {}, appointments = []) {
  const policyholder = claim?.policyholder || communication?.policyholder || claim?.claimant || {};
  const damage = claim?.damageLocation || claim?.lossLocation || communication?.damageLocation || {};
  const policyAddress = policyholder?.address || claim?.policyholderAddress || {};
  const next = [...(appointments || []), ...(claim?.appointments || [])].filter(item => item?.startDate).sort((a, b) => String(a.startDate).localeCompare(String(b.startDate)))[0] || null;
  return {
    schaden_nr: first(claim, ['insurerClaimId', 'claimNumber', 'externalId', 'number']),
    versicherungsschein_nr: first(claim, ['policyNumber', 'insurancePolicyNumber', 'contractNumber']),
    vn_objekt: personName(policyholder) || first(claim, ['policyholderName', 'objectName']),
    strasse: first(policyAddress, ['street', 'streetAddress', 'addressLine1']),
    plz: first(policyAddress, ['postalCode', 'zipCode']),
    ort: first(policyAddress, ['city', 'town']),
    schaden_strasse: first(damage, ['street', 'streetAddress', 'addressLine1']),
    schaden_plz: first(damage, ['postalCode', 'zipCode']),
    schaden_ort: first(damage, ['city', 'town']),
    schadenart: first(claim, ['damageType.name', 'damageType', 'damage.name', 'damage']),
    reserve: first(claim, ['reserve', 'reserveAmount', 'claimAmount']),
    telefon: first(policyholder, ['phone', 'phoneNumber', 'landline']),
    mobil: first(policyholder, ['mobile', 'mobilePhone', 'cellPhone']),
    email: first(policyholder, ['email', 'emailAddress']),
    claimsforce_claim_id: text(claim?.id),
    claimsforce_termin: next,
    claimsforce_zuletzt_eingelesen: new Date().toISOString()
  };
}

export function safeFileName(value, fallback = 'ClaimsForce-Datei') {
  const name = String(value || fallback).replace(/[<>:"/\\|?*\x00-\x1f]/g, '-').trim();
  return name.slice(0, 180) || fallback;
}
