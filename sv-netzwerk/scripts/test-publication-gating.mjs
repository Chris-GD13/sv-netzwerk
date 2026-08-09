import { isKnowledgeItemPublished, isLibraryItemPublished } from '../src/utils/publication.ts';

const checks = [
  {
    name: 'Knowledge draft before 05:00 stays private',
    actual: isKnowledgeItemPublished({ publication: { status: 'published', publishedAt: '2026-08-10' } }, new Date('2026-08-10T02:59:00.000Z')),
    expected: false,
  },
  {
    name: 'Knowledge item at 05:00 becomes public',
    actual: isKnowledgeItemPublished({ publication: { status: 'published', publishedAt: '2026-08-10' } }, new Date('2026-08-10T03:00:00.000Z')),
    expected: true,
  },
  {
    name: 'Library item before 05:00 stays private',
    actual: isLibraryItemPublished({ date: '2026-08-10', type: 'article' }, new Date('2026-08-10T02:59:00.000Z')),
    expected: false,
  },
  {
    name: 'Library item at 05:00 becomes public',
    actual: isLibraryItemPublished({ date: '2026-08-10', type: 'article' }, new Date('2026-08-10T03:00:00.000Z')),
    expected: true,
  },
];

const failures = checks.filter((check) => check.actual !== check.expected);

for (const check of checks) {
  console.log(`${check.expected === check.actual ? 'OK' : 'FAIL'} ${check.name}: ${check.actual}`);
}

if (failures.length > 0) {
  process.exit(1);
}
