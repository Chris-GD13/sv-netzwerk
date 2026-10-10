const observedClaims = new Map();
const pendingInvestigations = new Map();
const requestViaPage = (endpoint, queries) => new Promise(resolve => {
  const id = `${Date.now()}-${Math.random().toString(36).slice(2)}`;
  const timer = setTimeout(() => { pendingInvestigations.delete(id); resolve({ ok: false, status: 0, hasAuth: false, body: 'Zeitlimit' }); }, 60000);
  pendingInvestigations.set(id, data => { clearTimeout(timer); pendingInvestigations.delete(id); resolve(data); });
  window.postMessage({ source: 'svnet-claimsforce-bridge', type: 'INVESTIGATIONS_REQUEST', id, endpoint, queries }, location.origin);
});
const requestInvoicedRows = (expected = 0) => new Promise(resolve => {
  const id = `${Date.now()}-${Math.random().toString(36).slice(2)}`;
  const finish = value => { clearTimeout(timer); window.removeEventListener('message', onMessage); resolve(value); };
  const onMessage = event => {
    if (event.source !== window || event.origin !== location.origin || event.data?.source !== 'svnet-claimsforce-main' || event.data?.type !== 'INVOICED_ROWS_RESPONSE' || event.data.id !== id) return;
    finish(event.data);
  };
  const timer = setTimeout(() => finish({ ok: false, rows: [] }), 75000);
  window.addEventListener('message', onMessage);
  window.postMessage({ source: 'svnet-claimsforce-bridge', type: 'INVOICED_ROWS_REQUEST', id, expected }, location.origin);
});
window.addEventListener('message', event => {
  if (event.source !== window || event.origin !== location.origin || event.data?.source !== 'svnet-claimsforce-main') return;
  if (event.data?.type === 'INVESTIGATIONS_RESPONSE') pendingInvestigations.get(event.data.id)?.(event.data);
  if (event.data?.type === 'TOKEN') {
    try { chrome.runtime.sendMessage({ type: 'CLAIMS_TOKEN', token: event.data.token }).catch(() => {}); } catch {}
  }
  if (event.data?.type === 'CLAIMS_SNAPSHOT') {
    for (const claim of event.data.claims || []) if (claim?.id) observedClaims.set(claim.id, claim);
  }
});

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  if (message?.type === 'FETCH_INVESTIGATIONS') {
    // Zuerst mit dem Authorization-Header, den die ClaimsForce-Seite selbst an diese API sendet.
    requestViaPage(message.endpoint, message.queries).then(pageResult => {
      if (pageResult.hasAuth) { sendResponse({ ...pageResult, via: 'seite' }); return; }
      const headers = new Headers({ 'Content-Type': 'application/json; charset=UTF-8' });
      if (message.authorization) headers.append('Authorization', message.authorization);
      return fetch(`${String(message.endpoint).replace(/\/+$/, '')}/investigation-list`, { method: 'POST', mode: 'cors', headers, body: JSON.stringify({ queries: message.queries, countsOnly: false }) })
        .then(async response => sendResponse({ ok: response.ok, status: response.status, via: 'tab', info: [pageResult.body, response.headers.get('x-amzn-errortype'), response.headers.get('content-type')].filter(Boolean).join(','), body: response.ok ? await response.json().catch(() => null) : (await response.text().catch(() => '')).slice(0, 120) }))
        .catch(error => sendResponse({ ok: false, status: 0, via: 'tab', info: pageResult.body, body: String(error?.message || error).slice(0, 120) }));
    });
    return true;
  }
  if (message?.type === 'OPEN_REPORTS') {
    const label = node => (node.textContent || node.getAttribute('aria-label') || '').replace(/\s+/g, ' ').trim();
    const control = [...document.querySelectorAll('a,button,[role="tab"],[role="link"]')]
      .find(node => /^Berichte(?:\s+\d+)?$/i.test(label(node)));
    if (control) control.click();
    sendResponse({
      ok: !!control,
      href: control ? String(control.getAttribute('href') || '') : '',
      route: location.pathname,
      control: control ? control.tagName.toLowerCase() : ''
    });
    return;
  }
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
      let found = false;
      for (const pattern of patterns) {
        const match = text.match(pattern);
        if (match) { counts.push(Number(match[1])); found = true; break; }
      }
      if (found || !/^Alle$/i.test(text)) continue;
      const around = [node.nextElementSibling, node.previousElementSibling, node.parentElement?.nextElementSibling, node.parentElement].map(item => clean(item?.textContent));
      if (samples.length < 14) samples.push('Umfeld: ' + around.map(item => item.slice(0, 30)).join(' | '));
      const badge = around.slice(0, 3).map(item => item.match(/^\(?\[?(\d{1,5})\)?\]?$/)).find(Boolean) || clean(node.parentElement?.textContent).match(/^Alle\s*[(\[]?\s*(\d{1,5})\s*[)\]]?$|^(\d{1,5})\s*Alle$/i);
      const value = badge && (badge[1] || badge[2]);
      if (value !== undefined) counts.push(Number(value));
    }
    const navBadges = [];
    for (const node of document.querySelectorAll('a,button,[role="tab"]')) {
      if (!visible(node)) continue;
      const match = clean(node.textContent).match(/^Aufgaben\s*(\d{1,5})$/i);
      if (match) navBadges.push(Number(match[1]));
    }
    const rows = [...document.querySelectorAll('button,a')].filter(node => visible(node) && /^Überprüfen$/i.test(clean(node.textContent))).length;
    const headerVisible = [...document.querySelectorAll('th,[role="columnheader"]')].some(node => visible(node) && /^Schadennummer$/i.test(clean(node.textContent)));
    const onTasks = location.pathname.startsWith('/tasks');
    const uniqueBadges = [...new Set(navBadges)];
    let count = null;
    if (onTasks) {
      if (uniqueBadges.length === 1) count = uniqueBadges[0];
      else if (rows > 0) count = rows;
      else if (headerVisible) count = 0;
    }
    sendResponse({ ok: Number.isInteger(count), openTasks: count, rows, debug: { path: location.pathname, candidates: [...uniqueBadges, 'rows=' + rows, 'header=' + headerVisible], samples } });    return;
  }
  if (message?.type === 'SCRAPE_ALL_CLAIMS') {
    const wait = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));
    const claimPattern = /\/claims\/([0-9a-f-]{20,})(?:\/|$)/i;
    const damageNumberPattern = /\b[A-Za-z]{1,4}\d[A-Za-z0-9]*(?:[-.][A-Za-z0-9]+)+\b|\b(?:\d{2,3}-)?\d{2,9}(?:-\d{1,9}){1,3}\b|\b\d{2}\.\d{5,}\.\d{1,3}\b|\b\d{8,14}\b/;
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
    const include = entered => !Number.isFinite(sinceTime) || !Number.isFinite(dateTime(entered)) || dateTime(entered) >= sinceTime;
    const extractDamageNumber = value => {
      const text = String(value || '');
      const match = text.match(damageNumberPattern);
      if (match) return match[0];
      const firstLine = text.split(/\r?\n/)[0].trim();
      return /[A-Za-z]/.test(firstLine) && /^[A-Za-z0-9][A-Za-z0-9./-]{3,}$/.test(firstLine) ? firstLine : '';
    };
    const normalizedDamageNumber = value => extractDamageNumber(value).replace(/[^A-Za-z0-9]/g, '').toUpperCase();
    const observedByDamageNumber = () => {
      const matches = new Map();
      for (const claim of observedClaims.values()) {
        const key = normalizedDamageNumber(claim?.label);
        if (!key) continue;
        if (!matches.has(key)) matches.set(key, []);
        matches.get(key).push(claim);
      }
      return matches;
    };
    const listedDamageNumbers = new Map();
    let expectedCount = Number((document.body?.innerText || '').match(/Schäden mit erstellten Kostennoten\s*\((\d+)\)/i)?.[1] || 0);
    const collect = () => {
      const observed = observedByDamageNumber();
      for (const row of document.querySelectorAll('table tbody tr,[role="row"]')) {
        const anchor = row.querySelector('a[href*="/claims/"]');
        const match = String(anchor?.getAttribute('href') || '').match(claimPattern);
        const cells = [...row.querySelectorAll('td,[role="cell"]')].map(cell => (cell.innerText || cell.textContent || '').trim());
        const dates = [...(row.querySelectorAll('time[datetime],[data-date],[data-created-at],[data-updated-at]') || [])].map(node => node.getAttribute('datetime') || node.getAttribute('data-date') || node.getAttribute('data-created-at') || node.getAttribute('data-updated-at') || '');
        const numberCell = cells.find(cell => extractDamageNumber(cell)) || '';
        const damageNumber = extractDamageNumber(numberCell);
        if (!damageNumber) continue;
        const entered = numberCell.match(/\b\d{2}\.\d{2}\.\d{4}\b/)?.[0] || cells.find(cell => /\b\d{2}\.\d{2}\.\d{4}\b/.test(cell))?.match(/\b\d{2}\.\d{2}\.\d{4}\b/)?.[0] || dates[0] || '';
        if (!include(entered)) continue;
        const key = normalizedDamageNumber(damageNumber);
        listedDamageNumbers.set(key, { label: damageNumber, enteredAt: entered });
        const text = (row.textContent || anchor.textContent || '').replace(/\s+/g, ' ').trim();
        const candidates = observed.get(normalizedDamageNumber(damageNumber)) || [];
        const claim = match ? observedClaims.get(match[1]) : candidates.length === 1 ? candidates[0] : null;
        const id = match?.[1] || claim?.id;
        if (!id) continue;
        const snapshot = observedClaims.get(id) || claim || {};
        claims.set(id, { ...snapshot, id, label: damageNumber, listVersion: [snapshot.listVersion, entered || text.slice(0, 180)].filter(Boolean).join('|'), enteredAt: entered || snapshot.enteredAt || '' });
      }
      // Some ClaimsForce list variants render claim links without table rows.
      // Collect those links as a fallback so full sync never falls back to
      // the 14 planning tasks.
      for (const anchor of document.querySelectorAll('a[href*="/claims/"]')) {
        const match = String(anchor.getAttribute('href') || '').match(claimPattern);
        if (!match || claims.has(match[1])) continue;
        const row = anchor.closest('tr,[role="row"],article,li');
        const text = (row?.textContent || anchor.textContent || '').replace(/\s+/g, ' ').trim();
        const entered = text.match(/\b\d{2}\.\d{2}\.\d{4}\b/)?.[0] || '';
        if (!include(entered)) continue;
        const damageNumber = extractDamageNumber(text);
        const observed = observedClaims.get(match[1]) || {};
        claims.set(match[1], { ...observed, id: match[1], label: damageNumber || observed.label || String(anchor.textContent || '').replace(/\s+/g, ' ').trim() || text.slice(0, 120), listVersion: [observed.listVersion, entered || text.slice(0, 180)].filter(Boolean).join('|'), enteredAt: entered || observed.enteredAt || '' });
      }
    };
    const setSearchValue = value => {
      const input = [...document.querySelectorAll('input')].find(node => /Schäden durchsuchen/i.test(`${node.getAttribute('placeholder') || ''} ${node.getAttribute('aria-label') || ''}`));
      if (!input) throw new Error('[CF-INVOICED-01] ClaimsForce-Suchfeld für die Einzelfallzuordnung wurde nicht gefunden.');
      const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set;
      if (!setter) throw new Error('[CF-INVOICED-01] ClaimsForce-Suchfeld konnte nicht gesetzt werden.');
      setter.call(input, value);
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
      return input;
    };
    const resolveClaimByDamageNumber = async damageNumber => {
      setSearchValue(damageNumber);
      const key = normalizedDamageNumber(damageNumber);
      for (let attempt = 0; attempt < 40; attempt++) {
        await wait(150);
        const candidates = [...document.querySelectorAll('a[href*="/claims/"]')].filter(anchor => {
          const href = String(anchor.getAttribute('href') || '');
          const label = extractDamageNumber(anchor.innerText || anchor.textContent);
          return claimPattern.test(href) && normalizedDamageNumber(label) === key;
        });
        const matches = new Map();
        for (const anchor of candidates) {
          const id = String(anchor.getAttribute('href') || '').match(claimPattern)?.[1];
          if (id) matches.set(id, anchor);
        }
        if (matches.size > 1) throw new Error(`[CF-INVOICED-01] Die Schadennummer ${damageNumber} ist in der ClaimsForce-Suche nicht eindeutig.`);
        if (matches.size === 1) return { id: [...matches.keys()][0] };
      }
      return null;
    };
    const findListScroller = () => {
      const table = document.querySelector('table');
      for (let node = table?.parentElement; node; node = node.parentElement) {
        if (node.scrollHeight > node.clientHeight + 100 && node.clientHeight > 150) return node;
      }
      return null;
    };
    const nextButton = () => [...document.querySelectorAll('button,a,[role="button"]')].find(node => {
      const text = (node.textContent || '').replace(/\s+/g, ' ').trim();
      const label = `${text} ${node.getAttribute('aria-label') || ''} ${node.getAttribute('title') || ''}`;
      return (/^(Nächste|Weiter|Next|›|>)$/i.test(text) || /Nächste|Weiter|Next|next page/i.test(label)) && !node.disabled && node.getAttribute('aria-disabled') !== 'true';
    });
    (async () => {
      if (location.pathname.replace(/\/+$/, '') !== '/invoiced') throw new Error(`ClaimsForce-Kostennotenliste ist nicht geöffnet (aktuell ${location.pathname || 'unbekannt'}).`);
      // Nach einem Neustart ist die Tabelle erst nach dem Laden der Daten gefüllt.
      for (let waited = 0; waited < 60 && !(document.querySelector('table tbody tr') && /Schäden mit erstellten Kostennoten\s*\(\d+\)/i.test(document.body?.innerText || '')); waited++) await wait(500);
      expectedCount = Number((document.body?.innerText || '').match(/Schäden mit erstellten Kostennoten\s*\((\d+)\)/i)?.[1] || 0);
      if (/Seite ist veraltet/i.test(document.body?.innerText || '')) throw new Error(`[CF-INVOICED-01] Die ClaimsForce-Seite ist veraltet. Bitte den Import erneut starten, damit /invoiced frisch geladen wird (Bridge ${chrome.runtime.getManifest().version}).`);
      let page = 0;
      // Die Zeilendaten der Tabelle enthalten alle Schäden; das Scrollen entfällt (im Hintergrund-Tab rendert die virtualisierte Liste nicht).
      const direct = await requestInvoicedRows(expectedCount);
      const directDiag = `direkt: ok=${!!direct.ok}, Zeilen=${direct.rows?.length || 0}${direct.diag ? ', ' + direct.diag : ''}${direct.error ? ', ' + direct.error : ''}`;
      if (direct.ok && direct.rows.length && (!expectedCount || direct.rows.length >= expectedCount)) {
        for (const row of direct.rows) {
          if (!include(row.enteredAt)) continue;
          claims.set(row.id, { id: row.id, label: row.label, listVersion: row.enteredAt, enteredAt: row.enteredAt });
        }
        sendResponse({ ok: true, claims: [...claims.values()], route: location.pathname, pages: 1, expectedCount, listedCount: direct.rows.length, observedCount: claims.size, searchResolvedCount: 0, since, via: 'zeilendaten', excludedUndated: 0 });
        return;
      }
      const scroller = findListScroller();
      if (scroller) {
        const originalTop = scroller.scrollTop;
        const step = Math.max(120, Math.min(240, Math.floor(scroller.clientHeight / 3)));
        let previousCount = -1;
        let previousHeight = -1;
        let stableScans = 0;
        for (let scan = 0; scan < 6; scan++) {
          if (scan > 0) await wait(700);
          scroller.scrollTop = 0;
          await wait(120);
          for (let index = 0; index < 320; index++) {
            collect();
            if (index > 0 && index % 10 === 0) {
              // Fortschrittsmeldung ist rein informativ; eine ausbleibende Antwort darf den Lesevorgang nicht abbrechen.
              await Promise.race([
                chrome.runtime.sendMessage({
                  type: 'INVOICED_SCRAPE_PROGRESS',
                  current: listedDamageNumbers.size,
                  total: expectedCount || listedDamageNumbers.size,
                  listedCount: listedDamageNumbers.size,
                  observedCount: claims.size,
                  searchResolvedCount: 0
                }).catch(() => null),
                new Promise(resolve => setTimeout(resolve, 2000))
              ]);
            }
            const nextTop = Math.min(scroller.scrollHeight - scroller.clientHeight, scroller.scrollTop + step);
            if (nextTop <= scroller.scrollTop) break;
            scroller.scrollTop = nextTop;
            await wait(140);
          }
          collect();
          const count = listedDamageNumbers.size;
          const height = scroller.scrollHeight;
          if (expectedCount && count >= expectedCount) break;
          stableScans = count === previousCount && height === previousHeight ? stableScans + 1 : 0;
          if (stableScans >= 2) break;
          previousCount = count;
          previousHeight = height;
        }
        scroller.scrollTop = originalTop;
        await wait(80);
      } else {
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
      }
      const observed = observedByDamageNumber();
      const observedCount = claims.size;
      let searchResolvedCount = 0;
      let usedSearch = false;
      for (const [key, listed] of listedDamageNumbers) {
        if ([...claims.values()].some(claim => normalizedDamageNumber(claim.label) === key)) continue;
        const candidates = observed.get(key) || [];
        if (candidates.length === 1) {
          const claim = candidates[0];
          claims.set(claim.id, { ...claim, label: listed.label, enteredAt: listed.enteredAt || claim.enteredAt || '' });
          continue;
        }
        usedSearch = true;
        const resolved = await resolveClaimByDamageNumber(listed.label);
        if (!resolved) throw new Error(`[CF-INVOICED-01] Die ClaimsForce-Suche hat für Schadennummer ${listed.label} keine eindeutige Fall-ID geliefert (Bridge ${chrome.runtime.getManifest().version}; Nummern gelesen ${listedDamageNumbers.size}${expectedCount ? `/${expectedCount}` : ''}; IDs aus Liste/API ${observedCount}; Suchtreffer ${searchResolvedCount}).`);
        claims.set(resolved.id, {
          ...(observedClaims.get(resolved.id) || {}),
          id: resolved.id,
          label: listed.label,
          listVersion: listed.enteredAt || '',
          enteredAt: listed.enteredAt || ''
        });
        searchResolvedCount++;
        if (searchResolvedCount % 10 === 0 || searchResolvedCount === listedDamageNumbers.size) {
          const current = Math.min(listedDamageNumbers.size, observedCount + searchResolvedCount);
          await Promise.race([
            chrome.runtime.sendMessage({
              type: 'INVOICED_SCRAPE_PROGRESS',
              current,
              total: listedDamageNumbers.size,
              listedCount: listedDamageNumbers.size,
              observedCount,
              searchResolvedCount
            }).catch(() => null),
            new Promise(resolve => setTimeout(resolve, 2000))
          ]);
        }
      }
      if (usedSearch) setSearchValue('');
      if (!Number.isFinite(sinceTime) && expectedCount && claims.size < expectedCount) throw new Error(`[CF-INVOICED-01] Kostennotenliste unvollständig: ${claims.size} von ${expectedCount} Schäden konnten zugeordnet werden (Bridge ${chrome.runtime.getManifest().version}; Schadennummern gelesen ${listedDamageNumbers.size}; IDs aus Liste/API ${observedCount}; Suchtreffer ${searchResolvedCount}; ${directDiag}).`);
      sendResponse({ ok: true, claims: [...claims.values()], route: location.pathname, pages: page + 1, expectedCount, listedCount: listedDamageNumbers.size, observedCount, searchResolvedCount, since, excludedUndated: Number.isFinite(sinceTime) ? [...observedClaims.values()].filter(claim => !Number.isFinite(dateTime(claim?.enteredAt || claim?.createdAt || claim?.updatedAt || claim?.date))).length : 0 });
    })().catch(error => sendResponse({ ok: false, error: error.message, claims: [] }));
    return true;
  }
  if (message?.type === 'SCRAPE_CLAIM_BY_DAMAGE_NUMBER') {
    const damageNumber = String(message.damageNumber || '').trim();
    const key = damageNumber.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
    const claimPattern = /\/claims\/([0-9a-f-]{20,})(?:\/|$)/i;
    const damageNumberPattern = /\b[A-Za-z]{1,4}\d[A-Za-z0-9]*(?:[-.][A-Za-z0-9]+)+\b|\b(?:\d{2,3}-)?\d{2,9}(?:-\d{1,9}){1,3}\b|\b\d{2}\.\d{5,}\.\d{1,3}\b|\b\d{8,14}\b/;
    if (!key) {
      sendResponse({ ok: false, error: '[CF-SINGLE-01] Für den Einzelfallimport fehlt die Schadennummer.' });
      return;
    }
    if (location.pathname.replace(/\/+$/, '') === '/login') {
      sendResponse({ ok: false, error: '[CF-SINGLE-01] ClaimsForce ist nicht angemeldet; die globale Schadensuche ist nicht verfügbar.' });
      return;
    }
    const input = [...document.querySelectorAll('input')].find(node => /Schäden durchsuchen/i.test(`${node.getAttribute('placeholder') || ''} ${node.getAttribute('aria-label') || ''}`));
    if (!input) {
      sendResponse({ ok: false, error: '[CF-SINGLE-01] ClaimsForce-Suchfeld für die Schadennummer wurde nicht gefunden.' });
      return;
    }
    const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set;
    if (!setter) {
      sendResponse({ ok: false, error: '[CF-SINGLE-01] ClaimsForce-Suchfeld konnte nicht gesetzt werden.' });
      return;
    }
    setter.call(input, damageNumber);
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
    (async () => {
      const matches = new Map();
      for (let attempt = 0; attempt < 40; attempt++) {
        await new Promise(resolve => setTimeout(resolve, 150));
        for (const anchor of document.querySelectorAll('a[href*="/claims/"]')) {
          const id = String(anchor.getAttribute('href') || '').match(claimPattern)?.[1];
          const row = anchor.closest('tr,[role="row"],article,li');
          const labels = [anchor.innerText || anchor.textContent || '', anchor.getAttribute('aria-label') || '', row?.innerText || row?.textContent || ''];
          const exact = labels.some(label => (String(label).match(damageNumberPattern)?.[0] || '').replace(/[^A-Za-z0-9]/g, '').toUpperCase() === key);
          if (id && exact) matches.set(id, anchor);
        }
        if (matches.size > 1) throw new Error(`[CF-SINGLE-01] Die Schadennummer ${damageNumber} ist in ClaimsForce nicht eindeutig.`);
        if (matches.size === 1) {
          const id = [...matches.keys()][0];
          sendResponse({ ok: true, route: location.pathname, claims: [{ id, label: damageNumber }], listedCount: 1 });
          return;
        }
      }
      throw new Error(`[CF-SINGLE-01] Schadennummer ${damageNumber} wurde über die ClaimsForce-Suche nicht gefunden. Die Suche muss den Fall exakt anzeigen.`);
    })().catch(error => sendResponse({ ok: false, error: error.message }));
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
