import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ConfirmButton } from '@/components/lj/confirm-button';
import { TopBar } from '@/components/lj/top-bar';
import {
    Badge,
    Button,
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
import { PageBody } from '@/layouts/lumberjack-layout';
import { formatRelative } from '@/lib/format';
import { useT } from '@/lib/i18n';
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

export default function AdminUsers({ filters, users, teams }: Props) {
    const t = useT();
    const { auth } = usePage<{ auth: { user: { id: number } } }>().props;
    const [search, setSearch] = useState(filters.search);
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
                        className="max-w-sm"
                        placeholder={t('admin.users.search')}
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                </form>

                <Card>
                    {users.data.length === 0 ? (
                        <EmptyState title={t('admin.users.empty')} />
                    ) : (
                        <div className="overflow-x-auto">
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
                                        <th className={thClass}>
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
                                                    </span>
                                                </td>
                                                <td
                                                    className={cn(
                                                        tdClass,
                                                        'whitespace-nowrap text-ink-2',
                                                    )}
                                                >
                                                    {user.created_at
                                                        ? formatRelative(
                                                              user.created_at,
                                                          )
                                                        : '-'}
                                                </td>
                                                <td
                                                    className={cn(
                                                        tdClass,
                                                        'text-right',
                                                    )}
                                                >
                                                    <span className="inline-flex flex-wrap justify-end gap-2">
                                                        <Button
                                                            onClick={() =>
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
                                                        </Button>
                                                        {!you &&
                                                            !user.password_change_required && (
                                                                <ConfirmButton
                                                                    variant="default"
                                                                    question={t(
                                                                        'admin.users.require_password_change_question',
                                                                        {
                                                                            name: user.name,
                                                                        },
                                                                    )}
                                                                    confirmLabel={t(
                                                                        'admin.users.require_password_change',
                                                                    )}
                                                                    onConfirm={() =>
                                                                        router.put(
                                                                            passwordChange(
                                                                                user.id,
                                                                            )
                                                                                .url,
                                                                            {},
                                                                            keep,
                                                                        )
                                                                    }
                                                                >
                                                                    {t(
                                                                        'admin.users.require_password_change',
                                                                    )}
                                                                </ConfirmButton>
                                                            )}
                                                        {!you &&
                                                            (user.is_admin ? (
                                                                <ConfirmButton
                                                                    question={t(
                                                                        'admin.users.revoke_admin_question',
                                                                        {
                                                                            name: user.name,
                                                                        },
                                                                    )}
                                                                    confirmLabel={t(
                                                                        'admin.users.revoke_admin',
                                                                    )}
                                                                    onConfirm={() =>
                                                                        router.put(
                                                                            setAdmin(
                                                                                user.id,
                                                                            )
                                                                                .url,
                                                                            {
                                                                                is_admin: false,
                                                                            },
                                                                            keep,
                                                                        )
                                                                    }
                                                                >
                                                                    {t(
                                                                        'admin.users.revoke_admin',
                                                                    )}
                                                                </ConfirmButton>
                                                            ) : (
                                                                <ConfirmButton
                                                                    variant="default"
                                                                    question={t(
                                                                        'admin.users.make_admin_question',
                                                                        {
                                                                            name: user.name,
                                                                        },
                                                                    )}
                                                                    confirmLabel={t(
                                                                        'admin.users.make_admin',
                                                                    )}
                                                                    onConfirm={() =>
                                                                        router.put(
                                                                            setAdmin(
                                                                                user.id,
                                                                            )
                                                                                .url,
                                                                            {
                                                                                is_admin: true,
                                                                            },
                                                                            keep,
                                                                        )
                                                                    }
                                                                >
                                                                    {t(
                                                                        'admin.users.make_admin',
                                                                    )}
                                                                </ConfirmButton>
                                                            ))}
                                                        {!you &&
                                                            (user.suspended ? (
                                                                <Button
                                                                    onClick={() =>
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
                                                                </Button>
                                                            ) : (
                                                                <ConfirmButton
                                                                    question={t(
                                                                        'admin.users.suspend_question',
                                                                        {
                                                                            name: user.name,
                                                                        },
                                                                    )}
                                                                    confirmLabel={t(
                                                                        'admin.users.suspend',
                                                                    )}
                                                                    onConfirm={() =>
                                                                        router.put(
                                                                            suspend(
                                                                                user.id,
                                                                            )
                                                                                .url,
                                                                            {
                                                                                suspended: true,
                                                                            },
                                                                            keep,
                                                                        )
                                                                    }
                                                                >
                                                                    {t(
                                                                        'admin.users.suspend',
                                                                    )}
                                                                </ConfirmButton>
                                                            ))}
                                                    </span>
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
            </PageBody>
        </>
    );
}
