async function fillLogin() {
  const email = document.querySelector('input[type="email"],input[name="email"],input[name="username"]');
  const password = document.querySelector('input[type="password"]');
  if (!email || !password || password.dataset.svnetFilled) return;
  const submit = () => {
    if (password.dataset.svnetSubmitted) return;
    password.dataset.svnetSubmitted = '1';
    const form = password.closest('form');
    const button = form?.querySelector('button[type="submit"]');
    if (button) button.click();
    else form?.requestSubmit?.();
  };
  if (email.value && password.value) {
    password.dataset.svnetFilled = '1';
    setTimeout(submit, 250);
    return;
  }
  const response = await chrome.runtime.sendMessage({ type: 'GET_CREDENTIALS' }).catch(() => null);
  if (!response?.email || !response?.password) return;
  const set = (node, value) => { const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set; setter?.call(node, value); node.dispatchEvent(new Event('input', { bubbles: true })); node.dispatchEvent(new Event('change', { bubbles: true })); };
  set(email, response.email); set(password, response.password); password.dataset.svnetFilled = '1';
  setTimeout(submit, 250);
}
fillLogin();
new MutationObserver(fillLogin).observe(document.documentElement, { childList: true, subtree: true });
