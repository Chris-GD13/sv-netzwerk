const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');

const profileOrder = ['christian', 'marc', 'holger', 'jens'];
const configFile = process.argv[2] || path.join(__dirname, 'claims-credentials-server.json');
const config = JSON.parse(fs.readFileSync(configFile, 'utf8'));

function credentials(profile) {
  const lines = fs.readFileSync(config.credentialsFile, 'utf8').split(/\r?\n/).map(line => line.trim()).filter(Boolean);
  const groups = [];
  for (let index = 0; index + 2 < lines.length; index += 3) groups.push({ email: lines[index + 1], password: lines[index + 2] });
  const selected = groups[profileOrder.indexOf(String(profile || '').toLowerCase())];
  if (!selected?.email || !selected?.password) throw new Error('Profil nicht gefunden.');
  return selected;
}

const server = http.createServer((request, response) => {
  const origin = String(request.headers.origin || '');
  response.setHeader('Access-Control-Allow-Origin', config.allowedOrigin);
  response.setHeader('Vary', 'Origin');
  response.setHeader('Content-Type', 'application/json; charset=utf-8');
  if (origin !== config.allowedOrigin || request.headers['x-svnet-token'] !== config.token) {
    response.writeHead(403); response.end('{"error":"Nicht freigegeben."}'); return;
  }
  const url = new URL(request.url, `http://127.0.0.1:${config.port}`);
  if (url.pathname !== '/credentials') { response.writeHead(404); response.end('{"error":"Unbekannt."}'); return; }
  try { response.end(JSON.stringify(credentials(url.searchParams.get('profile')))); }
  catch (error) { response.writeHead(404); response.end(JSON.stringify({ error: error.message })); }
});

server.listen(config.port, '127.0.0.1');
