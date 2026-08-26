import fs from 'node:fs';
import path from 'node:path';

const [scope, sourceArg = 'dist', targetArg = scope === 'portal' ? 'deploy-portal' : 'deploy-site'] = process.argv.slice(2);
if (!['site', 'portal'].includes(scope)) {
  throw new Error('Aufruf: node scripts/prepare-deploy-scope.mjs site|portal [quelle] [ziel]');
}

const source = path.resolve(sourceArg);
const target = path.resolve(targetArg);
const protectedName = (name) => name === '.htaccess' || name === 'config.php' || name.startsWith('.env') || name.endsWith('.log');
const copyFile = (relative) => {
  const from = path.join(source, relative);
  if (!fs.existsSync(from) || !fs.statSync(from).isFile() || protectedName(path.basename(relative))) return;
  const to = path.join(target, relative);
  fs.mkdirSync(path.dirname(to), { recursive: true });
  fs.copyFileSync(from, to);
};
const copyTree = (relative, filter = () => true) => {
  const root = path.join(source, relative);
  if (!fs.existsSync(root)) return;
  for (const entry of fs.readdirSync(root, { withFileTypes: true })) {
    const child = path.join(relative, entry.name);
    if (!filter(child, entry)) continue;
    if (entry.isDirectory()) copyTree(child, filter);
    else copyFile(child);
  }
};
const referencedBuildFiles = (scanRoot) => {
  const references = new Set();
  const scanFile = (absolute) => {
    if (!/\.(?:html|css|js)$/i.test(absolute)) return;
    const text = fs.readFileSync(absolute, 'utf8');
    for (const match of text.matchAll(/["'(]\/((?:_astro|assets)\/[^"')?#\s]+)/g)) references.add(match[1]);
  };
  const scanTree = (root) => {
    if (!fs.existsSync(root)) return;
    for (const entry of fs.readdirSync(root, { withFileTypes: true })) {
      const absolute = path.join(root, entry.name);
      if (entry.isDirectory()) scanTree(absolute);
      else scanFile(absolute);
    }
  };
  scanTree(scanRoot);
  for (const relative of references) scanFile(path.join(source, relative));
  return references;
};

fs.rmSync(target, { recursive: true, force: true });
fs.mkdirSync(target, { recursive: true });

if (scope === 'site') {
  const portalRoots = new Set(['intern', 'sw.js', 'manifest.webmanifest', 'portal-release-2026-08-21-2317.txt', 'portal-release-2026-08-21-2325.txt']);
  copyTree('', (relative) => !portalRoots.has(relative.split(path.sep)[0]));
  const references = referencedBuildFiles(target);
  const astroDirectory = path.join(target, '_astro');
  if (fs.existsSync(astroDirectory)) {
    for (const entry of fs.readdirSync(astroDirectory)) {
      if (!references.has(`_astro/${entry}`)) fs.rmSync(path.join(astroDirectory, entry), { recursive: true, force: true });
    }
  }
} else {
  copyTree('intern');
  for (const file of ['sw.js', 'manifest.webmanifest']) copyFile(file);

  const references = referencedBuildFiles(path.join(target, 'intern'));
  for (const relative of references) copyFile(relative);

  const sha = process.env.GITHUB_SHA || 'local-build';
  const marker = `Commit: ${sha}\nScope: portal\nBuild-Zeit: ${new Date().toISOString()}\n`;
  fs.writeFileSync(path.join(target, 'intern', 'deploy-version.txt'), marker, 'utf8');
}

const files = [];
const collect = (root) => {
  for (const entry of fs.readdirSync(root, { withFileTypes: true })) {
    const absolute = path.join(root, entry.name);
    if (entry.isDirectory()) collect(absolute);
    else files.push(path.relative(target, absolute).replaceAll('\\', '/'));
  }
};
collect(target);
if (files.some((file) => protectedName(path.basename(file)))) throw new Error('Geschützte Datei im Deployment-Paket.');
if (scope === 'site' && files.some((file) => file === 'intern' || file.startsWith('intern/'))) throw new Error('Portaldatei im Website-Paket.');
if (scope === 'portal' && files.some((file) => !file.startsWith('intern/') && !file.startsWith('_astro/') && !file.startsWith('assets/') && !['sw.js', 'manifest.webmanifest'].includes(file))) throw new Error('Website-Datei im Portal-Paket.');
if (!files.length) throw new Error('Deployment-Paket ist leer.');
console.log(`${scope}: ${files.length} Dateien vorbereitet`);
