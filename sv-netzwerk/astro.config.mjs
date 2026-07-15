import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';

export default defineConfig({
  site: 'https://www.sv-netzwerk.eu',
  integrations: [
    sitemap({
      filter: (page) =>
        !page.includes('/wissen-in-180-sekunden') && !page.includes('/komponenten')
    })
  ],
  prefetch: true,
  build: { format: 'directory' },
  vite: { build: { cssMinify: 'lightningcss' } }
});
