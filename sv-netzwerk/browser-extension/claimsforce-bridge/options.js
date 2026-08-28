import { saveCredentials, loadCredentials, clearCredentials } from './vault.js';
const profile = document.querySelector('#profile'), email = document.querySelector('#email'), password = document.querySelector('#password'), state = document.querySelector('#state');
const active = (await chrome.storage.session.get('activeProfile')).activeProfile;
if (active && [...profile.options].some(option => option.value === active)) profile.value = active;
async function showProfile() { const current = await loadCredentials(profile.value); email.value = current?.email || ''; password.value = ''; state.textContent = current?.email ? 'Zugangsdaten sind für diese Anmeldung gespeichert.' : 'Für diese Anmeldung sind noch keine Zugangsdaten gespeichert.'; state.className = current?.email ? 'ok' : ''; }
profile.addEventListener('change', showProfile);
await showProfile();
document.querySelector('#form').addEventListener('submit', async event => {
  event.preventDefault();
  await saveCredentials(profile.value, { email: email.value.trim(), password: password.value });
  password.value = '';
  state.textContent = 'Zugangsdaten wurden verschlüsselt gespeichert.';
  state.className = 'ok';
});
document.querySelector('#remove').addEventListener('click', async () => {
  await clearCredentials(profile.value); email.value = ''; password.value = ''; state.textContent = 'Gespeicherte Zugangsdaten wurden gelöscht.'; state.className = '';
});
