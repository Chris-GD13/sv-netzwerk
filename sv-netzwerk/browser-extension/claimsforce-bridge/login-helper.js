let suppliedCredentials = null;
let submitLoop = 0;
let submissionAttempted = false;
let loginObserver = null;

function submitWhenReady() {
  if (submitLoop || submissionAttempted) return;
  let attempts = 0;
  const tick = () => {
    submitLoop = 0;
    if (submissionAttempted) return;
    const password = document.querySelector('input[type="password"]');
    const form = password?.closest('form');
    const button = form?.querySelector('button[type="submit"]') || [...document.querySelectorAll('button')].find(node => /^Anmelden$/i.test((node.textContent || '').trim()));
    if (!password || !button) return;
    const ready = button && !button.disabled && button.getAttribute('aria-disabled') !== 'true';
    if (ready) {
      submissionAttempted = true;
      button.click();
      return;
    }
    attempts++;
    if (attempts < 30 && document.contains(password)) submitLoop = setTimeout(tick, 350);
  };
  submitLoop = setTimeout(tick, 300);
}

async function fillLogin(credentials = suppliedCredentials) {
  const email = document.querySelector('input[type="email"],input[inputmode="email"],input[name="email"],input[name="username"],input[autocomplete="username"]');
  const password = document.querySelector('input[type="password"]');
  if (!email || !password) return false;
  const response = credentials;
  if (!response?.email || !response?.password) return false;
  const set = (node, value) => {
    const previous = node.value;
    const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set;
    node.focus();
    setter?.call(node, value);
    node._valueTracker?.setValue(previous);
    node.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertText', data: value }));
    node.dispatchEvent(new Event('change', { bubbles: true }));
    node.blur();
  };
  set(email, response.email); set(password, response.password);
  submitWhenReady();
  return true;
}

function observeRequestedLogin() {
  if (loginObserver || !document.documentElement) return;
  loginObserver = new MutationObserver(() => fillLogin(suppliedCredentials));
  loginObserver.observe(document.documentElement, { childList: true, subtree: true });
}

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  if (message?.type !== 'FILL_LOGIN') return;
  suppliedCredentials = message.credentials || null;
  observeRequestedLogin();
  fillLogin(suppliedCredentials).then(ready => sendResponse({ ok: !!ready })).catch(() => sendResponse({ ok: false }));
  return true;
});
