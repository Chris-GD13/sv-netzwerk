const DB_NAME = 'svnet-claimsforce-vault';
const STORE = 'keys';

function openDb() {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, 1);
    request.onupgradeneeded = () => request.result.createObjectStore(STORE);
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

async function vaultKey() {
  const db = await openDb();
  const existing = await new Promise((resolve, reject) => {
    const request = db.transaction(STORE).objectStore(STORE).get('claimsforce');
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
  if (existing) return existing;
  const key = await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
  await new Promise((resolve, reject) => {
    const tx = db.transaction(STORE, 'readwrite');
    tx.objectStore(STORE).put(key, 'claimsforce');
    tx.oncomplete = resolve;
    tx.onerror = () => reject(tx.error);
  });
  return key;
}

const SUPPORTED_PROFILES = ['christian', 'holger', 'marc', 'jens'];
const profileKey = value => {
  const profile = String(value || '').trim().toLowerCase();
  if (!SUPPORTED_PROFILES.includes(profile)) throw new Error('Ungültiges ClaimsForce-Profil.');
  return `credentials_${profile}`;
};
const portalKey = 'portal_credentials';

async function saveEncrypted(keyName, credentials) {
  const key = await vaultKey();
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const clear = new TextEncoder().encode(JSON.stringify(credentials));
  const encrypted = new Uint8Array(await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, clear));
  await chrome.storage.local.set({ [keyName]: { iv: [...iv], data: [...encrypted] } });
}

async function loadEncrypted(keyName) {
  const stored = (await chrome.storage.local.get(keyName))[keyName];
  if (!stored?.iv || !stored?.data) return null;
  try {
    const clear = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: new Uint8Array(stored.iv) }, await vaultKey(), new Uint8Array(stored.data));
    return JSON.parse(new TextDecoder().decode(clear));
  } catch { return null; }
}

export async function saveCredentials(profile, credentials) {
  await saveEncrypted(profileKey(profile), credentials);
}

export async function loadCredentials(profile) {
  return loadEncrypted(profileKey(profile));
}

export async function clearCredentials(profile) {
  await chrome.storage.local.remove(profileKey(profile));
}

export async function savePortalCredentials(credentials) {
  await saveEncrypted(portalKey, credentials);
}

export async function loadPortalCredentials() {
  return loadEncrypted(portalKey);
}

export async function clearPortalCredentials() {
  await chrome.storage.local.remove(portalKey);
}
