const fs = require('node:fs');
const path = require('node:path');

const defaultCredentialsFile = 'G:\\Meine Ablage\\Zugangsdaten Claims\\Claims.txt';

function config() {
  const file = path.join(path.dirname(process.execPath), 'claims-credentials-host.json');
  if (!fs.existsSync(file)) return { credentialsFile: defaultCredentialsFile };
  const loaded = JSON.parse(fs.readFileSync(file, 'utf8'));
  return { ...loaded, credentialsFile: loaded.credentialsFile || defaultCredentialsFile };
}

function credentials(profile) {
  const lines = fs.readFileSync(config().credentialsFile, 'utf8').split(/\r?\n/).map(line => line.trim()).filter(Boolean);
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
  if (!selected?.email || !selected?.password) throw new Error('Für dieses ClaimsForce-Profil wurden keine vollständigen Zugangsdaten gefunden.');
  return { email: selected.email, password: selected.password };
}

function reply(value) {
  const body = Buffer.from(JSON.stringify(value));
  const size = Buffer.alloc(4);
  size.writeUInt32LE(body.length, 0);
  process.stdout.write(Buffer.concat([size, body]));
}

let input = Buffer.alloc(0);
process.stdin.on('data', chunk => {
  input = Buffer.concat([input, chunk]);
  while (input.length >= 4) {
    const size = input.readUInt32LE(0);
    if (input.length < size + 4) return;
    const message = JSON.parse(input.subarray(4, size + 4).toString('utf8'));
    input = input.subarray(size + 4);
    try { reply(credentials(message.profile)); }
    catch (error) { reply({ error: error.message }); }
  }
});
