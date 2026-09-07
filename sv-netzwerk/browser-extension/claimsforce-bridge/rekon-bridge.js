window.addEventListener('message', event => {
  if (event.source !== window || event.origin !== location.origin || event.data?.source !== 'svnet-rekon-main') return;
  if (event.data?.type === 'TOKEN') {
    try { chrome.runtime.sendMessage({ type: 'REKON_TOKEN', token: event.data.token }).catch(() => {}); } catch {}
  }
});
chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  if (message?.type !== 'REKON_SESSION_STATE') return;
  const text = (document.body?.innerText || '').replace(/\s+/g, ' ');
  const identity = (text.match(/(?:Schütt\s+Marc|Marc\s+Schütt|Holger\s+Roth|Roth\s+Holger)(?:\s*\(SV\))?/i) || [])[0] || '';
  sendResponse({ ok: !location.pathname.startsWith('/login'), route: location.pathname, identity });
});
