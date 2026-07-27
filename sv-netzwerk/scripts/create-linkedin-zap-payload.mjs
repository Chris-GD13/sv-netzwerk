import { access, mkdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const runtimeFile = path.join(root, '.automation', 'latest-publication.json');
const payloadDir = path.join(root, '.automation', 'linkedin-payloads');

const readText = (file) => readFile(file, 'utf8');

const parseFrontmatterDescription = (source) => {
  const match = source.match(/^---\r?\n([\s\S]*?)\r?\n---\r?\n/);
  if (!match) return '';
  const frontmatter = match[1];
  return frontmatter.match(/^description:\s*"([^"]+)"/m)?.[1]?.trim() ?? '';
};

const extractHashtags = (text) => {
  const tags = [...text.matchAll(/#[\p{L}\p{N}_-]+/gu)].map((item) => item[0]);
  return [...new Set(tags)].join(' ');
};

const runtimeRaw = await readText(runtimeFile);
const runtime = JSON.parse(runtimeRaw);
if (!['generated', 'resumed'].includes(runtime.status)) {
  throw new Error(`Unerwarteter Runtime-Status: ${runtime.status || 'unbekannt'}`);
}

const berlinDate = String(runtime.berlinDate || '').trim();
const slug = String(runtime.slug || '').trim();
const publicationId = String(runtime.publicationId || '').trim();
const title = String(runtime.title || '').trim();
const articleUrl = String(runtime.articleUrl || '').trim();
const imageUrl = String(runtime.imageUrl || '').trim();
if (!berlinDate || !slug || !publicationId || !title || !articleUrl || !imageUrl) {
  throw new Error('latest-publication.json enthält unvollständige Pflichtfelder.');
}

const linkedinTextFile = path.join(root, 'src', 'content', 'linkedin', `${berlinDate}_${slug}.txt`);
const knowledgeFile = path.join(root, 'src', 'content', 'knowledge', `${slug}.md`);
const imageFile = path.join(root, 'public', 'assets', 'images', 'linkedin', `${slug}.svg`);

await access(linkedinTextFile);
await access(knowledgeFile);
await access(imageFile);

const linkedinText = await readText(linkedinTextFile);
const knowledgeSource = await readText(knowledgeFile);
const firstParagraph = linkedinText
  .split(/\r?\n\r?\n/)[0]
  .replace(/\s+/g, ' ')
  .trim()
  .slice(0, 280);
const hashtags = extractHashtags(linkedinText);
const description = parseFrontmatterDescription(knowledgeSource);

const payload = {
  title,
  description,
  first_paragraph: firstParagraph,
  hashtags,
  image_url: imageUrl,
  url: articleUrl,
  date: berlinDate,
  slug,
  publication_id: publicationId,
};

await mkdir(payloadDir, { recursive: true });
const payloadPath = path.join(payloadDir, `${publicationId}.json`);
await writeFile(payloadPath, `${JSON.stringify(payload, null, 2)}\n`);

process.stdout.write(path.relative(root, payloadPath).replaceAll('\\', '/'));
