import fs from 'node:fs';
import path from 'node:path';

const distRoot = path.resolve(process.argv[2] ?? 'dist');
const internRoot = path.join(distRoot, 'intern');
const loginRoot = path.join(internRoot, 'login');
const phpGuard = "<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/intern/auth_check.php'; ?>\n";

if (!fs.existsSync(internRoot)) {
  throw new Error(`Interner Build-Ordner fehlt: ${internRoot}`);
}

let protectedPages = 0;

const walk = (directory) => {
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const absolute = path.join(directory, entry.name);
    if (entry.isDirectory()) {
      walk(absolute);
      continue;
    }

    if (entry.name !== 'index.html') continue;
    if (absolute === path.join(loginRoot, 'index.html')) continue;

    const html = fs.readFileSync(absolute, 'utf8');
    const phpFile = path.join(directory, 'index.php');
    fs.writeFileSync(phpFile, phpGuard + html, 'utf8');
    fs.rmSync(absolute);
    protectedPages += 1;
  }
};

walk(internRoot);

if (protectedPages === 0) {
  throw new Error('Es wurden keine internen Portal-Seiten geschützt.');
}

if (!fs.existsSync(path.join(loginRoot, 'index.html'))) {
  throw new Error('Die öffentliche Loginseite fehlt nach der Absicherung.');
}

console.log(`${protectedPages} interne Portal-Seiten als geschützte PHP-Einstiegspunkte ausgegeben.`);

