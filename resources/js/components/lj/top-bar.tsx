import { Link, router, usePage } from '@inertiajs/react';
import { Fragment } from 'react';
import type { ReactNode } from 'react';
import { Segmented, Select } from '@/components/lj/ui';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { useSectionTabs } from '@/lib/use-navigation';
import type { DashboardFilters, Period } from '@/types/lumberjack';

export type Crumb = { label: string; href?: string; mono?: boolean };

export function TopBar({
    crumbs,
    children,
}: {
    crumbs: Crumb[];
    children?: ReactNode;
}) {
    const t = useT();
    const tabs = useSectionTabs();

    return (
        <>
            <header className="flex min-h-[57px] flex-wrap items-center gap-3 border-b border-line px-4 py-3 sm:px-7">
                <nav
                    aria-label={t('common.breadcrumb')}
                    className="flex min-w-0 flex-1 flex-wrap items-center gap-2 text-ink-3"
                >
                    {crumbs.map((crumb, i) => {
                        const last = i === crumbs.length - 1;

                        return (
                            <Fragment key={i}>
                                {i > 0 && <span aria-hidden="true">/</span>}
                                {last || !crumb.href ? (
                                    <span
                                        aria-current={last ? 'page' : undefined}
                                        className={
                                            last
                                                ? 'font-medium text-ink'
                                                : crumb.mono
                                                  ? 'font-mono text-ink-2'
                                                  : ''
                                        }
                                    >
                                        {crumb.label}
                                    </span>
                                ) : (
                                    <Link
                                        href={crumb.href}
                                        className={
                                            crumb.mono
                                                ? 'font-mono text-ink-2 hover:text-ink'
                                                : 'hover:text-ink'
                                        }
                                    >
                                        {crumb.label}
                                    </Link>
                                )}
                            </Fragment>
                        );
                    })}
                </nav>
                {children}
            </header>
            {tabs && (
                <nav
                    aria-label={t('common.nav.sections')}
                    className="flex gap-1 overflow-x-auto overflow-y-hidden border-b border-line px-4 sm:px-7"
                >
                    {tabs.map((tab) => (
                        <Link
                            key={tab.href}
                            href={tab.href}
                            aria-current={tab.active ? 'page' : undefined}
                            className={cn(
                                '-mb-px flex h-10 shrink-0 items-center gap-1.5 border-b-2 px-3 text-[13px]',
                                tab.active
                                    ? 'border-amber font-medium text-ink'
                                    : 'border-transparent text-ink-3 hover:text-ink',
                            )}
                        >
                            {tab.label}
                            {tab.count !== undefined && tab.count > 0 && (
                                <span className="font-mono text-[11px] text-err">
                                    {tab.count > 99 ? '99+' : tab.count}
                                </span>
                            )}
                        </Link>
                    ))}
                </nav>
            )}
        </>
    );
}

/**
 * Period and environment live in the query string; changing them reloads the page's props.
 */
export function DashboardFilterControls({
    filters,
    environments,
    periods = ['1h', '24h', '7d', '30d'],
}: {
    filters: DashboardFilters;
    environments: string[];
    periods?: Period[];
}) {
    const t = useT();
    const { url } = usePage();

    const update = (changes: Partial<DashboardFilters>) => {
        const params = new URLSearchParams(url.split('?')[1] ?? '');

        Object.entries(changes).forEach(([key, value]) =>
            value ? params.set(key, String(value)) : params.delete(key),
        );
        params.delete('page');

        router.get(
            `${url.split('?')[0]}?${params.toString()}`,
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <>
            <label className="sr-only" htmlFor="filter-environment">
                {t('common.filters.environment')}
            </label>
            <Select
                id="filter-environment"
                value={filters.environment ?? ''}
                onChange={(e) =>
                    update({ environment: e.target.value || null })
                }
            >
                <option value="">{t('common.filters.all_environments')}</option>
                {environments.map((environment) => (
                    <option key={environment} value={environment}>
                        {environment}
                    </option>
                ))}
            </Select>
            <Segmented<Period>
                label={t('common.filters.period')}
                value={filters.period}
                onChange={(period) => update({ period })}
                options={periods.map((period) => ({
                    value: period,
                    label: t(`common.periods.${period}`),
                }))}
            />
        </>
    );
}
