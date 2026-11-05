import { Relative } from '@/components/lj/relative';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { TopBar } from '@/components/lj/top-bar';
import {
    Badge,
    Button,
    buttonClass,
    Card,
    CardHeader,
    EmptyState,
    Field,
    Input,
    PageTitle,
    Select,
    tableClass,
    tdClass,
    thClass,
} from '@/components/lj/ui';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { PageBody } from '@/layouts/lumberjack-layout';
import { useT } from '@/lib/i18n';
import { useAdminLive } from '@/lib/use-live';
import { cn } from '@/lib/utils';
import {
    admin as setAdmin,
    index,
    invite,
    passwordChange,
    reset,
    suspend,
} from '@/routes/admin/users';
import type { Paginated } from '@/types/lumberjack';

type User = {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    suspended: boolean;
    password_change_required: boolean;
    teams: string[];
    created_at: string | null;
};

type Props = {
    filters: { search: string };
    users: Paginated<User>;
    teams: { uuid: string; name: string }[];
};

const pagerClass =
    'inline-flex h-8 items-center rounded-md border border-line-strong bg-panel-2 px-3 text-ink';
const keep = { preserveScroll: true };

type Confirming = {
    question: string;
    label: string;
    danger?: boolean;
    run: () => void;
};

export default function AdminUsers({ filters, users, teams }: Props) {
    const t = useT();
    useAdminLive(['users.changed', 'teams.changed'], ['users', 'teams']);
    const { auth } = usePage<{ auth: { user: { id: number } } }>().props;
    const [search, setSearch] = useState(filters.search);
    // The row actions live in a menu, which closes on select, so their question is asked here.
    const [confirming, setConfirming] = useState<Confirming | null>(null);
    // The dialog keeps its text while it animates closed.
    const lastConfirming = useRef<Confirming | null>(null);
    lastConfirming.current = confirming ?? lastConfirming.current;
    const shown = lastConfirming.current;
    const form = useForm({
        email: '',
        team: teams[0]?.uuid ?? '',
        role: 'member',
    });

    return (
        <>
            <Head title={t('admin.users.title')} />
            <TopBar
                crumbs={[
                    { label: t('admin.nav.section') },
                    { label: t('admin.users.title') },
                ]}
            />
            <PageBody>
                <PageTitle
                    title={t('admin.users.title')}
                    description={t('admin.users.description')}
                />

                <form
                    role="search"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get(
                            index().url,
                            search === '' ? {} : { search },
                            { preserveState: true },
                        );
                    }}
                >
                    <label htmlFor="user-search" className="sr-only">
                        {t('admin.users.search')}
                    </label>
                    <Input
                        id="user-search"
                        type="search"
                        className="w-full max-w-sm"
                        placeholder={t('admin.users.search')}
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                </form>

                <Card>
                    {users.data.length === 0 ? (
                        <EmptyState title={t('admin.users.empty')} />
                    ) : (
                        <div className="relative overflow-x-auto">
                            <table className={tableClass}>
                                <thead>
                                    <tr>
                                        <th className={thClass}>
                                            {t('admin.users.name')}
                                        </th>
                                        <th className={thClass}>
                                            {t('admin.users.teams')}
                                        </th>
                                        <th className={thClass}>
                                            {t('admin.users.status')}
                                        </th>
                                        <th
                                            className={cn(
                                                thClass,
                                                'hidden md:table-cell',
                                            )}
                                        >
                                            {t('admin.users.joined')}
                                        </th>
                                        <th className={thClass}>
                                            <span className="sr-only">
                                                {t('team.actions')}
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {users.data.map((user) => {
                                        const you = user.id === auth.user.id;

                                        return (
                                            <tr key={user.id}>
                                                <td className={tdClass}>
                                                    <div>
                                                        {user.name}{' '}
                                                        {you && (
                                                            <span className="text-xs text-ink-3">
                                                                {t(
                                                                    'admin.users.you',
                                                                )}
                                                            </span>
                                                        )}
                                                    </div>
                                                    <div className="text-xs text-ink-2">
                                                        {user.email}
                                                    </div>
                                                </td>
                                                <td
                                                    className={cn(
                                                        tdClass,
                                                        'text-ink-2',
                                                    )}
                                                >
                                                    {user.teams.join(', ') ||
                                                        '-'}
                                                </td>
                                                <td className={tdClass}>
                                                    <span className="inline-flex flex-wrap gap-1">
                                                        {user.is_admin && (
                                                            <Badge tone="amber">
                                                                {t(
                                                                    'admin.users.badges.admin',
                                                                )}
                                                            </Badge>
                                                        )}
                                                        {user.suspended && (
                                                            <Badge tone="err">
                                                                {t(
                                                                    'admin.users.badges.suspended',
                                                                )}
                                                            </Badge>
                                                        )}
                                                        {user.password_change_required && (
                                                            <Badge tone="warn">
                                                                {t(
                                                                    'admin.users.badges.password_change',
                                                                )}
                                                            </Badge>
                                                        )}
                                                        {!user.is_admin &&
                                                            !user.suspended &&
                                                            !user.password_change_required && (
                                                                <span className="text-ink-2">
                                                                    {t(
                                                                        'admin.users.badges.active',
                                                                    )}
                                                                </span>
                                                            )}
                                                    </span>
                                                </td>
                                                <td
                                                    className={cn(
                                                        tdClass,
                                                        'hidden whitespace-nowrap text-ink-2 md:table-cell',
                                                    )}
                                                >
                                                    <Relative
                                                        iso={user.created_at}
                                                    />
                                                </td>
                                                <td
                                                    className={cn(
                                                        tdClass,
                                                        'text-right',
                                                    )}
                                                >
                                                    <DropdownMenu>
                                                        <DropdownMenuTrigger
                                                            className={buttonClass()}
                                                        >
                                                            {t(
                                                                'admin.users.more',
                                                            )}
                                                        </DropdownMenuTrigger>
                                                        <DropdownMenuContent align="end">
                                                            <DropdownMenuItem
                                                                onSelect={() =>
                                                                    router.post(
                                                                        reset(
                                                                            user.id,
                                                                        ).url,
                                                                        {},
                                                                        keep,
                                                                    )
                                                                }
                                                            >
                                                                {t(
                                                                    'admin.users.send_reset',
                                                                )}
                                                            </DropdownMenuItem>
                                                            {!you &&
                                                                !user.password_change_required && (
                                                                    <DropdownMenuItem
                                                                        onSelect={() =>
                                                                            setConfirming(
                                                                                {
                                                                                    question:
                                                                                        t(
                                                                                            'admin.users.require_password_change_question',
                                                                                            {
                                                                                                name: user.name,
                                                                                            },
                                                                                        ),
                                                                                    label: t(
                                                                                        'admin.users.require_password_change',
                                                                                    ),
                                                                                    run: () =>
                                                                                        router.put(
                                                                                            passwordChange(
                                                                                                user.id,
                                                                                            )
                                                                                                .url,
                                                                                            {},
                                                                                            keep,
                                                                                        ),
                                                                                },
                                                                            )
                                                                        }
                                                                    >
                                                                        {t(
                                                                            'admin.users.require_password_change',
                                                                        )}
                                                                    </DropdownMenuItem>
                                                                )}
                                                            {!you && (
                                                                <DropdownMenuItem
                                                                    onSelect={() =>
                                                                        setConfirming(
                                                                            {
                                                                                question:
                                                                                    t(
                                                                                        user.is_admin
                                                                                            ? 'admin.users.revoke_admin_question'
                                                                                            : 'admin.users.make_admin_question',
                                                                                        {
                                                                                            name: user.name,
                                                                                        },
                                                                                    ),
                                                                                label: t(
                                                                                    user.is_admin
                                                                                        ? 'admin.users.revoke_admin'
                                                                                        : 'admin.users.make_admin',
                                                                                ),
                                                                                danger: user.is_admin,
                                                                                run: () =>
                                                                                    router.put(
                                                                                        setAdmin(
                                                                                            user.id,
                                                                                        )
                                                                                            .url,
                                                                                        {
                                                                                            is_admin:
                                                                                                !user.is_admin,
                                                                                        },
                                                                                        keep,
                                                                                    ),
                                                                            },
                                                                        )
                                                                    }
                                                                >
                                                                    {t(
                                                                        user.is_admin
                                                                            ? 'admin.users.revoke_admin'
                                                                            : 'admin.users.make_admin',
                                                                    )}
                                                                </DropdownMenuItem>
                                                            )}
                                                            {!you &&
                                                                (user.suspended ? (
                                                                    <DropdownMenuItem
                                                                        onSelect={() =>
                                                                            router.put(
                                                                                suspend(
                                                                                    user.id,
                                                                                )
                                                                                    .url,
                                                                                {
                                                                                    suspended: false,
                                                                                },
                                                                                keep,
                                                                            )
                                                                        }
                                                                    >
                                                                        {t(
                                                                            'admin.users.reinstate',
                                                                        )}
                                                                    </DropdownMenuItem>
                                                                ) : (
                                                                    <>
                                                                        <DropdownMenuSeparator />
                                                                        <DropdownMenuItem
                                                                            className="text-err focus:text-err"
                                                                            onSelect={() =>
                                                                                setConfirming(
                                                                                    {
                                                                                        question:
                                                                                            t(
                                                                                                'admin.users.suspend_question',
                                                                                                {
                                                                                                    name: user.name,
                                                                                                },
                                                                                            ),
                                                                                        label: t(
                                                                                            'admin.users.suspend',
                                                                                        ),
                                                                                        danger: true,
                                                                                        run: () =>
                                                                                            router.put(
                                                                                                suspend(
                                                                                                    user.id,
                                                                                                )
                                                                                                    .url,
                                                                                                {
                                                                                                    suspended: true,
                                                                                                },
                                                                                                keep,
                                                                                            ),
                                                                                    },
                                                                                )
                                                                            }
                                                                        >
                                                                            {t(
                                                                                'admin.users.suspend',
                                                                            )}
                                                                        </DropdownMenuItem>
                                                                    </>
                                                                ))}
                                                        </DropdownMenuContent>
                                                    </DropdownMenu>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>

                <div className="flex justify-end gap-2">
                    {users.prev_page_url && (
                        <Link href={users.prev_page_url} className={pagerClass}>
                            {t('common.previous')}
                        </Link>
                    )}
                    {users.next_page_url && (
                        <Link href={users.next_page_url} className={pagerClass}>
                            {t('common.next')}
                        </Link>
                    )}
                </div>

                {teams.length > 0 && (
                    <Card aria-labelledby="h-invite">
                        <CardHeader
                            id="h-invite"
                            title={t('admin.users.invite.title')}
                            description={t('admin.users.invite.description')}
                        />
                        <form
                            className="flex flex-wrap items-end gap-3 p-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post(invite().url, {
                                    preserveScroll: true,
                                    onSuccess: () => form.reset('email'),
                                });
                            }}
                        >
                            <Field
                                label={t('admin.users.invite.email')}
                                htmlFor="invite-email"
                                error={form.errors.email}
                            >
                                <Input
                                    id="invite-email"
                                    type="email"
                                    required
                                    value={form.data.email}
                                    onChange={(e) =>
                                        form.setData('email', e.target.value)
                                    }
                                />
                            </Field>
                            <Field
                                label={t('admin.users.invite.team')}
                                htmlFor="invite-team"
                                error={form.errors.team}
                            >
                                <Select
                                    id="invite-team"
                                    value={form.data.team}
                                    onChange={(e) =>
                                        form.setData('team', e.target.value)
                                    }
                                >
                                    {teams.map((team) => (
                                        <option
                                            key={team.uuid}
                                            value={team.uuid}
                                        >
                                            {team.name}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <Field
                                label={t('admin.users.invite.role')}
                                htmlFor="invite-role"
                                error={form.errors.role}
                            >
                                <Select
                                    id="invite-role"
                                    value={form.data.role}
                                    onChange={(e) =>
                                        form.setData('role', e.target.value)
                                    }
                                >
                                    <option value="member">
                                        {t('common.roles.member')}
                                    </option>
                                    <option value="admin">
                                        {t('common.roles.admin')}
                                    </option>
                                </Select>
                            </Field>
                            <Button
                                type="submit"
                                variant="primary"
                                disabled={form.processing}
                            >
                                {t('admin.users.invite.send')}
                            </Button>
                        </form>
                    </Card>
                )}
                <Dialog
                    open={confirming !== null}
                    onOpenChange={(open) => !open && setConfirming(null)}
                >
                    <DialogContent className="gap-5 border-line bg-panel sm:max-w-md [&>button:last-child]:hidden">
                        <DialogTitle className="text-sm leading-relaxed font-normal text-ink">
                            {shown?.question}
                        </DialogTitle>
                        <DialogDescription className="sr-only">
                            {shown?.label}
                        </DialogDescription>
                        <DialogFooter>
                            <Button onClick={() => setConfirming(null)}>
                                {t('common.cancel')}
                            </Button>
                            <Button
                                variant={shown?.danger ? 'danger' : 'primary'}
                                autoFocus
                                onClick={() => {
                                    confirming?.run();
                                    setConfirming(null);
                                }}
                            >
                                {shown?.label}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </PageBody>
        </>
    );
}
