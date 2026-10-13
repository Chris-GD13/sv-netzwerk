import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync('public/intern/case-document-reextract.js', 'utf8');
const page = fs.readFileSync('src/pages/intern/versicherungsfaelle/index.astro', 'utf8');
const calls = [];
const nodes = new Map();
const node = id => {
  if (!nodes.has(id)) nodes.set(id, { value: '', listeners: {}, addEventListener(type, fn) { this.listeners[type] = fn; } });
  return nodes.get(id);
};
const store = { getItem() { return JSON.stringify({ folder_id: 'case-owned', meta: {} }); }, setItem() {} };
const context = {
  location: { pathname: '/intern/versicherungsfaelle/' },
  document: {
    getElementById(id) { return nodes.get(id) || null; },
    querySelector() { return { appendChild() { node('vf-reextract'); node('vf-reextract-state'); } }; },
    createElement() { return {}; },
  },
  sessionStorage: store, localStorage: store, FormData, File,
  fetch: async (url) => {
    calls.push(url);
    if (url.includes('action=download')) return { ok: true, blob: async () => new Blob(['invoice']) };
    if (url.includes('case-file-browser')) return { ok: true, json: async () => ({ items: [{ id: 'invoice', name: 'invoice.pdf' }] }) };
    return { ok: true, json: async () => ({ fields: { schadenart: 'Leitungswasser' } }) };
  },
};
node('vf-files'); node('vf-drop');
vm.runInNewContext(source, context);
assert.equal(node('vf-files').listeners.change, undefined, 'file selection must not start AI');
assert.equal(node('vf-drop').listeners.drop, undefined, 'dropping files must not start AI');
assert.equal(calls.length, 0, 'opening the page must not call extraction');
await node('vf-reextract').listeners.click();
assert.equal(calls.filter(url => url.includes('insurance-case-extract')).length, 1, 'explicit reading remains available');

// Exercise the real upload handler with and without an already selected case.
const receive = page.match(/async function receive\(list\)\{[\s\S]*?\}\}async function extract/)[0].replace(/async function extract$/, '');
for (const selected of [true, false]) {
  let uploads = 0;
  const elements = new Map();
  const get = id => { if (!elements.has(id)) elements.set(id, {}); return elements.get(id); };
  const scope = { files: [], active: selected ? { folder_id: 'case-owned' } : null,
    $: get, e: x => x, upload: async () => { uploads++; },
    extract: async () => { throw Error('unrequested extraction'); } };
  vm.runInNewContext(receive, scope);
  await scope.receive([{ name: 'roof.jpg', size: 100 }]);
  assert.equal(uploads, selected ? 1 : 0);
  if (!selected) assert.match(get('vf-job').textContent, /manuell anlegen/);
}
console.log('Case storage makes no AI calls; explicit reading remains available.');
