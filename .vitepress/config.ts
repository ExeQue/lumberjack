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
                    { text: 'Finding things', link: '/docs/finding-things' },
                ],
            },
            {
                text: 'Operating it',
                items: [
                    {
                        text: 'Setup and administration',
                        link: '/docs/administration',
                    },
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
