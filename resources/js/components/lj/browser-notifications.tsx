import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import { formatDateTime } from '@/lib/format';
import { destroy, store } from '@/routes/push-subscriptions';

export type PushSettings = {
    public_key: string | null;
    devices: {
        label: string | null;
        endpoint: string;
        created_at: string | null;
    }[];
};

type Problem = 'unsupported' | 'denied' | 'not_configured' | 'failed';

const supported = () =>
    typeof window !== 'undefined' &&
    window.isSecureContext &&
    'serviceWorker' in navigator &&
    'PushManager' in window &&
    'Notification' in window;

/** The VAPID public key, base64url, as the bytes PushManager.subscribe() wants. */
function keyBytes(base64url: string): Uint8Array<ArrayBuffer> {
    const base64 = (base64url + '='.repeat((4 - (base64url.length % 4)) % 4))
        .replace(/-/g, '+')
        .replace(/_/g, '/');
    const raw = atob(base64);
    const bytes = new Uint8Array(new ArrayBuffer(raw.length));

    for (let i = 0; i < raw.length; i++) {
        bytes[i] = raw.charCodeAt(i);
    }

    return bytes;
}

/**
 * Turns browser push notifications on for this browser (permission, service worker,
 * subscription), and lists every browser they are on in, each with Remove.
 */
export function BrowserNotifications({
    push,
    heading = true,
}: {
    push: PushSettings;
    /** Off where the page already says what this is (the onboarding's step). */
    heading?: boolean;
}) {
    const t = useT();
    const [problem, setProblem] = useState<Problem | null>(null);
    const [busy, setBusy] = useState(false);
    const [endpoint, setEndpoint] = useState<string | null>(null);

    useEffect(() => {
        if (!supported()) {
            return;
        }

        navigator.serviceWorker
            .getRegistration('/')
            .then((registration) => registration?.pushManager.getSubscription())
            .then((subscription) => setEndpoint(subscription?.endpoint ?? null))
            .catch(() => setEndpoint(null));
    }, [push.devices.length]);

    const here = push.devices.some((device) => device.endpoint === endpoint);

    const enable = async () => {
        setProblem(null);

        if (!supported()) {
            setProblem('unsupported');

            return;
        }

        if (push.public_key === null) {
            setProblem('not_configured');

            return;
        }

        setBusy(true);

        try {
            if ((await Notification.requestPermission()) !== 'granted') {
                setProblem('denied');

                return;
            }

            const registration = await navigator.serviceWorker.register(
                '/push-sw.js',
                { scope: '/' },
            );
            await navigator.serviceWorker.ready;
            const subscription =
                (await registration.pushManager.getSubscription()) ??
                (await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: keyBytes(push.public_key),
                }));

            setEndpoint(subscription.endpoint);
            const { endpoint: subscribed, keys } = subscription.toJSON();
            router.post(
                store().url,
                { endpoint: subscribed ?? '', keys: { ...keys } },
                {
                    preserveScroll: true,
                },
            );
        } catch {
            setProblem('failed');
        } finally {
            setBusy(false);
        }
    };

    const remove = (endpointOfDevice: string) => {
        router.delete(destroy().url, {
            data: { endpoint: endpointOfDevice },
            preserveScroll: true,
            onSuccess: async () => {
                // Removing this browser also unsubscribes it, so it stops receiving at once.
                if (endpointOfDevice !== endpoint || !supported()) {
                    return;
                }

                const registration =
                    await navigator.serviceWorker.getRegistration('/');
                await (
                    await registration?.pushManager.getSubscription()
                )?.unsubscribe();
                setEndpoint(null);
            },
        });
    };

    return (
        <div className="space-y-6">
            {heading && (
                <Heading
                    variant="small"
                    title={t('settings.push.title')}
                    description={t('settings.push.description')}
                />
            )}
            <div className="space-y-2">
                {here ? (
                    <p className="text-sm">{t('settings.push.enabled_here')}</p>
                ) : (
                    <Button
                        type="button"
                        variant="outline"
                        disabled={busy}
                        onClick={enable}
                    >
                        {t('settings.push.enable')}
                    </Button>
                )}
                {problem && (
                    <p role="alert" className="text-sm text-destructive">
                        {t(`settings.push.${problem}`)}
                    </p>
                )}
            </div>
            <div className="space-y-2">
                <h3 className="text-sm font-medium">
                    {t('settings.push.devices')}
                </h3>
                {push.devices.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('settings.push.none')}
                    </p>
                ) : (
                    <ul className="divide-y rounded-md border text-sm">
                        {push.devices.map((device) => (
                            <li
                                key={device.endpoint}
                                className="flex items-center justify-between gap-4 px-3 py-2"
                            >
                                <div>
                                    <div className="font-medium">
                                        {device.label ?? '-'}
                                        {device.endpoint === endpoint && (
                                            <span className="ml-2 text-muted-foreground">
                                                (
                                                {t('settings.push.this_device')}
                                                )
                                            </span>
                                        )}
                                    </div>
                                    <div className="text-muted-foreground">
                                        {t('settings.push.added', {
                                            date: formatDateTime(
                                                device.created_at,
                                            ),
                                        })}
                                    </div>
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => remove(device.endpoint)}
                                >
                                    {t('settings.push.remove')}
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}
