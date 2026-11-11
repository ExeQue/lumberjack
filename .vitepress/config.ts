import { defineConfig } from 'vitepress';

const base = process.env.DOCS_BASE ?? '/lumberjack/';

// The markdown in this repository is the source: the site is a view of it, not a copy.
// Everything links the same way on GitHub and on the site, so srcDir is the repository root.
export default defineConfig({
    title: 'Lumberjack',
    description:
        'Self-hosted error tracking, logs and performance monitoring for any app, whatever it is built with.',
    // GitHub Pages serves the docs under /lumberjack/; the lerd docs worker serves them at the root of its subdomain.
    base,
    lastUpdated: true,
    cleanUrls: true,

    // The app's own icons, from public/; head links do not get the base added.
    head: [
        ['link', { rel: 'icon', href: `${base}favicon.ico`, sizes: 'any' }],
        [
            'link',
            {
                rel: 'icon',
                href: `${base}favicon.svg`,
                type: 'image/svg+xml',
            },
        ],
        [
            'link',
            {
                rel: 'apple-touch-icon',
                href: `${base}apple-touch-icon.png`,
            },
        ],
    ],

    // Only README.md and docs/ are pages; everything else in the repository is code.
    srcExclude: [
        '.ai/**',
        'AGENTS.md',
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
            // Served through lerd on docs.lumberjack.test, which Vite would otherwise refuse.
            allowedHosts: ['.test'],
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
        logo: '/favicon.svg',

        nav: [
            { text: 'Guides', link: '/docs/guides/' },
            { text: 'Run it with Docker', link: '/docs/docker' },
        ],

        sidebar: [
            {
                text: 'Getting started',
                items: [
                    { text: 'Introduction', link: '/' },
                    { text: 'Running with Docker', link: '/docs/docker' },
                    { text: 'Installing from source', link: '/docs/source' },
                    { text: 'Libraries', link: '/docs/libraries' },
                ],
            },
            {
                text: 'Guides',
                items: [
                    { text: 'Overview', link: '/docs/guides/' },
                    {
                        text: 'Know when production breaks',
                        link: '/docs/guides/alerting',
                    },
                    {
                        text: 'Find why a page is slow',
                        link: '/docs/guides/slow-pages',
                    },
                    {
                        text: 'Check a release',
                        link: '/docs/guides/releases',
                    },
                    {
                        text: 'Watch queues and jobs',
                        link: '/docs/guides/background-work',
                    },
                    {
                        text: 'Put a status screen on a TV',
                        link: '/docs/guides/status-tv',
                    },
                    {
                        text: 'Let an AI agent read your errors',
                        link: '/docs/guides/ai-agents',
                    },
                    {
                        text: 'Show metrics in Grafana',
                        link: '/docs/guides/grafana',
                    },
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
                    { text: 'Widgets', link: '/docs/widgets' },
                    { text: 'Data sources', link: '/docs/data-sources' },
                    {
                        text: 'Connectors',
                        collapsed: true,
                        // Grouped by each connector's first tag, as the Connector enum has them.
                        items: [
                            { text: 'Overview', link: '/docs/connectors/' },
                            {
                                text: 'Analytics',
                                collapsed: true,
                                items: [
                                    {
                                        text: 'Umami',
                                        link: '/docs/connectors/umami',
                                    },
                                ],
                            },
                            {
                                text: 'Development',
                                collapsed: true,
                                items: [
                                    {
                                        text: 'GitHub',
                                        link: '/docs/connectors/github',
                                    },
                                ],
                            },
                            {
                                text: 'Forms',
                                collapsed: true,
                                items: [
                                    {
                                        text: 'mosparo',
                                        link: '/docs/connectors/mosparo',
                                    },
                                ],
                            },
                            {
                                text: 'Payments',
                                collapsed: true,
                                items: [
                                    {
                                        text: 'Vipps MobilePay',
                                        link: '/docs/connectors/mobilepay',
                                    },
                                ],
                            },
                        ],
                    },
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
                    { text: 'OpenTelemetry', link: '/docs/opentelemetry' },
                    { text: 'Development', link: '/docs/development' },
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
