import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';
import { VitePWA } from 'vite-plugin-pwa';

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
        !page.includes('/intern/') &&
        !page.endsWith('/gutachter-plattform/fuer-versicherungen/') &&
        !page.endsWith('/gutachter-plattform/fuer-regulierer/')
    }),
  ],
  prefetch: true,
  build: { format: 'directory' },
  vite: {
    build: { cssMinify: 'lightningcss' },
    plugins: [
      VitePWA({
        strategies: 'injectManifest',
        srcDir: 'public',
        filename: 'sw.js',
        injectRegister: false,
        registerType: 'autoUpdate',
        includeAssets: ['favicon.ico', 'robots.txt', 'sitemap-index.xml'],
        manifest: {
          name: 'SV-Netzwerk Prüferportal',
          short_name: 'Portal',
          description: 'Offline-enabled inspection portal for window assessments',
          theme_color: '#1a1a1a',
          background_color: '#ffffff',
          display: 'standalone',
          scope: '/intern/',
          start_url: '/intern/login/',
          icons: [
            {
              src: '/icon-192x192.png',
              sizes: '192x192',
              type: 'image/png',
              purpose: 'any maskable'
            },
            {
              src: '/icon-512x512.png',
              sizes: '512x512',
              type: 'image/png',
              purpose: 'any maskable'
            }
          ],
          categories: ['productivity'],
          screenshots: [
            {
              src: '/screenshot-wide.png',
              sizes: '540x720',
              type: 'image/png',
              form_factor: 'wide'
            },
            {
              src: '/screenshot-narrow.png',
              sizes: '270x540',
              type: 'image/png',
              form_factor: 'narrow'
            }
          ]
        },
        injectManifest: {
          globPatterns: ['**/*.{js,css,svg,png,jpg,jpeg,gif,webp,woff,woff2}'],
          globIgnores: ['**/node_modules/**/*', '.htaccess'],
        },
      })
    ]
  }
});
