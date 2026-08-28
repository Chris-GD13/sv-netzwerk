(() => {
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
  const originalFetch = window.fetch;
  window.fetch = function(input, init) {
    inspect(init && init.headers);
    try { if (input instanceof Request) inspect(input.headers); } catch {}
    return originalFetch.apply(this, arguments);
  };
  const originalSetHeader = XMLHttpRequest.prototype.setRequestHeader;
  XMLHttpRequest.prototype.setRequestHeader = function(name, value) {
    if (String(name).toLowerCase() === 'authorization') publish(value);
    return originalSetHeader.apply(this, arguments);
  };
})();
