import { saveCredentials, loadCredentials, clearCredentials } from './vault.js';
const profile = document.querySelector('#profile'), email = document.querySelector('#email'), password = document.querySelector('#password'), state = document.querySelector('#state');
const active = (await chrome.storage.session.get('activeProfile')).activeProfile;
if (active && [...profile.options].some(option => option.value === active)) profile.value = active;
let currentCredentials = null;
async function showProfile() { currentCredentials = await loadCredentials(profile.value); email.value = currentCredentials?.email || ''; password.value = ''; password.placeholder = currentCredentials?.password ? 'Kennwort ist verschlüsselt gespeichert' : ''; state.textContent = currentCredentials?.email && currentCredentials?.password ? 'E-Mail-Adresse und Kennwort sind für diese Anmeldung gespeichert.' : 'Für diese Anmeldung sind noch keine vollständigen Zugangsdaten gespeichert.'; state.className = currentCredentials?.email && currentCredentials?.password ? 'ok' : ''; }
profile.addEventListener('change', showProfile);
await showProfile();
document.querySelector('#form').addEventListener('submit', async event => {
  event.preventDefault();
  const savedPassword = password.value || currentCredentials?.password || '';
  if (!savedPassword) { state.textContent = 'Bitte das ClaimsForce-Kennwort eingeben.'; state.className = ''; return; }
  await saveCredentials(profile.value, { email: email.value.trim(), password: savedPassword });
  currentCredentials = { email: email.value.trim(), password: savedPassword };
  password.value = '';
  password.placeholder = 'Kennwort ist verschlüsselt gespeichert';
  state.textContent = 'E-Mail-Adresse und Kennwort wurden verschlüsselt gespeichert.';
  state.className = 'ok';
});
document.querySelector('#remove').addEventListener('click', async () => {
  await clearCredentials(profile.value); currentCredentials = null; email.value = ''; password.value = ''; password.placeholder = ''; state.textContent = 'Gespeicherte Daten wurden gelöscht.'; state.className = '';
});
