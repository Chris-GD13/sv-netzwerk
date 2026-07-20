import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';

export default defineConfig({
  site: 'https://www.sv-netzwerk.eu',
  integrations: [
    sitemap({
      filter: (page) =>
        !page.includes('/wissen-in-180-sekunden') &&
        !page.includes('/komponenten') &&
        !page.includes('/praxisfaelle') &&
        !page.includes('/fachwissen/tag/') &&
        !page.includes('/fachwissen/kategorie/') &&
        !page.includes('/fachwissen/seite/') &&
        !page.endsWith('/fachwissen/az/') &&
        !page.includes('/svos/fachwissen') &&
        !page.endsWith('/versicherungen/') &&
        !page.endsWith('/wissen/') &&
        !page.endsWith('/schadenarten/photovoltaik/') &&
        !page.endsWith('/netzwerk/') &&
        !page.endsWith('/kompetenzzentrum/') &&
        !page.endsWith('/gutachter-plattform/fuer-versicherungen/') &&
        !page.endsWith('/gutachter-plattform/fuer-regulierer/')
    })
  ],
  prefetch: true,
  build: { format: 'directory' },
  vite: { build: { cssMinify: 'lightningcss' } }
});
