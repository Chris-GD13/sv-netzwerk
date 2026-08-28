let attempted = false;
const mark = phase => document.documentElement?.setAttribute('data-svnet-portal-login', phase);

async function fillPortalLogin() {
  if (attempted) return;
  const email = document.querySelector('#login-email,input[name="email"]');
  const password = document.querySelector('#login-password,input[name="password"]');
  const form = document.querySelector('#intern-login-form') || password?.closest('form');
  if (!email || !password || !form) { mark('waiting-form'); return; }
  const submit = () => setTimeout(() => form.querySelector('button[type="submit"]')?.click() || form.requestSubmit?.(), 250);
  if (email.value && password.value) {
    attempted = true;
    submit();
    return;
  }
  mark('requesting-credentials');
  const credentials = await chrome.runtime.sendMessage({ type: 'GET_PORTAL_CREDENTIALS' }).catch(() => null);
  if (!credentials?.email || !credentials?.password) {
    const status = await chrome.runtime.sendMessage({ type: 'GET_CREDENTIAL_DIAGNOSTIC' }).catch(() => null);
    mark(`credentials-unavailable-${status?.phase || 'unknown'}`);
    return;
  }
  attempted = true;
  const set = (node, value) => {
    const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set;
    setter?.call(node, value);
    node.dispatchEvent(new Event('input', { bubbles: true }));
    node.dispatchEvent(new Event('change', { bubbles: true }));
  };
  set(email, credentials.email);
  set(password, credentials.password);
  mark('submitted');
  submit();
}

function watchPortalLogin() {
  fillPortalLogin();
  if (!document.documentElement) { setTimeout(watchPortalLogin, 50); return; }
  mark('observer-ready');
  new MutationObserver(fillPortalLogin).observe(document.documentElement, { childList: true, subtree: true });
}
watchPortalLogin();
