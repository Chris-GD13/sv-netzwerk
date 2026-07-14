import { execFileSync } from 'node:child_process';
import { mkdir, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const publicDirectory = path.join(projectRoot, 'public');
const versionFile = path.join(publicDirectory, 'deploy-version.txt');

const commit = process.env.GITHUB_SHA?.trim() || execFileSync('git', ['rev-parse', 'HEAD'], {
  cwd: projectRoot,
  encoding: 'utf8'
}).trim();

const contents = [
  `Commit: ${commit}`,
  `Build-Zeit: ${new Date().toISOString()}`,
  'Version: Homepage-v5',
  ''
].join('\n');

await mkdir(publicDirectory, { recursive: true });
await writeFile(versionFile, contents, 'utf8');
console.log(`Deployment-Version für ${commit} erzeugt.`);
