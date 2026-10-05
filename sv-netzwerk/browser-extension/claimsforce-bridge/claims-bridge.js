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
  if (message?.type === 'OPEN_PLANNING_BUCKET') {
    const buckets = {
      WITH_FUTURE_APPOINTMENT: { hash: 'with-future-appointment', control: /Mit Termin|Zukünftige Termine|Termine in der Zukunft/i },
      WITHOUT_APPOINTMENT: { hash: 'without-appointment', control: /Ohne Termin|Kein Termin|Termin ausstehend/i }
    };
    const bucket = buckets[String(message.bucket || '').toUpperCase()];
    if (!bucket) { sendResponse({ ok: false, error: 'Unbekannte Planungsansicht.' }); return; }
    const controls = [...document.querySelectorAll('button,[role="tab"],[role="radio"],a')];
    const control = controls.find(node => bucket.control.test(node.textContent || ''));
    if (control) control.click();
    const url = new URL(location.href);
    url.pathname = '/planning';
    url.searchParams.set('bucket', String(message.bucket).toUpperCase());
    url.hash = bucket.hash;
    if (url.href !== location.href) {
      history.pushState({}, '', url);
      dispatchEvent(new PopStateEvent('popstate'));
      dispatchEvent(new HashChangeEvent('hashchange'));
    }
    sendResponse({ ok: true, strategy: control ? 'control-and-route' : 'route', bucket: String(message.bucket).toUpperCase() });
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
    const visible = node => node.getClientRects?.().length > 0 && getComputedStyle(node).visibility !== 'hidden' && getComputedStyle(node).display !== 'none';
    const clean = value => String(value || '').replace(/\s+/g, ' ').trim();
    const patterns = [/^(\d{1,5})\s*Alle$/i, /^Alle\s*[(\[]?\s*(\d{1,5})\s*[)\]]?$/i, /^Aufgaben\s*[-–:]?\s*Alle\s*[(\[]?\s*(\d{1,5})\s*[)\]]?$/i];
    const counts = [];
    const samples = [];
    for (const node of document.querySelectorAll('body *')) {
      if (!visible(node)) continue;
      const text = clean(node.textContent);
      if (!text || text.length > 40 || !/Alle/i.test(text)) continue;
      if (samples.length < 8) samples.push(text);
      for (const pattern of patterns) {
        const match = text.match(pattern);
        if (match) { counts.push(Number(match[1])); break; }
      }
    }
    const uniqueCounts = [...new Set(counts)];
    const onTasks = location.pathname.startsWith('/tasks');
    const count = onTasks && uniqueCounts.length === 1 ? uniqueCounts[0] : null;
    sendResponse({ ok: Number.isInteger(count), openTasks: count, debug: { path: location.pathname, candidates: uniqueCounts, samples } });
    return;
  }
  if (message?.type === 'SCRAPE_ALL_CLAIMS') {
    const wait = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));
    const claimPattern = /\/claims\/([0-9a-f-]{20,})(?:\/|$)/i;
    const claims = new Map();
    const since = String(message.since || '').trim();
    const sinceTime = /^\d{4}-\d{2}-\d{2}$/.test(since) ? Date.parse(`${since}T00:00:00`) : NaN;
    const parseGermanDate = value => {
      const match = String(value || '').match(/^(\d{2})\.(\d{2})\.(\d{4})$/);
      return match ? Date.parse(`${match[3]}-${match[2]}-${match[1]}T00:00:00`) : NaN;
    };
    const dateTime = value => {
      const german = parseGermanDate(value);
      if (Number.isFinite(german)) return german;
      const parsed = Date.parse(String(value || ''));
      return Number.isFinite(parsed) ? parsed : NaN;
    };
    const include = entered => !Number.isFinite(sinceTime) || (Number.isFinite(dateTime(entered)) && dateTime(entered) >= sinceTime);
    const collect = () => {
      for (const row of document.querySelectorAll('table tbody tr,[role="row"]')) {
        const anchor = row.querySelector('a[href*="/claims/"]');
        if (!anchor) continue;
        const match = String(anchor.getAttribute('href') || '').match(claimPattern);
        if (!match) continue;
        const cells = [...row.querySelectorAll('td,[role="cell"]')].map(cell => (cell.textContent || '').replace(/\s+/g, ' ').trim());
        const dates = [...(row.querySelectorAll('time[datetime],[data-date],[data-created-at],[data-updated-at]') || [])].map(node => node.getAttribute('datetime') || node.getAttribute('data-date') || node.getAttribute('data-created-at') || node.getAttribute('data-updated-at') || '');
        const entered = cells[3]?.match(/\b\d{2}\.\d{2}\.\d{4}\b/)?.[0] || dates[0] || '';
        if (!include(entered)) continue;
        const text = (row.textContent || anchor.textContent || '').replace(/\s+/g, ' ').trim();
        claims.set(match[1], { id: match[1], label: cells[1] || String(anchor.textContent || '').replace(/\s+/g, ' ').trim() || text.slice(0, 120), listVersion: entered || text.slice(0, 180), enteredAt: entered });
      }
      // Some ClaimsForce list variants render claim links without table rows.
      // Collect those links as a fallback so full sync never falls back to
      // the 14 planning tasks.
      for (const anchor of document.querySelectorAll('a[href*="/claims/"]')) {
        const match = String(anchor.getAttribute('href') || '').match(claimPattern);
        if (!match || claims.has(match[1])) continue;
        const text = (anchor.closest('tr,[role="row"],article,li')?.textContent || anchor.textContent || '').replace(/\s+/g, ' ').trim();
        const entered = text.match(/\b\d{2}\.\d{2}\.\d{4}\b/)?.[0] || '';
        if (!include(entered)) continue;
        claims.set(match[1], { id: match[1], label: String(anchor.textContent || '').replace(/\s+/g, ' ').trim() || text.slice(0, 120), listVersion: entered || text.slice(0, 180), enteredAt: entered });
      }
    };
    const nextButton = () => [...document.querySelectorAll('button,a,[role="button"]')].find(node => {
      const text = (node.textContent || '').replace(/\s+/g, ' ').trim();
      const label = `${text} ${node.getAttribute('aria-label') || ''} ${node.getAttribute('title') || ''}`;
      return (/^(Nächste|Weiter|Next|›|>)$/i.test(text) || /Nächste|Weiter|Next|next page/i.test(label)) && !node.disabled && node.getAttribute('aria-disabled') !== 'true';
    });
    (async () => {
      if (location.pathname !== '/claims') throw new Error(`ClaimsForce-Fallliste ist nicht geöffnet (aktuell ${location.pathname || 'unbekannt'}).`);
      let page = 0;
      for (; page < 120; page++) {
        const before = claims.size;
        collect();
        const next = nextButton();
        if (!next) break;
        next.click();
        await wait(900);
        collect();
        if (claims.size === before && page > 2) break;
      }
      for (const [id, claim] of observedClaims) {
        if (!claims.has(id) && include(claim?.enteredAt || claim?.createdAt || claim?.updatedAt || claim?.date)) claims.set(id, claim);
      }
      sendResponse({ ok: true, claims: [...claims.values()], route: location.pathname, pages: page + 1, since, excludedUndated: Number.isFinite(sinceTime) ? [...observedClaims.values()].filter(claim => !Number.isFinite(dateTime(claim?.enteredAt || claim?.createdAt || claim?.updatedAt || claim?.date))).length : 0 });
    })().catch(error => sendResponse({ ok: false, error: error.message, claims: [] }));
    return true;
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
