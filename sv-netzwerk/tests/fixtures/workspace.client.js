// This journal is deliberately DOM-visible, so browser QA does not inspect private runtime state.
(() => {
  const request = window.fetch.bind(window), calls = [];
  window.fetch = async (url, options) => {
    let action = '';
    try { action = JSON.parse(options?.body || '{}').action || ''; } catch {}
    calls.push(action || String(url));
    document.getElementById('fixture-requests').textContent = calls.join(' · ');
    const response=await request(url, options);
    if(String(url).includes('/case-file-browser.php?folder_id=fixture-a'))await new Promise(r=>setTimeout(r,250));
    return response;
  };
  const open = (folder, number) => {
    window.dispatchEvent(new CustomEvent('svnet:case-cleared'));
    const row = { _loaded: true, folder_id: folder, meta: { schaden_nr: number, vn_objekt: 'Synthetisches Testobjekt', versicherer: 'Testversicherer', sparte: 'Gebäude' } };
    window.svnetOpenedClaimsCase = row;
    document.getElementById('vf-active').textContent = number + ' · Synthetisches Testobjekt';
    document.getElementById('vf-form').hidden = false;
    window.dispatchEvent(new CustomEvent('svnet:case-opened', { detail: row }));
  };
  document.getElementById('fixture-a').onclick = () => open('fixture-a', 'TEST-A-001');
  document.getElementById('fixture-b').onclick = () => open('fixture-b', 'TEST-B-002');
  document.getElementById('fixture-clear').onclick = () => {
    window.svnetOpenedClaimsCase = null;
    window.dispatchEvent(new CustomEvent('svnet:case-cleared'));
    document.getElementById('vf-active').textContent = 'Kein Fall geöffnet';
  };
})();
