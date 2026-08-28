window.addEventListener('message', event => {
  if (event.source !== window || event.origin !== location.origin || event.data?.source !== 'svnet-claimsforce-main' || event.data?.type !== 'TOKEN') return;
  chrome.runtime.sendMessage({ type: 'CLAIMS_TOKEN', token: event.data.token }).catch(() => {});
});

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  if (message?.type !== 'SCRAPE_CLAIMS') return;
  const claims = new Map();
  const collect = () => document.querySelectorAll('a[href*="/claims/"],a[href*="/planning/"]').forEach(anchor => {
    const match = anchor.getAttribute('href')?.match(/\/(?:claims|planning)\/([0-9a-f-]{20,})(?:\/|$)/i);
    if (match) claims.set(match[1], { id: match[1], label: anchor.textContent?.trim() || '' });
  });
  (async () => {
    collect();
    const days = [...document.querySelectorAll('[role="tabpanel"] button')].map(button => (button.textContent || '').trim()).filter(label => /\b20\d{2}\b/.test(label));
    for (const label of days) {
      const day = [...document.querySelectorAll('[role="tabpanel"] button')].find(button => (button.textContent || '').trim() === label);
      day?.click();
      await new Promise(resolve => setTimeout(resolve, 260));
      collect();
    }
    sendResponse({ ok: true, claims: [...claims.values()], url: location.href });
  })().catch(error => sendResponse({ ok: false, error: error.message, claims: [] }));
  return true;
});
