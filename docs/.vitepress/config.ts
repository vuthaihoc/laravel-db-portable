import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'Laravel DB Portable',
  description: 'Query builder helpers, schema macros, database switching tools, and parallel database mirrors for Laravel.',
  base: '/laravel-db-portable/',

  themeConfig: {
    nav: [
      { text: 'Docs', link: '/docs/installation' },
      { text: 'Mirrors', link: '/docs/mirrors' },
      {
        text: 'Resources',
        items: [
          { text: 'GitHub', link: 'https://github.com/vuthaihoc/laravel-db-portable' },
          { text: 'Packagist', link: 'https://packagist.org/packages/vuthaihoc/laravel-db-portable' },
        ],
      },
    ],

    sidebar: [
      {
        text: 'Getting Started',
        items: [
          { text: 'Installation & Overview', link: '/docs/installation' },
          { text: 'Testing & Conformance', link: '/docs/testing' },
        ],
      },
      {
        text: 'Querying & Eloquent',
        items: [
          { text: 'Query Builder Macros', link: '/docs/query-builder' },
          { text: 'Analytics & ROLLUP', link: '/docs/analytics' },
          { text: 'Historical & Stale Reads', link: '/docs/historical-reads' },
          { text: 'Search & Autocomplete', link: '/docs/search' },
        ],
      },
      {
        text: 'Schema & Indexes',
        items: [
          { text: 'Schema & Blueprint Macros', link: '/docs/schema' },
          { text: 'Scout Search Indexes', link: '/docs/search-indexes' },
        ],
      },
      {
        text: 'Switching Databases',
        items: [
          { text: 'Scan, Audit & Copy', link: '/docs/switching-databases' },
        ],
      },
      {
        text: 'Parallel Databases',
        items: [
          { text: 'Mirrors (Sync Queue)', link: '/docs/mirrors' },
          { text: 'Roadmap & Architecture', link: '/plans/parallel-databases' },
        ],
      },
    ],

    socialLinks: [
      { icon: 'github', link: 'https://github.com/vuthaihoc/laravel-db-portable' },
    ],

    search: {
      provider: 'local',
    },

    editLink: {
      pattern: 'https://github.com/vuthaihoc/laravel-db-portable/edit/master/docs/:path',
      text: 'Edit this page on GitHub',
    },

    footer: {
      message: 'Released under the MIT License.',
    },
  },
})
