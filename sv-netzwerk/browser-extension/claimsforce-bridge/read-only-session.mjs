// Read jobs must never reset a shared browser session or touch another tab.
export function capturedTokenProfile(token, emails, emailOf) {
  const email = emailOf(token);
  const profile = Object.keys(emails).find(key => emails[key] === email);
  if (!email || !profile) throw new Error('CF-TOKEN-ACCOUNT: Sitzung gehört zu keinem eingerichteten Konto.');
  return profile;
}
export async function requireReadOnlySession({ profile, expectedEmail, readSession, emailOf, now = Date.now }) {
  const row = await readSession();
  const token = row?.claimsToken;
  if (!token || row.claimsTokenProfile !== profile || !expectedEmail || emailOf(token) !== expectedEmail) {
    throw new Error('CF-READ-SESSION: Bitte das passende Claimsforce-Konto anmelden. Lesetest verändert keine gemeinsame Anmeldung.');
  }
  let payload;
  try { payload = JSON.parse(atob(token.split('.')[1].replace(/-/g, '+').replace(/_/g, '/'))); } catch {}
  if (!Number.isFinite(payload?.exp) || payload.exp * 1000 <= now() + 30000) {
    throw new Error('CF-READ-SESSION-EXPIRED: Claimsforce-Sitzung bitte im bestehenden Konto erneuern.');
  }
  return token;
}
