import { readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const articleArg = process.argv[2];
if (!articleArg) throw new Error('Aufruf: node scripts/sync-library-from-article.mjs <slug|datei.md>');

const slug = path.basename(articleArg).replace(/\.mdx?$/, '');
const articlePath = path.join(root, 'src', 'content', 'knowledge', `${slug}.md`);
const libraryPath = path.join(root, 'src', 'data', 'library.ts');
const source = await readFile(articlePath, 'utf8');
const fm = source.match(/^---\r?\n([\s\S]*?)\r?\n---/m)?.[1] ?? '';
const scalar = (key) => fm.match(new RegExp(`^\\s*${key}:\\s*["']?([^"'\\r\\n]+)["']?\\s*$`, 'm'))?.[1]?.trim() ?? '';
const title = scalar('title');
const description = scalar('description');
const category = scalar('category');
const publishedAt = scalar('publishedAt');
const featured = /^featured:\s*true$/m.test(fm);
const tagsRaw = fm.match(/^tags:\s*\[([^\]]*)\]/m)?.[1] ?? '';
const tags = tagsRaw.split(',').map((item) => item.trim().replace(/^['"]|['"]$/g, '')).filter(Boolean);
if (!title || !description || !category || !publishedAt) throw new Error(`Frontmatter unvollständig in ${articlePath}`);

const q = (value) => `'${String(value).replaceAll('\\', '\\\\').replaceAll("'", "\\'")}'`;
const indentTags = `[${tags.map(q).join(', ')}]`;
const href = `/fachwissen/${slug}/`;
const block = `  {\n    title: ${q(title)},\n    description: ${q(description)},\n    href: ${q(href)},\n    category: ${q(category)},\n    tags: ${indentTags},\n    date: ${q(publishedAt)},\n    type: 'article',\n    featured: ${featured ? 'true' : 'false'},\n  },\n`;

let library = await readFile(libraryPath, 'utf8');
const escapedHref = href.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const entryPattern = new RegExp(`  \\{\\n(?:[^\\n]*\\n)*?    href: ['"]${escapedHref}['"],\\n(?:[^\\n]*\\n)*?  \\},\\n`, 'm');
if (entryPattern.test(library)) library = library.replace(entryPattern, '');
const anchor = 'export const library: LibraryItem[] = [\n';
if (!library.includes(anchor)) throw new Error('Library-Anker nicht gefunden.');
library = library.replace(anchor, `${anchor}${block}`);
await writeFile(libraryPath, library);
console.log(`library.ts synchronisiert: ${title} -> ${href}`);
