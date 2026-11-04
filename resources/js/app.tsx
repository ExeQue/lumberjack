import { createInertiaApp, http, router } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import { loadTranslations } from '@/lib/i18n';
import { tabHeaders, trackTabContext } from '@/lib/tab-context';
import AuthLayout from '@/layouts/auth-layout';
import LumberjackLayout from '@/layouts/lumberjack-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { configureEcho, echo } from '@laravel/echo-react';

// Where to reach Reverb comes from the server on each page load, not from the build.
const reverb = JSON.parse(
    document
        .querySelector('meta[name="lumberjack-reverb"]')
        ?.getAttribute('content') ?? '{}',
) as { key?: string; host?: string; port?: number; scheme?: string };

configureEcho({
    broadcaster: 'reverb',
    key: reverb.key,
    wsHost: reverb.host,
    wsPort: reverb.port,
    wssPort: reverb.port,
    forceTLS: reverb.scheme === 'https',
    enabledTransports: ['ws', 'wss'],
});

// Live broadcasts caused by this tab's own requests skip it: the response already shows them.
http.onRequest((config) => {
    const socket = echo().socketId();

    if (socket) {
        config.headers = { ...config.headers, 'X-Socket-ID': socket };
    }

    // This tab's team and project, also for requests outside a page visit.
    config.headers = { ...config.headers, ...tabHeaders() };

    return config;
});

trackTabContext();

const appName = 'Lumberjack';

/** Where the first page's strings are: read from the page data in the HTML. */
function initialTranslationsUrl(): string | null {
    try {
        const page = JSON.parse(
            document.querySelector('script[data-page]')?.textContent ?? '{}',
        ) as { props?: { translations_url?: string } };

        return page.props?.translations_url ?? null;
    } catch {
        return null;
    }
}

// Another locale (or a deploy that changed the strings) on a later page: load those, then
// draw the page again with them.
router.on('navigate', (event) => {
    const url = (event.detail.page.props as { translations_url?: string })
        .translations_url;

    if (url) {
        void loadTranslations(url).then(
            (changed) => changed && router.reload({ only: [] }),
        );
    }
});

// The strings first (preloaded by the layout, so usually already here), then the app.
void (async () => {
    const url = initialTranslationsUrl();

    if (url) {
        await loadTranslations(url).catch(() => undefined);
    }

    await createInertiaApp({
        title: (title) => (title ? `${title} - ${appName}` : appName),
        layout: (name) => {
            switch (true) {
                case name === 'welcome' ||
                    name === 'screens/kiosk' ||
                    name === 'screens/edit' ||
                    name === 'screens/unshared' ||
                    name.startsWith('tv/') ||
                    name.startsWith('onboarding/') ||
                    name.startsWith('pair/'):
                    return null;
                case name.startsWith('auth/') ||
                    name.startsWith('invitations/'):
                    return AuthLayout;
                case name.startsWith('settings/'):
                    return [LumberjackLayout, SettingsLayout];
                default:
                    return LumberjackLayout;
            }
        },
        strictMode: true,
        withApp(app) {
            return (
                <TooltipProvider delayDuration={0}>
                    {app}
                    <Toaster />
                </TooltipProvider>
            );
        },
        progress: {
            color: '#F0A93B',
        },
    });
})();

// This will set light / dark mode on load...
initializeTheme();
