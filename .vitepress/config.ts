import { defineConfig } from 'vitepress';

// The markdown in this repository is the source: the site is a view of it, not a copy.
// Everything links the same way on GitHub and on the site, so srcDir is the repository root.
export default defineConfig({
    title: 'Lumberjack',
    description:
        'Self-hosted error tracking, logs and performance monitoring for Laravel.',
    base: '/lumberjack/',
    lastUpdated: true,
    cleanUrls: true,

    // Only README.md and docs/ are pages; everything else in the repository is code.
    srcExclude: [
        '.ai/**',
        '.github/**',
        'app/**',
        'bootstrap/**',
        'config/**',
        'database/**',
        'lang/**',
        'node_modules/**',
        'public/**',
        'resources/**',
        'routes/**',
        'storage/**',
        'tests/**',
        'vendor/**',
    ],

    // README.md is the front page here, and stays the front page on GitHub.
    rewrites: {
        'README.md': 'index.md',
    },

    vite: {
        // Not the app's vite.config.ts: its plugins (Wayfinder runs artisan) have no place in the docs.
        configFile: false,
        server: {
            watch: {
                ignored: [
                    '**/vendor/**',
                    '**/storage/**',
                    '**/tests/**',
                    '**/.phpunit.cache/**',
                ],
            },
        },
    },

    themeConfig: {
        nav: [{ text: 'Run it with Docker', link: '/docs/docker' }],

        sidebar: [
            {
                text: 'Getting started',
                items: [
                    { text: 'Introduction', link: '/' },
                    { text: 'Running with Docker', link: '/docs/docker' },
                ],
            },
            {
                text: 'Using it',
                items: [
                    { text: 'Projects', link: '/docs/projects' },
                    { text: 'Teams and accounts', link: '/docs/teams' },
                    { text: 'Issues', link: '/docs/issues' },
                    { text: 'Alerts', link: '/docs/alerts' },
                    {
                        text: 'Performance and traces',
                        link: '/docs/performance',
                    },
                    { text: 'Releases', link: '/docs/releases' },
                    { text: 'Health', link: '/docs/health' },
                    { text: 'Status screens', link: '/docs/screens' },
                    { text: 'Finding things', link: '/docs/finding-things' },
                    { text: 'MCP', link: '/docs/mcp' },
                    {
                        text: 'Metrics API and Grafana',
                        link: '/docs/metrics-api',
                    },
                ],
            },
            {
                text: 'Operating it',
                items: [
                    {
                        text: 'Setup and administration',
                        link: '/docs/administration',
                    },
                    { text: 'Ingest and API', link: '/docs/api' },
                ],
            },
        ],

        search: { provider: 'local' },

        socialLinks: [
            { icon: 'github', link: 'https://github.com/ExeQue/lumberjack' },
        ],

        editLink: {
            pattern: 'https://github.com/ExeQue/lumberjack/edit/main/:path',
            text: 'Edit this page on GitHub',
        },

        outline: [2, 3],
    },
});
