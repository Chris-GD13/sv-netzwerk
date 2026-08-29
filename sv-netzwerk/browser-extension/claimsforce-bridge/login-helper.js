let suppliedCredentials = null;

async function fillLogin(credentials = suppliedCredentials) {
  const email = document.querySelector('input[type="email"],input[name="email"],input[name="username"]');
  const password = document.querySelector('input[type="password"]');
  if (!email || !password) return;
  const submit = () => {
    const submittedAt = Number(password.dataset.svnetSubmittedAt || 0);
    if (Date.now() - submittedAt < 3000) return;
    password.dataset.svnetSubmittedAt = String(Date.now());
    const form = password.closest('form');
    const button = form?.querySelector('button[type="submit"]') || [...document.querySelectorAll('button')].find(node => /^Anmelden$/i.test((node.textContent || '').trim()));
    if (button) button.click();
    else form?.requestSubmit?.();
  };
  const response = credentials || await chrome.runtime.sendMessage({ type: 'GET_CREDENTIALS' }).catch(() => null);
  if (!response?.email || !response?.password) {
    if (email.value && password.value) setTimeout(submit, 250);
    return;
  }
  const set = (node, value) => { const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set; setter?.call(node, value); node.dispatchEvent(new Event('input', { bubbles: true })); node.dispatchEvent(new Event('change', { bubbles: true })); };
  set(email, response.email); set(password, response.password);
  setTimeout(submit, 250);
}
fillLogin();
new MutationObserver(fillLogin).observe(document.documentElement, { childList: true, subtree: true });
chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  if (message?.type !== 'FILL_LOGIN') return;
  suppliedCredentials = message.credentials || null;
  fillLogin(suppliedCredentials).then(() => sendResponse({ ok: true })).catch(() => sendResponse({ ok: false }));
  return true;
});
