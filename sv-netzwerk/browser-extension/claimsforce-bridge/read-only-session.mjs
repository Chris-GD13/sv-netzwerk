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

// Extension reload clears session storage while Claims itself can stay logged in.
// Recover only through an owned read-only tab, never reload/logout a user tab.
export async function recoverReadOnlySession(options, io) {
  try { return await requireReadOnlySession(options); }
  catch (error) {
    if (!/^CF-READ-SESSION(?::|-EXPIRED:)/.test(String(error.message))) throw error;
    const existing = await options.readSession();
    if (existing?.claimsToken && (existing.claimsTokenProfile !== options.profile || options.emailOf(existing.claimsToken) !== options.expectedEmail)) throw error;
    let tab;
    try {
      tab = await io.open();
      const deadline = io.now() + 15000;
      while (io.now() < deadline) {
        const row = await options.readSession();
        if (row?.claimsToken && (row.claimsTokenProfile !== options.profile || options.emailOf(row.claimsToken) !== options.expectedEmail)) throw error;
        try { return await requireReadOnlySession(options); }
        catch (current) {
          if (!/^CF-READ-SESSION(?::|-EXPIRED:)/.test(String(current.message))) throw current;
        }
        await io.pause(200);
      }
      throw new Error('CF-READ-SESSION-RECOVERY: Bestehende Claims-Anmeldung konnte im eigenen Lesetab nicht bestaetigt werden. Kein Kontowechsel ausgefuehrt.');
    } finally {
      if (tab?.id != null) await io.close(tab.id).catch(() => {});
    }
  }
}
