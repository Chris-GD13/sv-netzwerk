(() => {
  const links = document.querySelectorAll('[data-energy-access]');
  if (!links.length) return;
  fetch('/intern/api/energy.php?action=access', { credentials: 'same-origin', cache: 'no-store' })
    .then(async response => response.ok ? response.json() : null)
    .then(data => { if (data?.ok === true && data.visible === true) links.forEach(link => { link.hidden = false; }); })
    .catch(() => {});
})();
