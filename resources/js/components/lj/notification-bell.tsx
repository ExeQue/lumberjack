import { Link, router, usePage } from '@inertiajs/react';
import { useEcho, useEchoNotification } from '@laravel/echo-react';
import { Bell } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Relative } from '@/components/lj/relative';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { sendJson } from '@/lib/http';
import { useT } from '@/lib/i18n';
import { NOTIFICATION_KINDS, unreadLabel } from '@/lib/notification-kinds';
import type { CenterNotification } from '@/lib/notification-kinds';
import { cn } from '@/lib/utils';
import { edit, index, mark, readAll, recent } from '@/routes/notifications';

/** Personal toasts that are kept in the center too, but arrive by their own broadcast. */
const PERSONAL_EVENTS = [
    '.note.mentioned',
    '.issue.assigned',
    '.issue.unassigned',
    '.issue.watched',
];

/**
 * The bell: how many notifications are unread, kept live, and the latest few in a list
 * that loads when it opens. Opening one marks it read.
 */
export function NotificationBell() {
    const t = useT();
    const { notifications_unread, auth } = usePage<{
        notifications_unread: number;
        auth: { user: { uuid: string } };
    }>().props;
    const [unread, setUnread] = useState(notifications_unread);
    const [items, setItems] = useState<CenterNotification[] | null>(null);
    const isOpen = useRef(false);

    useEffect(() => setUnread(notifications_unread), [notifications_unread]);

    // An open list loads afresh; a closed one when it next opens.
    const arrived = () => {
        setUnread((count) => count + 1);
        setItems(null);

        if (isOpen.current) {
            void load();
        }
    };

    useEchoNotification<{ type: string }>(`user.${auth.user.uuid}`, (event) => {
        if (event.type === 'lumberjack.notification') {
            arrived();
        }
    });
    useEcho(`user.${auth.user.uuid}`, PERSONAL_EVENTS, arrived);

    const load = () =>
        fetch(recent().url, { headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : null))
            .then(
                (
                    data: {
                        notifications: CenterNotification[];
                        unread: number;
                    } | null,
                ) => {
                    if (data) {
                        setItems(data.notifications);
                        setUnread(data.unread);
                    }
                },
            )
            .catch(() => {});

    const open = async (item: CenterNotification) => {
        if (!item.read) {
            setUnread((count) => Math.max(0, count - 1));
            await sendJson(mark(item.id).url, 'PUT', { read: true }).catch(
                () => null,
            );
        }

        setItems(null);
        router.visit(item.url);
    };

    const markAll = async () => {
        setUnread(0);
        setItems(
            (list) => list?.map((item) => ({ ...item, read: true })) ?? null,
        );
        await sendJson(readAll().url, 'POST').catch(() => null);
    };

    return (
        <DropdownMenu
            onOpenChange={(open) => {
                isOpen.current = open;

                if (open && items === null) {
                    void load();
                }
            }}
        >
            <DropdownMenuTrigger
                aria-label={t('notifications.bell', {
                    count: unreadLabel(unread),
                })}
                className="relative inline-flex size-8 cursor-pointer items-center justify-center rounded-md text-ink-3 hover:bg-panel hover:text-ink"
            >
                <Bell className="size-4" aria-hidden="true" />
                {unread > 0 && (
                    <span
                        aria-hidden="true"
                        className="absolute -top-0.5 -right-0.5 min-w-4 rounded-full bg-amber px-1 text-center text-[10px] leading-4 font-semibold text-black"
                    >
                        {unreadLabel(unread)}
                    </span>
                )}
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-80">
                <div className="flex items-center justify-between gap-2">
                    <DropdownMenuLabel>
                        {t('notifications.title')}
                    </DropdownMenuLabel>
                    {unread > 0 && (
                        <button
                            type="button"
                            onClick={markAll}
                            className="cursor-pointer px-2 text-[12px] text-ink-3 hover:text-ink"
                        >
                            {t('notifications.mark_all_read')}
                        </button>
                    )}
                </div>
                <DropdownMenuSeparator />
                {items === null ? (
                    <p className="px-2 py-3 text-[12px] text-ink-3">
                        {t('notifications.loading')}
                    </p>
                ) : items.length === 0 ? (
                    <p className="px-2 py-3 text-[12px] text-ink-3">
                        {t('notifications.empty')}
                    </p>
                ) : (
                    items.map((item) => {
                        const Icon = NOTIFICATION_KINDS[item.kind] ?? Bell;

                        return (
                            <DropdownMenuItem
                                key={item.id}
                                onSelect={() => open(item)}
                                className="items-start gap-2.5"
                            >
                                <Icon
                                    className="mt-0.5 size-4 shrink-0 text-ink-3"
                                    aria-hidden="true"
                                />
                                <span className="flex min-w-0 flex-1 flex-col">
                                    <span
                                        className={cn(
                                            'truncate',
                                            !item.read && 'font-medium',
                                        )}
                                    >
                                        {item.title}
                                    </span>
                                    <span className="truncate text-[12px] text-ink-3">
                                        {item.body}
                                    </span>
                                    <span className="truncate text-[11px] text-ink-3">
                                        {[item.project, item.team]
                                            .filter(Boolean)
                                            .join(' · ')}
                                        {item.project && ' · '}
                                        <Relative iso={item.created_at} />
                                    </span>
                                </span>
                                {!item.read && (
                                    <span
                                        className="mt-1.5 size-2 shrink-0 rounded-full bg-amber"
                                        aria-label={t('notifications.unread')}
                                    />
                                )}
                            </DropdownMenuItem>
                        );
                    })
                )}
                <DropdownMenuSeparator />
                <div className="flex items-center justify-between">
                    <DropdownMenuItem asChild>
                        <Link href={index().url}>
                            {t('notifications.see_all')}
                        </Link>
                    </DropdownMenuItem>
                    <DropdownMenuItem asChild>
                        <Link href={edit().url} className="text-ink-3">
                            {t('notifications.settings')}
                        </Link>
                    </DropdownMenuItem>
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
