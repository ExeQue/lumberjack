import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Bell, Command, PanelLeft } from 'lucide-react';
import type { ReactNode } from 'react';
import { BrowserNotifications } from '@/components/lj/browser-notifications';
import type { PushSettings } from '@/components/lj/browser-notifications';
import { LogMark } from '@/components/lj/logo';
import {
    NotificationMatrix,
    NotificationPresetPicker,
} from '@/components/lj/notification-settings';
import type {
    NotificationPreferences,
    NotificationPresets,
} from '@/components/lj/notification-settings';
import {
    Button,
    Card,
    Eyebrow,
    LinkButton,
    buttonClass,
} from '@/components/lj/ui';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { update } from '@/routes/notifications';
import { complete, show } from '@/routes/onboarding';
import { show as teamPage } from '@/routes/team';

type Team = { uuid: string; name: string; projects: string[] };

type Props = {
    step: number;
    steps: number;
    teams?: Team[];
    notifications?: NotificationPreferences;
    presets?: NotificationPresets;
    push?: PushSettings;
};

/** Project names shown per team before "and N more". */
const SHOWN_PROJECTS = 6;

/**
 * The first-login onboarding: three short steps, each skippable, outside the app's
 * sidebar. Saving notifications and turning on this browser use the same routes as
 * the account settings.
 */
export default function Onboarding({
    step,
    steps,
    teams,
    notifications,
    presets,
    push,
}: Props) {
    const t = useT();

    return (
        <div className="flex min-h-svh justify-center bg-ground px-4 py-6 text-[13px] text-ink sm:items-center sm:py-10">
            <Head title={t('onboarding.title')} />
            <div className="flex w-full max-w-2xl flex-col gap-4">
                <div className="flex items-center justify-between gap-3">
                    <span className="flex items-center gap-2 text-[15px] font-semibold tracking-tight">
                        <LogMark />
                        Lumberjack
                    </span>
                    <Link
                        href={complete()}
                        as="button"
                        className={buttonClass('ghost')}
                    >
                        {t('onboarding.skip')}
                    </Link>
                </div>

                <Card className="flex flex-col gap-5 p-5 sm:p-6">
                    <div className="flex flex-col gap-2">
                        <Eyebrow>
                            {t('onboarding.step', { step, steps })}
                        </Eyebrow>
                        <div className="flex gap-1.5" aria-hidden="true">
                            {Array.from({ length: steps }, (_, i) => (
                                <span
                                    key={i}
                                    className={cn(
                                        'h-1 flex-1 rounded-full',
                                        i < step ? 'bg-amber' : 'bg-line',
                                    )}
                                />
                            ))}
                        </div>
                    </div>

                    {step === 1 && <Welcome teams={teams ?? []} />}
                    {step === 2 && notifications && presets && (
                        <Notifications
                            notifications={notifications}
                            presets={presets}
                        />
                    )}
                    {step === 3 && push && <Push push={push} />}
                </Card>
            </div>
        </div>
    );
}

function Footer({ step, children }: { step: number; children: ReactNode }) {
    const t = useT();

    return (
        <div className="flex items-center justify-between gap-3 border-t border-line pt-4">
            {step > 1 ? (
                <LinkButton href={show(step - 1)}>
                    {t('onboarding.back')}
                </LinkButton>
            ) : (
                <span />
            )}
            {children}
        </div>
    );
}

function Welcome({ teams }: { teams: Team[] }) {
    const t = useT();
    const { auth } = usePage<{ auth: { user: { name: string } } }>().props;
    const mac =
        typeof navigator !== 'undefined' &&
        /Mac|iPhone|iPad/.test(navigator.userAgent);

    const places: { icon: ReactNode; text: string }[] = [
        {
            icon: <PanelLeft className="size-4" />,
            text: t('onboarding.welcome.sidebar'),
        },
        {
            icon: <Command className="size-4" />,
            text: t('onboarding.welcome.palette', {
                keys: mac ? 'Cmd+K' : 'Ctrl+K',
            }),
        },
        {
            icon: <Bell className="size-4" />,
            text: t('onboarding.welcome.bell'),
        },
    ];

    return (
        <>
            <div className="flex flex-col gap-1">
                <h1 className="text-xl font-semibold tracking-tight">
                    {t('onboarding.welcome.title', { name: auth.user.name })}
                </h1>
                <p className="text-ink-2">{t('onboarding.welcome.intro')}</p>
            </div>

            <section className="flex flex-col gap-2">
                <h2 className="text-[13px] font-semibold">
                    {t('onboarding.welcome.teams')}
                </h2>
                {teams.length === 0 ? (
                    <div className="flex flex-col items-start gap-3 rounded-md border border-line bg-panel-2 p-3">
                        <p className="text-ink-2">
                            {t('onboarding.welcome.no_team')}
                        </p>
                        <Link
                            href={teamPage()}
                            className="text-amber underline-offset-4 hover:underline"
                        >
                            {t('onboarding.welcome.team_page')}
                        </Link>
                    </div>
                ) : (
                    <ul className="flex flex-col divide-y divide-line rounded-md border border-line">
                        {teams.map((team) => (
                            <li
                                key={team.uuid}
                                className="flex flex-col gap-1 px-3 py-2.5"
                            >
                                <span className="flex flex-wrap items-baseline justify-between gap-2">
                                    <span className="font-medium">
                                        {team.name}
                                    </span>
                                    <span className="text-xs text-ink-3">
                                        {t(
                                            'onboarding.welcome.projects',
                                            {},
                                            team.projects.length,
                                        )}
                                    </span>
                                </span>
                                {team.projects.length > 0 && (
                                    <span className="text-ink-2">
                                        {team.projects
                                            .slice(0, SHOWN_PROJECTS)
                                            .join(', ')}
                                        {team.projects.length >
                                            SHOWN_PROJECTS &&
                                            ` ${t('onboarding.welcome.more', { count: team.projects.length - SHOWN_PROJECTS })}`}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <section className="flex flex-col gap-2">
                <h2 className="text-[13px] font-semibold">
                    {t('onboarding.welcome.where')}
                </h2>
                <ul className="flex flex-col gap-2">
                    {places.map((place) => (
                        <li
                            key={place.text}
                            className="flex gap-2.5 text-ink-2"
                        >
                            <span
                                aria-hidden="true"
                                className="mt-0.5 flex-none text-amber"
                            >
                                {place.icon}
                            </span>
                            {place.text}
                        </li>
                    ))}
                </ul>
            </section>

            <Footer step={1}>
                <LinkButton variant="primary" href={show(2)}>
                    {t('onboarding.next')}
                </LinkButton>
            </Footer>
        </>
    );
}

function Notifications({
    notifications,
    presets,
}: {
    notifications: NotificationPreferences;
    presets: NotificationPresets;
}) {
    const t = useT();
    const form = useForm<NotificationPreferences>(notifications);

    return (
        <form
            className="flex flex-col gap-5"
            onSubmit={(e) => {
                e.preventDefault();

                if (!form.isDirty) {
                    router.visit(show(3));

                    return;
                }

                form.put(update().url, {
                    preserveScroll: true,
                    onSuccess: () => router.visit(show(3)),
                });
            }}
        >
            <div className="flex flex-col gap-1">
                <h1 className="text-xl font-semibold tracking-tight">
                    {t('onboarding.notifications.title')}
                </h1>
                <p className="text-ink-2">
                    {t('onboarding.notifications.intro')}
                </p>
            </div>
            <NotificationPresetPicker
                presets={presets}
                value={form.data}
                onChange={form.setData}
            />
            <div className="overflow-x-auto">
                <NotificationMatrix value={form.data} onChange={form.setData} />
            </div>
            <Footer step={2}>
                <Button
                    type="submit"
                    variant="primary"
                    disabled={form.processing}
                >
                    {t('onboarding.next')}
                </Button>
            </Footer>
        </form>
    );
}

function Push({ push }: { push: PushSettings }) {
    const t = useT();

    return (
        <>
            <div className="flex flex-col gap-1">
                <h1 className="text-xl font-semibold tracking-tight">
                    {t('onboarding.push.title')}
                </h1>
                <p className="text-ink-2">{t('onboarding.push.intro')}</p>
            </div>
            <BrowserNotifications push={push} />
            <Footer step={3}>
                <Link
                    href={complete()}
                    as="button"
                    className={buttonClass(
                        push.devices.length > 0 ? 'primary' : 'default',
                    )}
                >
                    {push.devices.length > 0
                        ? t('onboarding.done')
                        : t('onboarding.not_now')}
                </Link>
            </Footer>
        </>
    );
}
