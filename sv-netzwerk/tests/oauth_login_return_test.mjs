import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const client = fs.readFileSync(path.join(root, 'src/lib/internal/client.ts'), 'utf8');
const authorize = fs.readFileSync(path.join(root, 'public/intern/oauth/authorize.php'), 'utf8');

assert(authorize.includes("['return'=>$returnPath]"), 'OAuth authorization must return through the portal login');
assert(client.includes("new URLSearchParams(window.location.search).get('return')"), 'Login must read the OAuth return path');
assert(client.includes("returnUrl.origin === window.location.origin && returnUrl.pathname === '/intern/oauth/authorize.php'"), 'Login may only continue to the same-origin OAuth authorization endpoint');
assert(client.includes("redirectTo(`${returnUrl.pathname}${returnUrl.search}`)"), 'Successful portal login must resume OAuth authorization');
assert(client.includes("redirectTo('/intern/tagescockpit/')"), 'Normal portal login must keep its existing destination');

console.log('oauth_login_return_test: ok');
