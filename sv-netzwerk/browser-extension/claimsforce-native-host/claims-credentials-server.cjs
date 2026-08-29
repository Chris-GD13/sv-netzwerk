const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');

const configFile = process.argv[2] || path.join(__dirname, 'claims-credentials-server.json');
const config = JSON.parse(fs.readFileSync(configFile, 'utf8'));
const logFile = path.join(path.dirname(configFile), 'claims-credentials-server.log');

function credentials(profile) {
  const lines = fs.readFileSync(config.credentialsFile, 'utf8').split(/\r?\n/).map(line => line.trim()).filter(Boolean);
  const groups = [];
  for (let index = 0; index + 2 < lines.length; index += 3) groups.push({ label: lines[index], email: lines[index + 1], password: lines[index + 2] });
  const requested = String(profile || '').toLowerCase();
  const selected = groups.find(group => {
    const label = group.label.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    if (requested === 'christian' || requested === 'self') return label.includes('christian wachter');
    if (requested === 'marc') return label.includes('marc schutt');
    if (requested === 'holger') return label.includes('holger roth');
    if (requested === 'jens') return label.includes('jens maurer');
    return false;
  });
  if (!selected?.email || !selected?.password) throw new Error('Profil nicht gefunden.');
  return selected;
}

const server = http.createServer((request, response) => {
  const origin = String(request.headers.origin || '');
  fs.appendFileSync(logFile, JSON.stringify({ at: new Date().toISOString(), method: request.method, path: request.url, origin }) + '\n');
  response.setHeader('Access-Control-Allow-Origin', config.allowedOrigin);
  response.setHeader('Access-Control-Allow-Headers', 'X-SVNET-Token');
  response.setHeader('Access-Control-Allow-Methods', 'GET, OPTIONS');
  response.setHeader('Vary', 'Origin');
  response.setHeader('Content-Type', 'application/json; charset=utf-8');
  if (request.method === 'OPTIONS' && origin === config.allowedOrigin) {
    response.writeHead(204); response.end(); return;
  }
  if (origin !== config.allowedOrigin || request.headers['x-svnet-token'] !== config.token) {
    response.writeHead(403); response.end('{"error":"Nicht freigegeben."}'); return;
  }
  const url = new URL(request.url, `http://127.0.0.1:${config.port}`);
  if (url.pathname !== '/credentials') { response.writeHead(404); response.end('{"error":"Unbekannt."}'); return; }
  try { response.end(JSON.stringify(credentials(url.searchParams.get('profile')))); }
  catch (error) { response.writeHead(404); response.end(JSON.stringify({ error: error.message })); }
});

server.listen(config.port, '127.0.0.1');
