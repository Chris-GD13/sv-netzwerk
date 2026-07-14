import { defineCollection } from 'astro:content';
import { z } from 'astro/zod';
import { glob } from 'astro/loaders';

const seoSchema = z.object({
  title: z.string().max(70).optional(),
  description: z.string().max(180).optional(),
  canonical: z.url().optional(),
  noindex: z.boolean().default(false),
  image: z.string().optional(),
});

const publicationSchema = z.object({
  publishedAt: z.coerce.date(),
  updatedAt: z.coerce.date().optional(),
  status: z.enum(['draft', 'review', 'published', 'archived']).default('draft'),
});

const knowledge = defineCollection({
  loader: glob({ pattern: '**/*.{md,mdx}', base: './src/content/knowledge' }),
  schema: z.object({
    title: z.string(),
    description: z.string(),
    category: z.string(),
    tags: z.array(z.string()).default([]),
    author: z.string(),
    featured: z.boolean().default(false),
    readingMinutes: z.number().int().positive().optional(),
    dailyStandard: z.boolean().default(false),
    contentLevel: z.enum(['A', 'B', 'C']).optional(),
    teaser: z.string().optional(),
    linkedinSummary: z.string().optional(),
    videoScript: z.string().optional(),
    cta: z.object({ label: z.string(), href: z.string() }).optional(),
    relatedLinks: z.array(z.string()).default([]),
    damageTypes: z.array(z.string()).default([]),
    publication: publicationSchema,
    seo: seoSchema.default({ noindex: false }),
  }),
});

const downloads = defineCollection({
  loader: glob({ pattern: '**/*.{md,mdx}', base: './src/content/downloads' }),
  schema: z.object({
    title: z.string(),
    description: z.string(),
    category: z.string(),
    tags: z.array(z.string()).default([]),
    file: z.string(),
    fileType: z.string(),
    fileSize: z.string().optional(),
    version: z.string().optional(),
    audience: z.array(z.string()).default([]),
    damageTypes: z.array(z.string()).default([]),
    format: z.enum(['PDF', 'Word', 'Excel', 'Video']).optional(),
    publication: publicationSchema,
    seo: seoSchema.default({ noindex: false }),
  }),
});

const practiceCases = defineCollection({
  loader: glob({ pattern: '**/*.{md,mdx}', base: './src/content/practice-cases' }),
  schema: z.object({
    title: z.string(),
    description: z.string(),
    lossType: z.string(),
    objectType: z.string(),
    segment: z.enum(['Gebäude', 'Hausrat', 'Gewerbe', 'Industrie']).default('Gebäude'),
    region: z.string().optional(),
    lossRange: z.enum(['unter-25k', '25k-100k', '100k-500k', 'ueber-500k']).optional(),
    tags: z.array(z.string()).default([]),
    relatedKnowledge: z.array(z.string()).default([]),
    relatedDownloads: z.array(z.string()).default([]),
    insuranceLine: z.string().optional(),
    cause: z.string().optional(),
    finding: z.string().optional(),
    assessment: z.string().optional(),
    recommendation: z.string().optional(),
    measures: z.array(z.string()).default([]),
    result: z.string().optional(),
    reserve: z.string().optional(),
    duration: z.string().optional(),
    featured: z.boolean().default(false),
    images: z.array(z.object({ src: z.string(), alt: z.string(), caption: z.string().optional() })).default([]),
    publication: publicationSchema,
    seo: seoSchema.default({ noindex: false }),
  }),
});

const authors = defineCollection({
  loader: glob({ pattern: '**/*.{json,yaml,yml}', base: './src/content/authors' }),
  schema: z.object({
    name: z.string(),
    slug: z.string(),
    role: z.string(),
    qualification: z.array(z.string()).default([]),
    bio: z.string(),
    email: z.email().optional(),
    image: z.string().optional(),
    active: z.boolean().default(true),
  }),
});

const videos = defineCollection({
  loader: glob({ pattern: '**/*.{md,mdx}', base: './src/content/videos-library' }),
  schema: z.object({
    title: z.string(),
    description: z.string(),
    topic: z.string(),
    damageType: z.string(),
    duration: z.string(),
    transcript: z.string(),
    platformLinks: z.array(z.string()).default([]),
    tags: z.array(z.string()).default([]),
    relatedKnowledge: z.array(z.string()).default([]),
    downloadHints: z.array(z.string()).default([]),
    publication: publicationSchema,
    seo: seoSchema.default({ noindex: false }),
  }),
});

export const collections = { knowledge, downloads, practiceCases, authors, videos };
