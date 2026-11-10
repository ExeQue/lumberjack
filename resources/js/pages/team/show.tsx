import { Relative, WithRelative } from '@/components/lj/relative';
import { Form, Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ConfirmButton } from '@/components/lj/confirm-button';
import {
    McpTokenEditor,
    type McpCatalogArea,
    type McpEditableToken,
} from '@/components/lj/mcp-token-editor';
import { TopBar } from '@/components/lj/top-bar';
import {
    Badge,
    Button,
    Card,
    CardHeader,
    EmptyState,
    Field,
    Input,
    Mono,
    PageTitle,
    Select,
    tableClass,
    tdClass,
    thClass,
} from '@/components/lj/ui';
import { PageBody } from '@/layouts/lumberjack-layout';
import { formatDateTime } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { useTeamLive } from '@/lib/use-live';
import { cn } from '@/lib/utils';
import {
    destroy as invitationDestroy,
    resend as invitationResend,
    store as invitationStore,
} from '@/routes/invitations';
import { destroy as tokenDestroy } from '@/routes/mcp-tokens';
import {
    destroy as memberDestroy,
    transfer as memberTransfer,
    update as memberUpdate,
} from '@/routes/members';
import { store as teamStore } from '@/routes/teams';
import type { TeamShared } from '@/types/lumberjack';

type Role = 'owner' | 'admin' | 'member';
type Member = {
    id: number;
    name: string;
    email: string;
    role: Role;
    is_you: boolean;
};
type Invitation = {
    id: number;
    email: string;
    role: Role;
    expires_at: string;
    expired: boolean;
};
type Token = McpEditableToken & {
    tool_count: number;
    created_by: string | null;
    usable: boolean;
    last_used_at: string | null;
    expires_at: string | null;
};

type Props = {
    members: Member[];
    invitations: Invitation[];
    tokens: Token[];
    mcp_catalog: McpCatalogArea[];
    mcp_url: string;
    api_url: string;
    can_create_team: boolean;
};

export default function TeamShow({
    members,
    invitations,
    tokens,
    mcp_catalog,
    mcp_url,
    api_url,
    can_create_team,
}: Props) {
    const t = useT();
    const { team } = usePage<{ team: TeamShared }>().props;
    const admin = team.can_administer;
    const isOwner = team.role === 'owner';
    // null: closed; 'new': making a token; else the token being edited.
    const [editing, setEditing] = useState<Token | 'new' | null>(null);
    useTeamLive(
        ['members.changed', 'invitations.changed', 'tokens.changed'],
        ['members', 'invitations', 'tokens'],
    );

    return (
        <>
            <Head title={t('team.title')} />
            <TopBar
                crumbs={[{ label: team.name }, { label: t('common.nav.team') }]}
            />
            <PageBody>
                <PageTitle title={t('team.title')} />

                <Card aria-labelledby="h-members">
                    <CardHeader
                        id="h-members"
                        title={t('team.members.title')}
                        description={t('team.members.description')}
                    />
                    <div className="flex flex-col gap-5 p-4">
                        <div className="relative overflow-x-auto">
                            <table className={tableClass}>
                                <thead>
                                    <tr>
                                        <th className={thClass}>
                                            {t('team.members.name')}
                                        </th>
                                        <th className={thClass}>
                                            {t('team.members.email')}
                                        </th>
                                        <th className={thClass}>
                                            {t('team.members.role')}
                                        </th>
                                        <th className={thClass}>
                                            <span className="sr-only">
                                                {t('team.actions')}
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {members.map((member) => {
                                        const editable =
                                            admin &&
                                            member.role !== 'owner' &&
                                            !member.is_you;

                                        return (
                                            <tr key={member.id}>
                                                <td className={tdClass}>
                                                    {member.name}{' '}
                                                    {member.is_you && (
                                                        <span className="text-xs text-ink-3">
                                                            {t(
                                                                'team.members.you',
                                                            )}
                                                        </span>
                                                    )}
                                                </td>
                                                <td
                                                    className={cn(
                                                        tdClass,
                                                        'text-ink-2',
                                                    )}
                                                >
                                                    {member.email}
                                                </td>
                                                <td className={tdClass}>
                                                    {editable ? (
                                                        <>
                                                            <label
                                                                className="sr-only"
                                                                htmlFor={`role-${member.id}`}
                                                            >
                                                                {t(
                                                                    'team.members.role_for',
                                                                    {
                                                                        name: member.name,
                                                                    },
                                                                )}
                                                            </label>
                                                            <Select
                                                                id={`role-${member.id}`}
                                                                value={
                                                                    member.role
                                                                }
                                                                onChange={(e) =>
                                                                    router.put(
                                                                        memberUpdate(
                                                                            member.id,
                                                                        ).url,
                                                                        {
                                                                            role: e
                                                                                .target
                                                                                .value,
                                                                        },
                                                                        {
                                                                            preserveScroll: true,
                                                                        },
                                                                    )
                                                                }
                                                            >
                                                                <option value="admin">
                                                                    {t(
                                                                        'common.roles.admin',
                                                                    )}
                                                                </option>
                                                                <option value="member">
                                                                    {t(
                                                                        'common.roles.member',
                                                                    )}
                                                                </option>
                                                            </Select>
                                                        </>
                                                    ) : (
                                                        <Badge
                                                            tone={
                                                                member.role ===
                                                                'owner'
                                                                    ? 'info'
                                                                    : 'neutral'
                                                            }
                                                        >
                                                            {t(
                                                                `common.roles.${member.role}`,
                                                            )}
                                                        </Badge>
                                                    )}
                                                </td>
                                                <td
                                                    className={cn(
                                                        tdClass,
                                                        'text-right',
                                                    )}
                                                >
                                                    <span className="inline-flex flex-wrap justify-end gap-2">
                                                        {isOwner &&
                                                            !member.is_you && (
                                                                <ConfirmButton
                                                                    variant="default"
                                                                    question={t(
                                                                        'team.members.transfer_question',
                                                                        {
                                                                            name: member.name,
                                                                        },
                                                                    )}
                                                                    confirmLabel={t(
                                                                        'team.members.transfer',
                                                                    )}
                                                                    onConfirm={() =>
                                                                        router.post(
                                                                            memberTransfer(
                                                                                member.id,
                                                                            )
                                                                                .url,
                                                                            {},
                                                                            {
                                                                                preserveScroll: true,
                                                                            },
                                                                        )
                                                                    }
                                                                >
                                                                    {t(
                                                                        'team.members.transfer',
                                                                    )}
                                                                    <span className="sr-only">
                                                                        {' '}
                                                                        {
                                                                            member.name
                                                                        }
                                                                    </span>
                                                                </ConfirmButton>
                                                            )}
                                                        {editable && (
                                                            <ConfirmButton
                                                                question={t(
                                                                    'team.members.remove_question',
                                                                    {
                                                                        name: member.name,
                                                                    },
                                                                )}
                                                                confirmLabel={t(
                                                                    'team.members.remove',
                                                                )}
                                                                onConfirm={() =>
                                                                    router.delete(
                                                                        memberDestroy(
                                                                            member.id,
                                                                        ).url,
                                                                        {
                                                                            preserveScroll: true,
                                                                        },
                                                                    )
                                                                }
                                                            >
                                                                {t(
                                                                    'team.members.remove',
                                                                )}
                                                                <span className="sr-only">
                                                                    {' '}
                                                                    {
                                                                        member.name
                                                                    }
                                                                </span>
                                                            </ConfirmButton>
                                                        )}
                                                    </span>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>

                        {invitations.length > 0 && (
                            <div className="flex flex-col gap-2">
                                <h3 className="text-[13px] font-medium">
                                    {t('team.invitations.title')}
                                </h3>
                                <ul className="flex flex-col gap-1.5">
                                    {invitations.map((invitation) => (
                                        <li
                                            key={invitation.id}
                                            className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-line px-3 py-2"
                                        >
                                            <span className="flex flex-wrap items-center gap-2">
                                                <span>{invitation.email}</span>
                                                <span className="text-xs text-ink-3">
                                                    {t(
                                                        `common.roles.${invitation.role}`,
                                                    )}
                                                </span>
                                                {invitation.expired ? (
                                                    <Badge tone="warn">
                                                        {t(
                                                            'team.invitations.expired',
                                                        )}
                                                    </Badge>
                                                ) : (
                                                    <span className="text-xs text-ink-3">
                                                        <WithRelative
                                                            iso={
                                                                invitation.expires_at
                                                            }
                                                        >
                                                            {(time) =>
                                                                t(
                                                                    'team.invitations.expires',
                                                                    { time },
                                                                )
                                                            }
                                                        </WithRelative>
                                                    </span>
                                                )}
                                            </span>
                                            {admin && (
                                                <span className="flex gap-2">
                                                    <Button
                                                        onClick={() =>
                                                            router.post(
                                                                invitationResend(
                                                                    invitation.id,
                                                                ).url,
                                                                {},
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        {t(
                                                            'team.invitations.resend',
                                                        )}
                                                        <span className="sr-only">
                                                            {' '}
                                                            {invitation.email}
                                                        </span>
                                                    </Button>
                                                    <ConfirmButton
                                                        question={t(
                                                            'team.invitations.revoke_question',
                                                            {
                                                                email: invitation.email,
                                                            },
                                                        )}
                                                        confirmLabel={t(
                                                            'team.invitations.revoke',
                                                        )}
                                                        onConfirm={() =>
                                                            router.delete(
                                                                invitationDestroy(
                                                                    invitation.id,
                                                                ).url,
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        {t(
                                                            'team.invitations.revoke',
                                                        )}
                                                        <span className="sr-only">
                                                            {' '}
                                                            {invitation.email}
                                                        </span>
                                                    </ConfirmButton>
                                                </span>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        {admin && (
                            <Form
                                {...invitationStore.form()}
                                options={{ preserveScroll: true }}
                                resetOnSuccess={['email']}
                                className="flex flex-wrap items-start gap-3"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <Field
                                            label={t('team.invitations.email')}
                                            htmlFor="invite-email"
                                            error={errors.email}
                                            className="min-w-0 flex-[2_1_240px]"
                                        >
                                            <Input
                                                id="invite-email"
                                                name="email"
                                                type="email"
                                                required
                                                autoComplete="off"
                                                placeholder={t(
                                                    'team.invitations.email_placeholder',
                                                )}
                                            />
                                        </Field>
                                        <Field
                                            label={t('team.members.role')}
                                            htmlFor="invite-role"
                                            error={errors.role}
                                            className="flex-[0_1_160px]"
                                        >
                                            <Select
                                                id="invite-role"
                                                name="role"
                                                defaultValue="member"
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
                                            disabled={processing}
                                            className="mt-6"
                                        >
                                            {t('team.invitations.send')}
                                        </Button>
                                    </>
                                )}
                            </Form>
                        )}
                    </div>
                </Card>

                <Card aria-labelledby="h-tokens">
                    <CardHeader
                        id="h-tokens"
                        title={t('team.tokens.title')}
                        description={t('team.tokens.description')}
                    />
                    <div className="flex flex-col gap-5 p-4">
                        <p className="text-xs text-ink-3">
                            {t('team.tokens.endpoint')}{' '}
                            <Mono className="break-all text-ink-2">
                                {mcp_url}
                            </Mono>
                        </p>
                        <p className="text-xs text-ink-3">
                            {t('team.tokens.api_endpoint')}{' '}
                            <Mono className="break-all text-ink-2">
                                {api_url}
                            </Mono>
                        </p>
                        {tokens.length === 0 ? (
                            <EmptyState title={t('team.tokens.empty')} />
                        ) : (
                            <div className="relative overflow-x-auto">
                                <table className={tableClass}>
                                    <thead>
                                        <tr>
                                            <th className={thClass}>
                                                {t('team.tokens.name')}
                                            </th>
                                            <th className={thClass}>
                                                {t('team.tokens.projects')}
                                            </th>
                                            <th className={thClass}>
                                                {t('team.tokens.permissions')}
                                            </th>
                                            <th className={thClass}>
                                                {t('team.tokens.created_by')}
                                            </th>
                                            <th className={thClass}>
                                                {t('team.tokens.last_used')}
                                            </th>
                                            <th className={thClass}>
                                                {t('team.tokens.expires')}
                                            </th>
                                            {admin && (
                                                <th className={thClass}>
                                                    <span className="sr-only">
                                                        {t('team.actions')}
                                                    </span>
                                                </th>
                                            )}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {tokens.map((token) => (
                                            <tr key={token.id}>
                                                <td
                                                    className={cn(
                                                        tdClass,
                                                        'font-mono text-xs',
                                                    )}
                                                >
                                                    {token.name}
                                                </td>
                                                <td
                                                    className={cn(
                                                        tdClass,
                                                        'text-ink-2',
                                                    )}
                                                >
                                                    {token.all_projects
                                                        ? t(
                                                              'team.tokens.all_projects',
                                                              {
                                                                  count: team
                                                                      .projects
                                                                      .length,
                                                              },
                                                          )
                                                        : token.projects
                                                              .map(
                                                                  (p) => p.name,
                                                              )
                                                              .join(', ')}
                                                </td>
                                                <td className={tdClass}>
                                                    <Badge>
                                                        {t(
                                                            'team.tokens.tool_count',
                                                            {
                                                                count: token.tool_count,
                                                            },
                                                            token.tool_count,
                                                        )}
                                                    </Badge>
                                                </td>
                                                <td
                                                    className={cn(
                                                        tdClass,
                                                        'text-ink-2',
                                                    )}
                                                >
                                                    {token.created_by ?? '-'}
                                                    {!token.usable && (
                                                        <Badge
                                                            tone="err"
                                                            className="ml-2"
                                                            title={t(
                                                                'team.tokens.inactive_hint',
                                                            )}
                                                        >
                                                            {t(
                                                                'team.tokens.inactive',
                                                            )}
                                                        </Badge>
                                                    )}
                                                </td>
                                                <td
                                                    className={cn(
                                                        tdClass,
                                                        'text-ink-2',
                                                    )}
                                                >
                                                    {token.last_used_at ? (
                                                        <Relative
                                                            iso={
                                                                token.last_used_at
                                                            }
                                                        />
                                                    ) : (
                                                        t(
                                                            'team.tokens.never_used',
                                                        )
                                                    )}
                                                </td>
                                                <td
                                                    className={cn(
                                                        tdClass,
                                                        'text-ink-2',
                                                    )}
                                                >
                                                    {token.expires_at
                                                        ? formatDateTime(
                                                              token.expires_at,
                                                          )
                                                        : t(
                                                              'team.tokens.never',
                                                          )}
                                                </td>
                                                {admin && (
                                                    <td
                                                        className={cn(
                                                            tdClass,
                                                            'text-right',
                                                        )}
                                                    >
                                                        <Button
                                                            type="button"
                                                            className="mr-2"
                                                            onClick={() =>
                                                                setEditing(
                                                                    token,
                                                                )
                                                            }
                                                        >
                                                            {t(
                                                                'team.tokens.edit',
                                                            )}
                                                            <span className="sr-only">
                                                                {' '}
                                                                {token.name}
                                                            </span>
                                                        </Button>
                                                        <ConfirmButton
                                                            question={t(
                                                                'team.tokens.revoke_question',
                                                                {
                                                                    name: token.name,
                                                                },
                                                            )}
                                                            confirmLabel={t(
                                                                'team.tokens.revoke',
                                                            )}
                                                            onConfirm={() =>
                                                                router.delete(
                                                                    tokenDestroy(
                                                                        token.id,
                                                                    ).url,
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            {t(
                                                                'team.tokens.revoke',
                                                            )}
                                                            <span className="sr-only">
                                                                {' '}
                                                                {token.name}
                                                            </span>
                                                        </ConfirmButton>
                                                    </td>
                                                )}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        {admin && (
                            <div className="border-t border-line pt-4">
                                <Button
                                    type="button"
                                    variant="primary"
                                    onClick={() => setEditing('new')}
                                    disabled={team.projects.length === 0}
                                >
                                    {t('team.tokens.new')}
                                </Button>
                                {team.projects.length === 0 && (
                                    <p className="mt-2 text-xs text-ink-3">
                                        {t('team.tokens.no_projects')}
                                    </p>
                                )}
                            </div>
                        )}
                        {editing !== null && (
                            <McpTokenEditor
                                key={editing === 'new' ? 'new' : editing.id}
                                open
                                onClose={() => setEditing(null)}
                                catalog={mcp_catalog}
                                projects={team.projects}
                                token={editing === 'new' ? null : editing}
                            />
                        )}
                    </div>
                </Card>

                {can_create_team && (
                    <Card aria-labelledby="h-new-team">
                        <CardHeader
                            id="h-new-team"
                            title={t('team.create.title')}
                            description={t('team.create.description')}
                        />
                        <Form
                            {...teamStore.form()}
                            className="flex flex-wrap items-start gap-3 p-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <Field
                                        label={t('team.create.name')}
                                        htmlFor="team-name"
                                        error={errors.name}
                                        className="min-w-0 flex-[1_1_240px]"
                                    >
                                        <Input
                                            id="team-name"
                                            name="name"
                                            required
                                            maxLength={100}
                                            autoComplete="off"
                                        />
                                    </Field>
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="mt-6"
                                    >
                                        {t('team.create.submit')}
                                    </Button>
                                </>
                            )}
                        </Form>
                    </Card>
                )}
            </PageBody>
        </>
    );
}
