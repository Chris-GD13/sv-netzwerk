import { spawnSync } from 'node:child_process';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

export const registerPath = 'sv-netzwerk/docs/fachbeitrag-tagesregister.json';
export const berlinDate = (now = new Date()) => new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Berlin', year: 'numeric', month: '2-digit', day: '2-digit' }).format(now);
const articleUrl = value => /^https:\/\/www\.sv-netzwerk\.eu\/fachwissen\/[a-z0-9-]+\/$/.test(value || '');
const linkedinUrl = value => /^https:\/\/www\.linkedin\.com\/feed\/update\/urn:li:activity:\d+\/$/.test(value || '');

export function transition(register, command, input, now = new Date()) {
  if (register.version !== 1 || !register.days || typeof register.days !== 'object') throw new Error('Ungültiges Tagesregister; keine Veröffentlichung.');
  const date = input.date || berlinDate(now);
  if (date !== berlinDate(now)) throw new Error('Nur das aktuelle Berliner Tagesdatum darf verändert werden.');
  const next = structuredClone(register);
  const day = next.days[date];
  if (command === 'inspect') return { outcome: day ? 'existing' : 'missing', day };
  if (command === 'claim-article') {
    if (day) return { outcome: 'existing', day };
    if (!input.owner || !input.title || !/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(input.slug || '') || !articleUrl(input.url) || input.url !== `https://www.sv-netzwerk.eu/fachwissen/${input.slug}/`) throw new Error('Eigentümer, Titel, Slug und passende kanonische URL erforderlich.');
    if (Object.values(next.days).some(d => d.article.url === input.url)) throw new Error('Artikel bereits für einen anderen Tag registriert.');
    next.days[date] = { publication_id: `${date}-${input.slug}`, article: { title: input.title, slug: input.slug, url: input.url, status: 'reserved', owner: input.owner, reserved_at: now.toISOString() }, linkedin: { status: 'not-posted' } };
  } else {
    if (!day) throw new Error('Zuerst den Tagesartikel reservieren.');
    if (command === 'verify-website') {
      if (!/^[a-f0-9]{40}$/.test(input.commit || '') || !/^https:\/\/github\.com\/Chris-GD13\/sv-netzwerk\/actions\/runs\/\d+$/.test(input.run_url || '')) throw new Error('Echter Commit und Deployment-Run erforderlich.');
      if (day.article.status === 'published-verified') return { outcome: 'existing', day };
      day.article = { ...day.article, status: 'published-verified', commit: input.commit, run_url: input.run_url, verified_at: now.toISOString() };
    } else if (command === 'claim-linkedin') {
      if (day.article.status !== 'published-verified') throw new Error('Kein verifizierter Website-Livegang; kein LinkedIn.');
      const hour = Number(new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/Berlin', hour: '2-digit', hourCycle: 'h23' }).format(now));
      if (hour < 7) throw new Error('LinkedIn-Veröffentlichung frühestens 07:00 Europe/Berlin.');
      if (day.linkedin.status !== 'not-posted') return { outcome: 'existing', day };
      if (!input.owner) throw new Error('Eigentümer erforderlich.');
      day.linkedin = { status: 'reserved', owner: input.owner, reserved_at: now.toISOString(), article_url: day.article.url };
    } else if (command === 'verify-linkedin') {
      if (day.article.status !== 'published-verified' || !linkedinUrl(input.permalink)) throw new Error('Website-Livegang und echter LinkedIn-Permalink erforderlich.');
      if (day.linkedin.status === 'published-verified' || day.linkedin.status === 'published-manually-verified') {
        if (day.linkedin.permalink !== input.permalink) throw new Error('Anderer LinkedIn-Beitrag bereits verifiziert; Duplikat nicht überschreiben.');
        return { outcome: 'existing', day };
      }
      if (day.linkedin.status !== 'reserved' || day.linkedin.owner !== input.owner) throw new Error('Eigene LinkedIn-Reservierung erforderlich.');
      day.linkedin = { ...day.linkedin, status: 'published-verified', permalink: input.permalink, verified_at: now.toISOString() };
    } else throw new Error('Unbekannter Befehl.');
  }
  next.updated_at = now.toISOString();
  return { outcome: 'updated', register: next, day: next.days[date] };
}

// An isolated Git index commits only the register on the freshly fetched main.
// A normal (never forced) push is the cross-machine compare-and-swap operation.
export function remoteTransition(repo, command, input, options = {}) {
  const gitBin = options.gitBin || process.env.GIT_BIN || 'git';
  function git(args, extra = {}) {
    const r = spawnSync(gitBin, ['-C', repo, ...args], { encoding: 'utf8', ...extra, env: { ...process.env, ...extra.env } });
    if (r.error) throw r.error;
    if (r.status !== 0) throw new Error(`Git ${args[0]} fehlgeschlagen: ${(r.stderr || r.stdout).trim()}`);
    return r.stdout.trim();
  }
  if (git(['status', '--porcelain'])) throw new Error('Nur einen sauberen Worktree verwenden.');
  for (let attempt = 0; attempt < 3; attempt++) {
    git(['fetch', 'origin', 'main']);
    const base = git(['rev-parse', 'origin/main']);
    const exists = git(['ls-tree', '--name-only', base, '--', registerPath]);
    const register = exists ? JSON.parse(git(['show', `${base}:${registerPath}`])) : { version: 1, timezone: 'Europe/Berlin', days: {} };
    const result = transition(register, command, input, options.now || new Date());
    if (result.outcome !== 'updated') return result;
    const temp = mkdtempSync(path.join(tmpdir(), 'sv-publication-'));
    try {
      const contentFile = path.join(temp, 'register.json');
      writeFileSync(contentFile, JSON.stringify(result.register, null, 2) + '\n');
      const blob = git(['hash-object', '-w', contentFile]);
      const env = { GIT_INDEX_FILE: path.join(temp, 'index') };
      git(['read-tree', base], { env });
      git(['update-index', '--add', '--cacheinfo', `100644,${blob},${registerPath}`], { env });
      const tree = git(['write-tree'], { env });
      const commit = git(['commit-tree', tree, '-p', base, '-m', `docs: ${command} ${input.date || berlinDate(options.now)} [skip ci]`]);
      const pushed = spawnSync(gitBin, ['-C', repo, 'push', 'origin', `${commit}:refs/heads/main`], { encoding: 'utf8' });
      if (pushed.status === 0) return { ...result, commit };
      // Only a changed remote base justifies retrying; permission/network errors stop.
      git(['fetch', 'origin', 'main']);
      if (git(['rev-parse', 'origin/main']) === base) throw new Error(`Reservierung nicht bestätigt: ${pushed.stderr || pushed.error || 'Push fehlgeschlagen'}`);
    } finally { rmSync(temp, { recursive: true, force: true }); }
  }
  throw new Error('Drei konkurrierende Registeränderungen; neu lesen, nichts veröffentlichen.');
}

if (process.argv[1] && import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href) {
  try {
    const [command = 'inspect', json = '{}'] = process.argv.slice(2);
    console.log(JSON.stringify(remoteTransition(process.cwd(), command, JSON.parse(json)), null, 2));
  } catch (error) { console.error(error.message); process.exitCode = 1; }
}
