import { Relative } from '@/components/lj/relative';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { ConfirmButton } from '@/components/lj/confirm-button';
import { TopBar } from '@/components/lj/top-bar';
import {
    Button,
    Card,
    CardHeader,
    EmptyState,
    Field,
    Input,
    numClass,
    PageTitle,
    tableClass,
    tdClass,
    thClass,
} from '@/components/lj/ui';
import { PageBody } from '@/layouts/lumberjack-layout';
import { useT } from '@/lib/i18n';
import { useAdminLive } from '@/lib/use-live';
import { cn } from '@/lib/utils';
import { destroy, open, store } from '@/routes/admin/teams';

type Team = {
    uuid: string;
    name: string;
    owner: string | null;
    members: number;
    projects: number;
    errors_24h: number;
    created_at: string | null;
};

type Props = {
    teams: Team[];
    users: { name: string; email: string }[];
};

export default function AdminTeams({ teams, users }: Props) {
    const t = useT();
    useAdminLive(['teams.changed', 'users.changed'], ['teams', 'users']);
    const { auth } = usePage<{ auth: { user: { email: string } } }>().props;
    const form = useForm({ name: '', owner: auth.user.email });

    return (
        <>
            <Head title={t('admin.teams.title')} />
            <TopBar
                crumbs={[
                    { label: t('admin.nav.section') },
                    { label: t('admin.teams.title') },
                ]}
            />
            <PageBody>
                <PageTitle
                    title={t('admin.teams.title')}
                    description={t('admin.teams.description')}
                />

                <Card>
                    {teams.length === 0 ? (
                        <EmptyState title={t('admin.teams.empty')} />
                    ) : (
                        <div className="relative overflow-x-auto">
                            <table className={tableClass}>
                                <thead>
                                    <tr>
                                        <th className={thClass}>
                                            {t('admin.teams.name')}
                                        </th>
                                        <th className={thClass}>
                                            {t('admin.teams.owner')}
                                        </th>
                                        <th
                                            className={cn(
                                                thClass,
                                                'text-right',
                                            )}
                                        >
                                            {t('admin.teams.members')}
                                        </th>
                                        <th
                                            className={cn(
                                                thClass,
                                                'text-right',
                                            )}
                                        >
                                            {t('admin.teams.projects')}
                                        </th>
                                        <th
                                            className={cn(
                                                thClass,
                                                'text-right',
                                            )}
                                        >
                                            {t('admin.teams.errors_24h')}
                                        </th>
                                        <th className={thClass}>
                                            {t('admin.teams.created')}
                                        </th>
                                        <th className={thClass}>
                                            <span className="sr-only">
                                                {t('team.actions')}
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {teams.map((team) => (
                                        <tr key={team.uuid}>
                                            <td className={tdClass}>
                                                {team.name}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    'text-ink-2',
                                                )}
                                            >
                                                {team.owner ?? '-'}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    numClass,
                                                )}
                                            >
                                                {team.members}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    numClass,
                                                )}
                                            >
                                                {team.projects}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    numClass,
                                                )}
                                            >
                                                {team.errors_24h}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    'text-ink-2',
                                                )}
                                            >
                                                <Relative
                                                    iso={team.created_at}
                                                />
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
                                                                open(team.uuid)
                                                                    .url,
                                                            )
                                                        }
                                                    >
                                                        {t('admin.teams.open')}
                                                    </Button>
                                                    <ConfirmButton
                                                        question={t(
                                                            'admin.teams.delete_question',
                                                            { name: team.name },
                                                        )}
                                                        confirmLabel={t(
                                                            'admin.teams.delete',
                                                        )}
                                                        onConfirm={() =>
                                                            router.delete(
                                                                destroy(
                                                                    team.uuid,
                                                                ).url,
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        {t(
                                                            'admin.teams.delete',
                                                        )}
                                                    </ConfirmButton>
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>

                <Card aria-labelledby="h-new-team">
                    <CardHeader id="h-new-team" title={t('admin.teams.new')} />
                    <form
                        className="flex flex-wrap items-end gap-3 p-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(store().url, {
                                preserveScroll: true,
                                onSuccess: () => form.reset(),
                            });
                        }}
                    >
                        <Field
                            label={t('admin.teams.name')}
                            htmlFor="team-name"
                            className="min-w-48 flex-1"
                            error={form.errors.name}
                        >
                            <Input
                                id="team-name"
                                required
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label={t('admin.teams.owner_email')}
                            htmlFor="team-owner"
                            className="min-w-64 flex-[2]"
                            error={form.errors.owner}
                        >
                            <Input
                                id="team-owner"
                                type="email"
                                list="team-owner-users"
                                autoComplete="off"
                                required
                                value={form.data.owner}
                                onChange={(e) =>
                                    form.setData('owner', e.target.value)
                                }
                            />
                            <datalist id="team-owner-users">
                                {users.map((user) => (
                                    <option key={user.email} value={user.email}>
                                        {user.name}
                                    </option>
                                ))}
                            </datalist>
                        </Field>
                        <Button
                            type="submit"
                            variant="primary"
                            disabled={form.processing}
                        >
                            {t('admin.teams.create')}
                        </Button>
                        <p className="w-full text-xs text-ink-3">
                            {t('admin.teams.owner_hint')}
                        </p>
                    </form>
                </Card>
            </PageBody>
        </>
    );
}
