import { Link } from '@inertiajs/react';
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
    { key: 'profile', href: edit() },
    { key: 'notifications', href: editNotifications() },
    { key: 'security', href: editSecurity() },
    { key: 'appearance', href: editAppearance() },
] as const;

/**
 * Your own account, laid out like the rest of Lumberjack: a top bar, a title, and the
 * sections on the left as in a project's settings.
 */
export default function SettingsLayout({ children }: PropsWithChildren) {
    const t = useT();
    const { isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <>
            <TopBar crumbs={[{ label: t('settings.account.title') }]} />
            <PageBody>
                <PageTitle
                    title={t('settings.account.title')}
                    description={t('settings.account.description')}
                />
                <div className="grid gap-6 lg:grid-cols-[200px_1fr]">
                    <nav
                        aria-label={t('settings.account.title')}
                        className="flex flex-wrap gap-1 lg:flex-col"
                    >
                        {sections.map((section) => {
                            const current = isCurrentOrParentUrl(section.href);

                            return (
                                <Link
                                    key={toUrl(section.href)}
                                    href={section.href}
                                    aria-current={current ? 'page' : undefined}
                                    className={cn(
                                        'rounded-md px-2.5 py-1.5 text-[13px] text-ink-2 hover:bg-panel-2 hover:text-ink',
                                        current && navActiveClass,
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
