(() => {
  const seenClaims = new Map();
  const publish = value => {
    const token = String(value || '').replace(/^Bearer\s+/i, '').trim();
    if (token.length > 40) window.postMessage({ source: 'svnet-claimsforce-main', type: 'TOKEN', token }, location.origin);
  };
  const authByOrigin = new Map();
  const authCaptureCounts = new Map();
  const headerValue = (headers, name) => {
    try {
      if (!headers) return '';
      if (headers instanceof Headers) return headers.get(name) || '';
      if (Array.isArray(headers)) return (headers.find(([key]) => String(key).toLowerCase() === name) || [])[1] || '';
      return Object.entries(headers).find(([key]) => String(key).toLowerCase() === name)?.[1] || '';
    } catch { return ''; }
  };
  const rememberAuthorization = (url, value) => {
    try {
      const origin = new URL(String(url), location.href).origin;
      if (value) {
        authByOrigin.set(origin, String(value));
        authCaptureCounts.set(origin, (authCaptureCounts.get(origin) || 0) + 1);
      }
    } catch {}
  };
  const inspect = headers => {
    try {
      if (headers instanceof Headers) publish(headers.get('authorization'));
      else if (Array.isArray(headers)) headers.forEach(([key, value]) => String(key).toLowerCase() === 'authorization' && publish(value));
      else if (headers && typeof headers === 'object') Object.entries(headers).forEach(([key, value]) => String(key).toLowerCase() === 'authorization' && publish(value));
    } catch {}
  };
  const inspectTokenCache = (value, key = '', depth = 0) => {
    if (depth > 6 || value == null) return;
    if (typeof value === 'string') {
      if (/^(access_token|accessToken|token)$/i.test(key)) publish(value);
      if (/^[{[]/.test(value.trim())) {
        try { inspectTokenCache(JSON.parse(value), key, depth + 1); } catch {}
      }
      return;
    }
    if (Array.isArray(value)) {
      value.forEach(entry => inspectTokenCache(entry, key, depth + 1));
      return;
    }
    if (typeof value === 'object') {
      Object.entries(value).forEach(([entryKey, entry]) => inspectTokenCache(entry, entryKey, depth + 1));
    }
  };
  const inspectStorage = storage => {
    try {
      for (let index = 0; index < storage.length; index++) {
        const key = storage.key(index);
        inspectTokenCache(storage.getItem(key), key || '', 0);
      }
    } catch {}
  };
  const claimId = value => /^[0-9a-f]{8}-[0-9a-f-]{20,}$/i.test(String(value || '')) ? String(value) : '';
  const listVersion = value => [
    value.updatedAt, value.modifiedAt, value.lastModifiedAt, value.version,
    value.appointments?.nextAppointment?.id,
    value.appointments?.nextAppointment?.updatedAt,
    value.appointments?.nextAppointment?.startDate
  ].map(entry => String(entry || '')).filter(Boolean).join('|');
  const inspectClaims = (value, planningContext = false, depth = 0, invoicedContext = false) => {
    if (!value || depth > 7) return;
    if (Array.isArray(value)) {
      value.forEach(entry => inspectClaims(entry, planningContext, depth + 1, invoicedContext));
      return;
    }
    if (typeof value !== 'object') return;
    const explicitClaimId = claimId(value.claimId) || claimId(value.claim?.id);
    const id = explicitClaimId || (!invoicedContext || value.claimType || value.bucket || value.appointments || value.actualAppointmentLocation ? claimId(value.id) : '');
    const insurerClaimId = value.insurerClaimId || value.data?.insurerClaimId || value.claimNumber || value.claim?.insurerClaimId || value.claim?.claimNumber || '';
    const hasClaimShape = insurerClaimId || value.claimType || value.bucket || value.appointments || value.actualAppointmentLocation;
    const supportedBucket = ['WITH_FUTURE_APPOINTMENT', 'WITHOUT_APPOINTMENT'].includes(String(value.bucket || '').toUpperCase());
    const visiblePlanningClaim = planningContext || supportedBucket || !!value.appointments?.nextAppointment;
    if (id && hasClaimShape && (visiblePlanningClaim || invoicedContext && insurerClaimId)) {
      const previous = seenClaims.get(id) || {};
      seenClaims.set(id, {
        id,
        label: String(insurerClaimId || previous.label || '').slice(0, 100),
        listVersion: listVersion(value) || previous.listVersion || '',
        createdAt: String(value.createdAt || value.created || previous.createdAt || '')
      });
    }
    Object.values(value).forEach(entry => inspectClaims(entry, planningContext, depth + 1, invoicedContext));
  };
  const planningRequest = (input, init) => {
    const url = String(input instanceof Request ? input.url : input || '');
    const body = typeof init?.body === 'string' ? init.body : '';
    return /futureAppointment|withoutAppointment|WITH_FUTURE_APPOINTMENT|WITHOUT_APPOINTMENT|with-future-appointment|without-appointment/i.test(`${url} ${body}`);
  };
  const inspectResponse = async (response, input, init) => {
    try {
      const url = String(response?.url || (input instanceof Request ? input.url : input) || '');
      const invoicedContext = location.pathname.replace(/\/+$/, '') === '/invoiced';
      if (!(/\/claims(?:[/?]|$)/i.test(url) || invoicedContext) || !String(response.headers.get('content-type') || '').includes('json')) return;
      inspectClaims(await response.clone().json(), planningRequest(input, init), 0, invoicedContext);
      if (seenClaims.size) window.postMessage({ source: 'svnet-claimsforce-main', type: 'CLAIMS_SNAPSHOT', claims: [...seenClaims.values()] }, location.origin);
    } catch {}
  };
  const originalFetch = window.fetch;
  window.fetch = function(input, init) {
    inspect(init && init.headers);
    try { if (input instanceof Request) inspect(input.headers); } catch {}
    try { rememberAuthorization(input instanceof Request ? input.url : input, headerValue(init && init.headers, 'authorization') || (input instanceof Request ? input.headers.get('authorization') : '')); } catch {}
    const result = originalFetch.apply(this, arguments);
    result.then(response => inspectResponse(response, input, init)).catch(() => {});
    return result;
  };
  const originalOpen = XMLHttpRequest.prototype.open;
  XMLHttpRequest.prototype.open = function(_method, url) {
    this.__svnetUrl = String(url || '');
    return originalOpen.apply(this, arguments);
  };
  const originalSetHeader = XMLHttpRequest.prototype.setRequestHeader;
  XMLHttpRequest.prototype.setRequestHeader = function(name, value) {
    if (String(name).toLowerCase() === 'authorization') { publish(value); rememberAuthorization(this.__svnetUrl, value); }
    return originalSetHeader.apply(this, arguments);
  };
  const originalSend = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.send = function(body) {
    const planning = planningRequest(this.__svnetUrl, { body });
    this.addEventListener('load', () => {
      try {
        const invoicedContext = location.pathname.replace(/\/+$/, '') === '/invoiced';
        if ((/\/claims(?:[/?]|$)/i.test(this.__svnetUrl || '') || invoicedContext) && typeof this.responseText === 'string') {
          inspectClaims(JSON.parse(this.responseText), planning, 0, invoicedContext);
          if (seenClaims.size) window.postMessage({ source: 'svnet-claimsforce-main', type: 'CLAIMS_SNAPSHOT', claims: [...seenClaims.values()] }, location.origin);
        }
      } catch {}
    }, { once: true });
    return originalSend.apply(this, arguments);
  };
  window.addEventListener('message', async event => {
    const data = event.data;
    if (event.source !== window || data?.source !== 'svnet-claimsforce-bridge' || data.type !== 'INVESTIGATIONS_REQUEST') return;
    const reply = payload => window.postMessage({ source: 'svnet-claimsforce-main', type: 'INVESTIGATIONS_RESPONSE', id: data.id, ...payload }, location.origin);
    try {
      const endpoint = String(data.endpoint || '').replace(/\/+$/, '');
      const authorization = authByOrigin.get(new URL(endpoint).origin) || '';
      if (!authorization) {
        reply({
          ok: false,
          status: 0,
          hasAuth: false,
          body: `kein Seitentoken; route=${location.pathname}; authOrigins=${[...authCaptureCounts.keys()].join('|') || 'keine'}`
        });
        return;
      }
      const response = await originalFetch.call(window, `${endpoint}/investigation-list`, {
        method: 'POST', mode: 'cors',
        headers: { 'Content-Type': 'application/json; charset=UTF-8', Authorization: authorization },
        body: JSON.stringify({ queries: data.queries, countsOnly: false })
      });
      reply({ ok: response.ok, status: response.status, hasAuth: true, body: response.ok ? await response.json().catch(() => null) : (await response.text().catch(() => '')).slice(0, 120) });
    } catch (error) { reply({ ok: false, status: 0, hasAuth: true, body: String(error?.message || error).slice(0, 120) }); }
  });
  window.addEventListener('message', async event => {
    const data = event.data;
    if (event.source !== window || data?.source !== 'svnet-claimsforce-bridge' || data.type !== 'INVOICED_ROWS_REQUEST') return;
    const reply = payload => window.postMessage({ source: 'svnet-claimsforce-main', type: 'INVOICED_ROWS_RESPONSE', id: data.id, ...payload }, location.origin);
    try {
      const row = document.querySelector('table tbody tr');
      const fiberKey = row && Object.keys(row).find(key => key.startsWith('__reactFiber'));
      let fiber = fiberKey ? row[fiberKey] : null;
      let table = null;
      for (let depth = 0; depth < 40 && fiber; depth++, fiber = fiber.return) {
        if (typeof fiber.memoizedProps?.table?.getCoreRowModel === 'function') { table = fiber.memoizedProps.table; break; }
      }
      if (!table) { reply({ ok: false, rows: [], diag: 'keine-tabelle' }); return; }
      const readRows = () => table.getCoreRowModel().rows.map(entry => entry.original);
      let originals = readRows();
      const expected = Number(data.expected || 0);
      if (expected && originals.length < expected) {
        // Die Liste lädt beim Scrollen nach; der Container wird direkt ans Ende gesetzt, bis alle Zeilen im Modell liegen.
        let scroller = null;
        for (let node = document.querySelector('table')?.parentElement; node; node = node.parentElement) if (node.scrollHeight > node.clientHeight + 100 && node.clientHeight > 150) { scroller = node; break; }
        let stable = 0, last = originals.length;
        for (let i = 0; i < 120 && originals.length < expected && stable < 12; i++) {
          if (scroller) scroller.scrollTop = scroller.scrollHeight;
          document.querySelector('table tbody tr:last-child')?.scrollIntoView?.({ block: 'end' });
          await new Promise(resolve => setTimeout(resolve, 500));
          originals = readRows();
          stable = originals.length === last ? stable + 1 : 0;
          last = originals.length;
        }
        if (scroller) scroller.scrollTop = 0;
      }
      const rows = originals.map(original => {
        const id = claimId(original?.claimId) || claimId(original?.claim?.id) || claimId(original?.id);
        const label = String(original?.claim?.insurerClaimId || original?.claim?.tpaClaimId || '').slice(0, 100);
        const invoiced = new Date(original?.invoicedAt || '');
        const enteredAt = Number.isNaN(invoiced.getTime()) ? '' : invoiced.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
        return { id, label, enteredAt };
      }).filter(entry => entry.id && entry.label);
      reply({ ok: true, rows, modelCount: originals.length });
    } catch (error) { reply({ ok: false, rows: [], error: String(error?.message || error).slice(0, 120) }); }
  });
  inspectStorage(localStorage);
  inspectStorage(sessionStorage);
  addEventListener('storage', event => inspectTokenCache(event.newValue, event.key || '', 0));
  setTimeout(() => { inspectStorage(localStorage); inspectStorage(sessionStorage); }, 1200);
})();
