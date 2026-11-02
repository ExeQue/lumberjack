import { Head, Link } from '@inertiajs/react';
import { TopBar } from '@/components/lj/top-bar';
import {
    Card,
    EmptyState,
    PageTitle,
    tableClass,
    tdClass,
    thClass,
} from '@/components/lj/ui';
import { PageBody } from '@/layouts/lumberjack-layout';
import { formatDateTime } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types/lumberjack';

type Entry = {
    id: number;
    admin: string | null;
    action: string;
    subject: string | null;
    data: Record<string, unknown> | null;
    at: string;
};

const pagerClass =
    'inline-flex h-8 items-center rounded-md border border-line-strong bg-panel-2 px-3 text-ink';

export default function AdminAudit({ entries }: { entries: Paginated<Entry> }) {
    const t = useT();

    return (
        <>
            <Head title={t('admin.audit.title')} />
            <TopBar
                crumbs={[
                    { label: t('admin.nav.section') },
                    { label: t('admin.audit.title') },
                ]}
            />
            <PageBody>
                <PageTitle
                    title={t('admin.audit.title')}
                    description={t('admin.audit.description')}
                />
                <Card>
                    {entries.data.length === 0 ? (
                        <EmptyState title={t('admin.audit.empty')} />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className={tableClass}>
                                <thead>
                                    <tr>
                                        <th className={thClass}>
                                            {t('admin.audit.when')}
                                        </th>
                                        <th className={thClass}>
                                            {t('admin.audit.admin')}
                                        </th>
                                        <th className={thClass}>
                                            {t('admin.audit.action')}
                                        </th>
                                        <th className={thClass}>
                                            {t('admin.audit.subject')}
                                        </th>
                                        <th className={thClass}>
                                            {t('admin.audit.details')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {entries.data.map((entry) => (
                                        <tr key={entry.id}>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    'whitespace-nowrap text-ink-2',
                                                )}
                                            >
                                                {formatDateTime(entry.at)}
                                            </td>
                                            <td className={tdClass}>
                                                {entry.admin ?? '-'}
                                            </td>
                                            <td className={tdClass}>
                                                {t(
                                                    `admin.audit.actions.${entry.action}`,
                                                )}
                                            </td>
                                            <td className={tdClass}>
                                                {entry.subject ?? '-'}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    'text-ink-2',
                                                )}
                                            >
                                                {entry.data &&
                                                    Object.entries(entry.data)
                                                        .map(
                                                            ([key, value]) =>
                                                                `${key}: ${String(value)}`,
                                                        )
                                                        .join(', ')}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>
                <div className="flex justify-end gap-2">
                    {entries.prev_page_url && (
                        <Link
                            href={entries.prev_page_url}
                            className={pagerClass}
                        >
                            {t('common.previous')}
                        </Link>
                    )}
                    {entries.next_page_url && (
                        <Link
                            href={entries.next_page_url}
                            className={pagerClass}
                        >
                            {t('common.next')}
                        </Link>
                    )}
                </div>
            </PageBody>
        </>
    );
}
