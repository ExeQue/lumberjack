import { createInertiaApp, http } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
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

void createInertiaApp({
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
            case name.startsWith('auth/') || name.startsWith('invitations/'):
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

// This will set light / dark mode on load...
initializeTheme();
