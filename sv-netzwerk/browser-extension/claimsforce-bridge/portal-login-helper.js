let attempted = false;

async function fillPortalLogin() {
  if (attempted) return;
  const email = document.querySelector('#login-email,input[name="email"]');
  const password = document.querySelector('#login-password,input[name="password"]');
  const form = document.querySelector('#intern-login-form') || password?.closest('form');
  if (!email || !password || !form) return;
  const credentials = await chrome.runtime.sendMessage({ type: 'GET_PORTAL_CREDENTIALS' }).catch(() => null);
  if (!credentials?.email || !credentials?.password) return;
  attempted = true;
  const set = (node, value) => {
    const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set;
    setter?.call(node, value);
    node.dispatchEvent(new Event('input', { bubbles: true }));
    node.dispatchEvent(new Event('change', { bubbles: true }));
  };
  set(email, credentials.email);
  set(password, credentials.password);
  setTimeout(() => form.requestSubmit?.() || form.querySelector('button[type="submit"]')?.click(), 250);
}

fillPortalLogin();
new MutationObserver(fillPortalLogin).observe(document.documentElement, { childList: true, subtree: true });
