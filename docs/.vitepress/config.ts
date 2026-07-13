import { defineConfig } from 'vitepress'

const docsSidebar = [
  {
    text: 'Getting started',
    items: [
      { text: 'Installation & requirements', link: '/getting-started/installation' },
      { text: 'Quick start', link: '/getting-started/quick-start' },
    ],
  },
  {
    text: 'Guides',
    items: [
      { text: 'Companies & teams', link: '/guides/companies-teams' },
      { text: 'Quotes', link: '/guides/quotes' },
      { text: 'Order approvals', link: '/guides/approvals' },
      { text: 'Pay on account & credit', link: '/guides/pay-on-account' },
      { text: 'Quick order & order lists', link: '/guides/quick-order' },
      { text: 'PO numbers', link: '/guides/po-numbers' },
      { text: 'PDF documents', link: '/guides/pdf-documents' },
      { text: 'Departments & budgets', link: '/guides/departments-budgets' },
      { text: 'Sales reps (order on behalf)', link: '/guides/sales-reps' },
      { text: 'Company-specific pricing & catalog', link: '/guides/company-catalog' },
      { text: 'Statements & dunning', link: '/guides/statements-dunning' },
    ],
  },
  {
    text: 'Reference',
    items: [
      { text: 'Settings', link: '/reference/settings' },
      { text: 'Permissions', link: '/reference/permissions' },
      { text: 'Console commands', link: '/reference/console-commands' },
      { text: 'Template variables', link: '/reference/template-variables' },
      { text: 'GraphQL API', link: '/reference/graphql' },
      { text: 'Events', link: '/reference/events' },
      { text: 'System messages', link: '/reference/system-messages' },
    ],
  },
  {
    text: 'More',
    items: [
      { text: 'Upgrading', link: '/upgrading' },
      { text: 'FAQ', link: '/faq' },
    ],
  },
]

export default defineConfig({
  title: 'B2B Commerce',
  description: 'B2B suite for Craft Commerce: company accounts, quotes, order approvals, pay on account and quick ordering.',
  base: '/craft-b2b-commerce/',
  cleanUrls: true,
  lastUpdated: true,
  appearance: 'dark',

  // docs/superpowers is a local, git-excluded planning directory (not part of the published
  // site) and its markdown is not authored as VitePress pages — exclude it from the build.
  srcExclude: ['superpowers/**'],

  head: [
    ['meta', { name: 'theme-color', content: '#5C46A9' }],
  ],

  themeConfig: {
    logo: '/logo.svg',

    nav: [
      { text: 'Guide', link: '/getting-started/installation', activeMatch: '^/(getting-started|guides|reference)/' },
      { text: 'Store', link: 'https://plugins.craftcms.com/b2b-commerce' },
      { text: 'Changelog', link: 'https://github.com/TotalWebCreations/craft-b2b-commerce/blob/main/CHANGELOG.md' },
      { text: 'Issues', link: 'https://github.com/TotalWebCreations/craft-b2b-commerce/issues' },
    ],

    sidebar: docsSidebar,

    search: {
      provider: 'local',
    },

    footer: {
      message: 'Released under a commercial license.',
      copyright: '© TotalWebCreations',
    },

    socialLinks: [
      { icon: 'github', link: 'https://github.com/TotalWebCreations/craft-b2b-commerce' },
    ],

    outline: {
      level: [2, 3],
    },
  },
})
