import { Head, Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { TopBar } from '@/components/lj/top-bar';
import { PageTitle, navActiveClass } from '@/components/lj/ui';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { PageBody } from '@/layouts/lumberjack-layout';
import { useT } from '@/lib/i18n';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editNotifications } from '@/routes/notifications';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';

const sections = [
    {
        key: 'profile',
        href: edit(),
        description: 'settings.account.profile_description',
    },
    {
        key: 'notifications',
        href: editNotifications(),
        description: 'settings.notifications.description',
    },
    {
        key: 'security',
        href: editSecurity(),
        description: 'settings.account.security_description',
    },
    {
        key: 'appearance',
        href: editAppearance(),
        description: 'settings.account.appearance_description',
    },
] as const;

/**
 * Your own account, laid out like the rest of Lumberjack: a top bar, a title, and the
 * sections on the left as in a project's settings.
 */
export default function SettingsLayout({ children }: PropsWithChildren) {
    const t = useT();
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const current = sections.find((section) =>
        isCurrentOrParentUrl(section.href),
    );
    const title = current
        ? t(`settings.account.${current.key}`)
        : t('settings.account.title');

    return (
        <>
            <Head
                title={
                    current
                        ? `${title} · ${t('settings.account.title')}`
                        : title
                }
            />
            <TopBar
                crumbs={[
                    { label: t('settings.account.title'), href: toUrl(edit()) },
                    ...(current ? [{ label: title }] : []),
                ]}
            />
            <PageBody>
                <PageTitle
                    title={title}
                    description={t(
                        current?.description ?? 'settings.account.description',
                    )}
                />
                <div className="grid gap-6 lg:grid-cols-[200px_1fr]">
                    <nav
                        aria-label={t('settings.account.title')}
                        className="flex flex-wrap gap-1 lg:flex-col"
                    >
                        {sections.map((section) => {
                            const active = section === current;

                            return (
                                <Link
                                    key={toUrl(section.href)}
                                    href={section.href}
                                    aria-current={active ? 'page' : undefined}
                                    className={cn(
                                        'lj-nav-item rounded-md px-2.5 py-1.5 text-[13px] text-ink-2 hover:bg-panel-2 hover:text-ink',
                                        active && navActiveClass,
                                    )}
                                >
                                    {t(`settings.account.${section.key}`)}
                                </Link>
                            );
                        })}
                    </nav>
                    <section className="flex max-w-xl min-w-0 flex-col gap-12">
                        {children}
                    </section>
                </div>
            </PageBody>
        </>
    );
}
