(() => {
  const link = document.getElementById('intern-offer-create-link');
  if (!link) return;
  // Fail closed; a cached browser profile must never grant offer access.
  link.hidden = true;
  fetch('/intern/api/private-business.php?action=access', {
    credentials: 'same-origin', cache: 'no-store',
  }).then(async (response) => {
    if (!response.ok) return;
    const data = await response.json();
    link.hidden = !(data.ok === true && data.can_create === true);
  }).catch(() => { link.hidden = true; });
})();
