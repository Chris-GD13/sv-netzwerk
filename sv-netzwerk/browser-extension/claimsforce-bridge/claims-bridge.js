window.addEventListener('message', event => {
  if (event.source !== window || event.origin !== location.origin || event.data?.source !== 'svnet-claimsforce-main' || event.data?.type !== 'TOKEN') return;
  try { chrome.runtime.sendMessage({ type: 'CLAIMS_TOKEN', token: event.data.token }).catch(() => {}); } catch {}
});

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  if (message?.type === 'OPEN_PLANNING') {
    const link = document.querySelector('a[href="/planning"],a[href^="/planning?"]');
    if (!link) { sendResponse({ ok: false, error: 'ClaimsForce ist noch nicht angemeldet.' }); return; }
    link.click();
    sendResponse({ ok: true });
    return;
  }
  if (message?.type === 'OPEN_FUTURE_APPOINTMENTS') {
    const buttons = [...document.querySelectorAll('button')];
    const future = buttons.find(button => /Mit Termin|Zukünftige Termine/i.test(button.textContent || ''));
    const show = [...buttons].filter(button => /^Anzeigen$/i.test((button.textContent || '').trim())).at(-1);
    (future || show)?.click();
    sendResponse({ ok: true });
    return;
  }
  if (message?.type !== 'SCRAPE_CLAIMS') return;
  const claims = new Map();
  const wait = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));
  const dayLabel = button => (button.textContent || '').replace(/\s+/g, ' ').trim();
  const isDayButton = button => /^(Montag|Dienstag|Mittwoch|Donnerstag|Freitag|Samstag|Sonntag), .+\b20\d{2}$/.test(dayLabel(button));
  const collect = () => document.querySelectorAll('a[href*="/claims/"],a[href*="/planning/"]').forEach(anchor => {
    const match = anchor.getAttribute('href')?.match(/\/(?:claims|planning)\/([0-9a-f-]{20,})(?:\/|$)/i);
    if (match) claims.set(match[1], { id: match[1], label: anchor.textContent?.trim() || '' });
  });
  const waitForDay = async (button, previousCount) => {
    const deadline = Date.now() + 5000;
    while (Date.now() < deadline) {
      collect();
      if (claims.size > previousCount || button.parentElement?.querySelector('a[href*="/claims/"],a[href*="/planning/"]')) return;
      await wait(120);
    }
  };
  (async () => {
    collect();
    const days = [...document.querySelectorAll('[role="tabpanel"] button')].filter(isDayButton).map(dayLabel);
    for (const label of days) {
      const day = [...document.querySelectorAll('[role="tabpanel"] button')].find(button => isDayButton(button) && dayLabel(button) === label);
      if (!day) continue;
      if (!day.parentElement?.querySelector('a[href*="/claims/"],a[href*="/planning/"]')) {
        const previousCount = claims.size;
        day.click();
        await waitForDay(day, previousCount);
      }
      collect();
    }
    sendResponse({ ok: true, claims: [...claims.values()], url: location.href });
  })().catch(error => sendResponse({ ok: false, error: error.message, claims: [] }));
  return true;
});
