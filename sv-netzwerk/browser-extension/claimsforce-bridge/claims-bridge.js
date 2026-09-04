const observedClaims = new Map();
window.addEventListener('message', event => {
  if (event.source !== window || event.origin !== location.origin || event.data?.source !== 'svnet-claimsforce-main') return;
  if (event.data?.type === 'TOKEN') {
    try { chrome.runtime.sendMessage({ type: 'CLAIMS_TOKEN', token: event.data.token }).catch(() => {}); } catch {}
  }
  if (event.data?.type === 'CLAIMS_SNAPSHOT') {
    for (const claim of event.data.claims || []) if (claim?.id) observedClaims.set(claim.id, claim);
  }
});

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  if (message?.type === 'OPEN_PLANNING') {
    const link = document.querySelector('a[href="/planning"],a[href^="/planning?"]');
    if (link) link.click();
    else if (!location.pathname.startsWith('/planning')) { sendResponse({ ok: false, error: 'ClaimsForce ist noch nicht angemeldet.' }); return; }
    sendResponse({ ok: true });
    return;
  }
  if (message?.type === 'OPEN_FUTURE_APPOINTMENTS') {
    const controls = [...document.querySelectorAll('button,[role="tab"],[role="radio"],a')];
    const future = controls.find(node => /Mit Termin|Zukünftige Termine|Termine in der Zukunft/i.test(node.textContent || ''));
    if (future) future.click();
    const url = new URL(location.href);
    url.pathname = '/planning';
    url.searchParams.set('bucket', 'WITH_FUTURE_APPOINTMENT');
    url.hash = 'with-future-appointment';
    if (url.href !== location.href) {
      history.pushState({}, '', url);
      dispatchEvent(new PopStateEvent('popstate'));
      dispatchEvent(new HashChangeEvent('hashchange'));
    }
    sendResponse({ ok: true, strategy: future ? 'control-and-route' : 'route' });
    return;
  }
  if (message?.type === 'OPEN_TASKS') {
    const controls = [...document.querySelectorAll('a,button,[role="tab"]')];
    const tasks = controls.find(node => /^Aufgaben(?:\s*\d+)?$/i.test((node.textContent || '').replace(/\s+/g, ' ').trim()));
    if (tasks) tasks.click();
    sendResponse({ ok: !!tasks });
    return;
  }
  if (message?.type === 'READ_OPEN_TASKS') {
    const labels = [...document.querySelectorAll('div,span,p,strong')].filter(node => /^Alle$/i.test((node.textContent || '').trim()));
    let count = null;
    for (const label of labels) {
      let node = label;
      for (let depth = 0; node && depth < 5; depth++, node = node.parentElement) {
        const text = (node.textContent || '').replace(/\s+/g, ' ').trim();
        const match = text.match(/^(\d{1,5})\s+Alle$/i) || text.match(/^Alle\s+(\d{1,5})$/i);
        if (match) { count = Number(match[1]); break; }
      }
      if (Number.isInteger(count)) break;
    }
    sendResponse({ ok: Number.isInteger(count), openTasks: count });
    return;
  }
  if (message?.type === 'SESSION_STATE') {
    sendResponse({ ok: true, route: location.pathname, observedClaims: observedClaims.size, planning: location.pathname.startsWith('/planning'), login: location.pathname.startsWith('/login') });
    return;
  }
  if (message?.type === 'READ_ACCOUNT_IDENTITY') {
    const roots = [...new Set([document.querySelector('header'), document.querySelector('[role="banner"]'), document.querySelector('nav')].filter(Boolean))];
    const badges = new Set();
    for (const root of roots) for (const node of [root, ...root.querySelectorAll('*')]) {
      const text = (node.textContent || '').replace(/\s+/g, ' ').trim();
      if (/^[A-ZÄÖÜ]{2}$/.test(text)) badges.add(text);
    }
    sendResponse({ ok: true, badges: [...badges] });
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
    for (const [id, claim] of observedClaims) if (!claims.has(id)) claims.set(id, claim);
    sendResponse({ ok: true, claims: [...claims.values()], route: location.pathname, domClaims: claims.size - observedClaims.size, observedClaims: observedClaims.size });
  })().catch(error => sendResponse({ ok: false, error: error.message, claims: [] }));
  return true;
});
