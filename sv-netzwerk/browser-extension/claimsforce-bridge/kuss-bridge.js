// KUSS uses the already authenticated browser session. No API token is read or stored.
const jobs = new Map();
const collect = () => {
  for (const anchor of document.querySelectorAll('a[href*="/contractor/jobs/"]')) {
    const href = anchor.href;
    const match = href.match(/\/contractor\/jobs\/([^/?#]+)/i);
    if (!match) continue;
    const row = anchor.closest('tr,[role="row"],li,article') || anchor.parentElement;
    const text = (row?.textContent || anchor.textContent || '').replace(/\s+/g, ' ').trim();
    jobs.set(match[1], { id: match[1], url: href, title: (anchor.textContent || '').replace(/\s+/g, ' ').trim(), text, rv: /R\s*\+\s*V|R\&V|ruv/i.test(text) });
  }
  for (const row of document.querySelectorAll('table tbody tr,[role="row"]')) {
    const text = (row.textContent || '').replace(/\s+/g, ' ').trim();
    const id = (text.match(/\b\d{2,4}-\d{2,4}-\d{6,10}-\d\b/) || [])[0];
    if (!id) continue;
    const link = row.querySelector('a[href]');
    const href = link?.href || '';
    const rv = !!row.querySelector('img[alt*="r"]') || /R\s*\+\s*V|R\&V|Die Regulierer/i.test(text);
    jobs.set(id, { id, url: href, title: id, text, rv });
  }
};
const nextPage = () => [...document.querySelectorAll('button,a,[role="button"]')].find(node => /^(Nächste|Next|›|>)$/i.test((node.textContent || '').replace(/\s+/g, ' ').trim()) && !node.disabled && node.getAttribute('aria-disabled') !== 'true');
chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  if (message?.type !== 'KUSS_SCAN_JOBS') return;
  (async()=>{
    collect();
    if (message.all) for (let page = 1; page < 530; page++) {
      const next = nextPage();
      if (!next) break;
      next.click();
      await new Promise(resolve => setTimeout(resolve, 300));
      collect();
    }
    sendResponse({ ok: true, route: location.pathname, authenticated: !/login|signin/i.test(location.pathname), jobs: [...jobs.values()] });
  })().catch(error=>sendResponse({ok:false,error:error.message}));
  return true;
});
collect();
