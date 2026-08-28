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

const addressPart = (value, names) => {
  if (!value || typeof value !== 'object') return '';
  const entries = Object.entries(value);
  for (const name of names) {
    const entry = entries.find(([key]) => key.toLowerCase() === name.toLowerCase());
    const result = text(entry?.[1]);
    if (result) return result;
  }
  return '';
};

function addressFrom(value) {
  if (!value) return {};
  if (typeof value === 'string') {
    const compact = value.replace(/\s+/g, ' ').trim();
    const match = compact.match(/^(.*?)[,;]?\s+(\d{5})\s+(.+)$/);
    return match ? { street: match[1].replace(/[,;]\s*$/, ''), postalCode: match[2], city: match[3] } : {};
  }
  if (typeof value !== 'object') return {};
  const nested = value.address || value.locationAddress || value.postalAddress;
  if (nested && nested !== value) {
    const parsed = addressFrom(nested);
    if (parsed.street || parsed.postalCode || parsed.city) return parsed;
  }
  const streetName = addressPart(value, ['street', 'streetAddress', 'addressLine1', 'streetName', 'road']);
  const houseNumber = addressPart(value, ['houseNumber', 'streetNumber']);
  return {
    street: [streetName, houseNumber].filter(Boolean).join(' '),
    postalCode: addressPart(value, ['postalCode', 'zipCode', 'zip', 'postcode']),
    city: addressPart(value, ['city', 'town', 'place', 'locality'])
  };
}

function firstAddress(candidates) {
  for (const candidate of candidates) {
    const parsed = addressFrom(candidate);
    if (parsed.street || parsed.postalCode || parsed.city) return parsed;
  }
  return {};
}

export function mapClaim(claim, communication = {}, appointments = []) {
  const policyholder = claim?.policyholder || communication?.policyholder || claim?.claimant || {};
  const policyAddress = policyholder?.address || claim?.policyholderAddress || {};
  const next = [...(appointments || []), ...(claim?.appointments || [])].filter(item => item?.startDate).sort((a, b) => String(a.startDate).localeCompare(String(b.startDate)))[0] || null;
  const damage = firstAddress([
    claim?.damageLocation, claim?.lossLocation, claim?.damageAddress,
    claim?.damage?.location, claim?.damage?.address,
    communication?.damageLocation, communication?.lossLocation,
    communication?.damage?.location, communication?.damage?.address,
    next?.location, next?.address, next?.appointmentAddress, next?.venue
  ]);
  return {
    schaden_nr: first(claim, ['insurerClaimId', 'claimNumber', 'externalId', 'number']),
    versicherungsschein_nr: first(claim, ['policyNumber', 'insurancePolicyNumber', 'contractNumber']),
    vn_objekt: personName(policyholder) || first(claim, ['policyholderName', 'objectName']),
    strasse: first(policyAddress, ['street', 'streetAddress', 'addressLine1']),
    plz: first(policyAddress, ['postalCode', 'zipCode']),
    ort: first(policyAddress, ['city', 'town']),
    schaden_strasse: damage.street || '',
    schaden_plz: damage.postalCode || '',
    schaden_ort: damage.city || '',
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
