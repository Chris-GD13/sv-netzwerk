(() => {
  const seenClaims = new Map();
  const publish = value => {
    const token = String(value || '').replace(/^Bearer\s+/i, '').trim();
    if (token.length > 40) window.postMessage({ source: 'svnet-claimsforce-main', type: 'TOKEN', token }, location.origin);
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
  const inspectClaims = (value, futureContext = false, depth = 0) => {
    if (!value || depth > 7) return;
    if (Array.isArray(value)) {
      value.forEach(entry => inspectClaims(entry, futureContext, depth + 1));
      return;
    }
    if (typeof value !== 'object') return;
    const id = claimId(value.id || value.claimId);
    const insurerClaimId = value.insurerClaimId || value.data?.insurerClaimId || value.claimNumber || '';
    const hasClaimShape = insurerClaimId || value.claimType || value.bucket || value.appointments || value.actualAppointmentLocation;
    const future = futureContext || value.bucket === 'WITH_FUTURE_APPOINTMENT' || !!value.appointments?.nextAppointment;
    if (id && hasClaimShape && future) {
      const previous = seenClaims.get(id) || {};
      seenClaims.set(id, {
        id,
        label: String(insurerClaimId || previous.label || '').slice(0, 100),
        listVersion: listVersion(value) || previous.listVersion || ''
      });
    }
    Object.values(value).forEach(entry => inspectClaims(entry, futureContext, depth + 1));
  };
  const futureRequest = (input, init) => {
    const url = String(input instanceof Request ? input.url : input || '');
    const body = typeof init?.body === 'string' ? init.body : '';
    return /futureAppointment|WITH_FUTURE_APPOINTMENT|with-future-appointment/i.test(`${url} ${body}`);
  };
  const inspectResponse = async (response, input, init) => {
    try {
      const url = String(response?.url || (input instanceof Request ? input.url : input) || '');
      if (!/\/claims(?:[/?]|$)/i.test(url) || !String(response.headers.get('content-type') || '').includes('json')) return;
      inspectClaims(await response.clone().json(), futureRequest(input, init));
      if (seenClaims.size) window.postMessage({ source: 'svnet-claimsforce-main', type: 'CLAIMS_SNAPSHOT', claims: [...seenClaims.values()] }, location.origin);
    } catch {}
  };
  const originalFetch = window.fetch;
  window.fetch = function(input, init) {
    inspect(init && init.headers);
    try { if (input instanceof Request) inspect(input.headers); } catch {}
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
    if (String(name).toLowerCase() === 'authorization') publish(value);
    return originalSetHeader.apply(this, arguments);
  };
  const originalSend = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.send = function(body) {
    const future = futureRequest(this.__svnetUrl, { body });
    this.addEventListener('load', () => {
      try {
        if (/\/claims(?:[/?]|$)/i.test(this.__svnetUrl || '') && typeof this.responseText === 'string') {
          inspectClaims(JSON.parse(this.responseText), future);
          if (seenClaims.size) window.postMessage({ source: 'svnet-claimsforce-main', type: 'CLAIMS_SNAPSHOT', claims: [...seenClaims.values()] }, location.origin);
        }
      } catch {}
    }, { once: true });
    return originalSend.apply(this, arguments);
  };
  inspectStorage(localStorage);
  inspectStorage(sessionStorage);
  addEventListener('storage', event => inspectTokenCache(event.newValue, event.key || '', 0));
  setTimeout(() => { inspectStorage(localStorage); inspectStorage(sessionStorage); }, 1200);
})();
