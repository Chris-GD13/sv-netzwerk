import { saveCredentials, loadCredentials, clearCredentials, savePortalCredentials, loadPortalCredentials, clearPortalCredentials } from './vault.js';
const OPTIONS_CODE_VERSION = '1.3.0';
if (localStorage.getItem('svnet-bridge-options-code-version') !== OPTIONS_CODE_VERSION) {
  localStorage.setItem('svnet-bridge-options-code-version', OPTIONS_CODE_VERSION);
  setTimeout(() => chrome.runtime.reload(), 250);
}
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

const portalEmail = document.querySelector('#portal-email'), portalPassword = document.querySelector('#portal-password'), portalState = document.querySelector('#portal-state');
let portalCredentials = await loadPortalCredentials();
portalEmail.value = portalCredentials?.email || '';
portalPassword.placeholder = portalCredentials?.password ? 'Kennwort ist verschlüsselt gespeichert' : '';
portalState.textContent = portalCredentials?.email && portalCredentials?.password ? 'Die automatische Prüfportal-Anmeldung ist eingerichtet.' : 'Die automatische Prüfportal-Anmeldung ist noch nicht vollständig eingerichtet.';
portalState.className = portalCredentials?.email && portalCredentials?.password ? 'ok' : '';
document.querySelector('#portal-form').addEventListener('submit', async event => {
  event.preventDefault();
  const savedPassword = portalPassword.value || portalCredentials?.password || '';
  if (!savedPassword) { portalState.textContent = 'Bitte das SV-Netzwerk-Kennwort eingeben.'; portalState.className = ''; return; }
  await savePortalCredentials({ email: portalEmail.value.trim(), password: savedPassword });
  portalCredentials = { email: portalEmail.value.trim(), password: savedPassword };
  portalPassword.value = '';
  portalPassword.placeholder = 'Kennwort ist verschlüsselt gespeichert';
  portalState.textContent = 'Der Portal-Zugang wurde verschlüsselt gespeichert. Die Anmeldung erfolgt künftig automatisch.';
  portalState.className = 'ok';
});
document.querySelector('#portal-remove').addEventListener('click', async () => {
  await clearPortalCredentials(); portalCredentials = null; portalEmail.value = ''; portalPassword.value = ''; portalPassword.placeholder = ''; portalState.textContent = 'Der gespeicherte Portal-Zugang wurde gelöscht.'; portalState.className = '';
});
