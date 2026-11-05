import { Head, Link, router } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { Relative } from '@/components/lj/relative';
import { TopBar } from '@/components/lj/top-bar';
import {
    Button,
    Card,
    EmptyState,
    PageTitle,
    Segmented,
    Select,
} from '@/components/lj/ui';
import { PageBody } from '@/layouts/lumberjack-layout';
import { useT } from '@/lib/i18n';
import { NOTIFICATION_KINDS } from '@/lib/notification-kinds';
import type { CenterNotification } from '@/lib/notification-kinds';
import { cn } from '@/lib/utils';
import { index, mark, readAll } from '@/routes/notifications';

type Filters = { unread: boolean; kind: string | null };

const pagerClass =
    'inline-flex h-8 items-center rounded-md border border-line-strong bg-panel-2 px-3 text-ink';

export default function Notifications({
    notifications,
    next_url,
    prev_url,
    filters,
    kinds,
}: {
    notifications: CenterNotification[];
    next_url: string | null;
    prev_url: string | null;
    filters: Filters;
    kinds: string[];
}) {
    const t = useT();

    const filter = (next: Partial<Filters>) => {
        const merged = { ...filters, ...next };
        router.get(
            index({
                query: {
                    unread: merged.unread ? 1 : undefined,
                    kind: merged.kind ?? undefined,
                },
            }).url,
            {},
            { preserveScroll: true },
        );
    };

    const setRead = (item: CenterNotification, read: boolean) =>
        router.put(mark(item.id).url, { read }, { preserveScroll: true });

    const open = (item: CenterNotification) => {
        if (item.read) {
            router.visit(item.url);

            return;
        }

        router.put(
            mark(item.id).url,
            { read: true },
            { onSuccess: () => router.visit(item.url) },
        );
    };

    return (
        <>
            <Head title={t('notifications.title')} />
            <TopBar crumbs={[{ label: t('notifications.title') }]} />
            <PageBody>
                <PageTitle
                    title={t('notifications.title')}
                    description={t('notifications.description')}
                    actions={
                        <Button
                            type="button"
                            onClick={() =>
                                router.post(
                                    readAll().url,
                                    { kind: filters.kind },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {t('notifications.mark_all_read')}
                        </Button>
                    }
                />
                <div className="flex flex-wrap items-center gap-2">
                    <Segmented
                        label={t('notifications.show')}
                        value={filters.unread ? 'unread' : 'all'}
                        options={[
                            { value: 'all', label: t('notifications.all') },
                            {
                                value: 'unread',
                                label: t('notifications.unread'),
                            },
                        ]}
                        onChange={(value) =>
                            filter({ unread: value === 'unread' })
                        }
                    />
                    <Select
                        aria-label={t('notifications.all_kinds')}
                        value={filters.kind ?? ''}
                        onChange={(e) =>
                            filter({ kind: e.target.value || null })
                        }
                    >
                        <option value="">{t('notifications.all_kinds')}</option>
                        {kinds.map((kind) => (
                            <option key={kind} value={kind}>
                                {t(`settings.notifications.${kind}.label`)}
                            </option>
                        ))}
                    </Select>
                </div>
                <Card>
                    {notifications.length === 0 ? (
                        <EmptyState
                            title={
                                filters.unread || filters.kind
                                    ? t('notifications.empty_filtered')
                                    : t('notifications.empty')
                            }
                        />
                    ) : (
                        <ul className="divide-y divide-line/60">
                            {notifications.map((item) => {
                                const Icon =
                                    NOTIFICATION_KINDS[item.kind] ?? Bell;

                                return (
                                    <li
                                        key={item.id}
                                        className={cn(
                                            'flex items-start gap-3 px-3 py-2.5',
                                            !item.read && 'bg-amber/5',
                                        )}
                                    >
                                        <Icon
                                            className="mt-0.5 size-4 shrink-0 text-ink-3"
                                            aria-hidden="true"
                                        />
                                        <button
                                            type="button"
                                            onClick={() => open(item)}
                                            className="flex min-w-0 flex-1 cursor-pointer flex-col text-left"
                                        >
                                            <span
                                                className={cn(
                                                    'line-clamp-2 break-words',
                                                    !item.read &&
                                                        'font-medium text-ink',
                                                )}
                                            >
                                                {item.title}
                                            </span>
                                            <span className="truncate text-[12px] text-ink-2">
                                                {item.body}
                                            </span>
                                            <span className="text-[11px] text-ink-3">
                                                {[item.project, item.team]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                                {item.project && ' · '}
                                                <Relative
                                                    iso={item.created_at}
                                                />
                                            </span>
                                        </button>
                                        {!item.read && (
                                            <span
                                                className="mt-1.5 size-2 shrink-0 rounded-full bg-amber"
                                                aria-label={t(
                                                    'notifications.unread',
                                                )}
                                            />
                                        )}
                                        <button
                                            type="button"
                                            onClick={() =>
                                                setRead(item, !item.read)
                                            }
                                            className="-my-1 inline-flex min-h-8 shrink-0 cursor-pointer items-center rounded-md px-2 text-[12px] text-ink-3 hover:bg-panel-2 hover:text-ink"
                                        >
                                            {item.read
                                                ? t('notifications.mark_unread')
                                                : t('notifications.mark_read')}
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </Card>
                <div className="flex justify-end gap-2">
                    {prev_url && (
                        <Link href={prev_url} className={pagerClass}>
                            {t('notifications.newer')}
                        </Link>
                    )}
                    {next_url && (
                        <Link href={next_url} className={pagerClass}>
                            {t('notifications.older')}
                        </Link>
                    )}
                </div>
            </PageBody>
        </>
    );
}
