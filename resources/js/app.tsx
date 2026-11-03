import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AuthLayout from '@/layouts/auth-layout';
import LumberjackLayout from '@/layouts/lumberjack-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { configureEcho } from '@laravel/echo-react';

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

const appName = 'Lumberjack';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome' ||
                name === 'screens/kiosk' ||
                name === 'screens/edit':
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
