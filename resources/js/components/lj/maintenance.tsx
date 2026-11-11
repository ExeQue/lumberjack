import { Form, router } from '@inertiajs/react';
import { useState } from 'react';
import { ConfirmButton } from '@/components/lj/confirm-button';
import {
    Badge,
    Button,
    Card,
    CardHeader,
    EmptyState,
    Field,
    Input,
    Mono,
    Select,
    tableClass,
    tdClass,
    thClass,
} from '@/components/lj/ui';
import type { Tone } from '@/components/lj/ui';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatDateTime } from '@/lib/format';
import { useT } from '@/lib/i18n';
import {
    end as maintenanceEnd,
    store as maintenanceStore,
    update as maintenanceUpdate,
} from '@/routes/maintenance';
import type { MaintenanceWindow, ProjectHeader } from '@/types/lumberjack';

const stateTone: Record<MaintenanceWindow['state'], Tone> = {
    planned: 'neutral',
    open: 'info',
    overrun: 'warn',
    ended: 'neutral',
};

/** An ISO time as a datetime-local input's value, in the browser's time zone. */
function localInput(time: Date): string {
    return new Date(time.getTime() - time.getTimezoneOffset() * 60_000)
        .toISOString()
        .slice(0, 16);
}

/**
 * The project's maintenance windows on the Health page: planned and reported ones, with
 * plan, edit and end for those who may administer the project.
 */
export function MaintenanceCard({ project }: { project: ProjectHeader }) {
    const t = useT();
    const [editing, setEditing] = useState<MaintenanceWindow | 'new' | null>(
        null,
    );
    const admin = project.can_administer;

    return (
        <Card aria-labelledby="health-maintenance-title">
            <CardHeader
                id="health-maintenance-title"
                title={t('health.maintenance.title')}
                description={t('health.maintenance.hint')}
                aside={
                    admin && (
                        <Button onClick={() => setEditing('new')}>
                            {t('health.maintenance.plan')}
                        </Button>
                    )
                }
            />
            {project.maintenance.length === 0 ? (
                <EmptyState title={t('health.maintenance.empty')} />
            ) : (
                <div className="overflow-x-auto">
                    <table className={tableClass}>
                        <thead>
                            <tr>
                                {(
                                    [
                                        'environment',
                                        'message',
                                        'starts',
                                        'expected_end',
                                        'ended',
                                        'status',
                                    ] as const
                                ).map((column) => (
                                    <th key={column} className={thClass}>
                                        {t(
                                            `health.maintenance.columns.${column}`,
                                        )}
                                    </th>
                                ))}
                                {admin && (
                                    <th className={thClass}>
                                        <span className="sr-only">
                                            {t(
                                                'health.maintenance.columns.actions',
                                            )}
                                        </span>
                                    </th>
                                )}
                            </tr>
                        </thead>
                        <tbody>
                            {project.maintenance.map((w) => (
                                <tr key={w.id}>
                                    <td className={tdClass}>
                                        {w.environment ? (
                                            <Mono>{w.environment}</Mono>
                                        ) : (
                                            t(
                                                'health.maintenance.every_environment',
                                            )
                                        )}
                                        <span className="block text-xs text-ink-3">
                                            {t(
                                                `health.maintenance.sources.${w.source}`,
                                            )}
                                        </span>
                                    </td>
                                    <td
                                        className={`${tdClass} text-xs text-ink-2`}
                                    >
                                        {w.message ?? '-'}
                                    </td>
                                    {[
                                        w.starts_at,
                                        w.expected_end_at,
                                        w.ended_at,
                                    ].map((time, i) => (
                                        <td
                                            key={i}
                                            className={`${tdClass} text-xs whitespace-nowrap text-ink-2`}
                                        >
                                            {formatDateTime(time)}
                                        </td>
                                    ))}
                                    <td className={tdClass}>
                                        <Badge tone={stateTone[w.state]}>
                                            {t(
                                                `health.maintenance.states.${w.state}`,
                                            )}
                                        </Badge>
                                    </td>
                                    {admin && (
                                        <td className={tdClass}>
                                            {w.state !== 'ended' && (
                                                <div className="flex justify-end gap-2">
                                                    <Button
                                                        variant="ghost"
                                                        onClick={() =>
                                                            setEditing(w)
                                                        }
                                                    >
                                                        {t(
                                                            'health.maintenance.edit',
                                                        )}
                                                    </Button>
                                                    {w.state !== 'planned' && (
                                                        <ConfirmButton
                                                            variant="default"
                                                            question={t(
                                                                'health.maintenance.end_question',
                                                            )}
                                                            confirmLabel={t(
                                                                'health.maintenance.end',
                                                            )}
                                                            onConfirm={() =>
                                                                router.post(
                                                                    maintenanceEnd(
                                                                        [
                                                                            project.uuid,
                                                                            w.id,
                                                                        ],
                                                                    ).url,
                                                                    {},
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            {t(
                                                                'health.maintenance.end',
                                                            )}
                                                        </ConfirmButton>
                                                    )}
                                                </div>
                                            )}
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            {editing !== null && (
                <MaintenanceDialog
                    project={project}
                    window={editing === 'new' ? undefined : editing}
                    onClose={() => setEditing(null)}
                />
            )}
        </Card>
    );
}

function MaintenanceDialog({
    project,
    window: w,
    onClose,
}: {
    project: ProjectHeader;
    window?: MaintenanceWindow;
    onClose: () => void;
}) {
    const t = useT();
    const start = w ? new Date(w.starts_at) : new Date();
    const end = w
        ? new Date(w.expected_end_at)
        : new Date(start.getTime() + 30 * 60_000);

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {t(
                            w
                                ? 'health.maintenance.form.edit_title'
                                : 'health.maintenance.form.plan_title',
                        )}
                    </DialogTitle>
                    <DialogDescription>
                        {t('health.maintenance.form.description')}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...(w
                        ? maintenanceUpdate.form([project.uuid, w.id])
                        : maintenanceStore.form(project.uuid))}
                    options={{ preserveScroll: true }}
                    // The inputs hold local times; the server gets them with their offset.
                    transform={(data) => ({
                        ...data,
                        starts_at: new Date(
                            String(data.starts_at),
                        ).toISOString(),
                        ends_at: new Date(String(data.ends_at)).toISOString(),
                    })}
                    onSuccess={onClose}
                    className="flex flex-col gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field
                                    label={t(
                                        'health.maintenance.form.environment',
                                    )}
                                    htmlFor="maintenance-environment"
                                    error={errors.environment}
                                    className="sm:col-span-2"
                                >
                                    <Select
                                        id="maintenance-environment"
                                        name="environment"
                                        defaultValue={w?.environment ?? ''}
                                    >
                                        <option value="">
                                            {t(
                                                'health.maintenance.every_environment',
                                            )}
                                        </option>
                                        {project.environments.map((env) => (
                                            <option key={env} value={env}>
                                                {env}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                                <Field
                                    label={t(
                                        'health.maintenance.form.starts_at',
                                    )}
                                    htmlFor="maintenance-starts"
                                    error={errors.starts_at}
                                >
                                    <Input
                                        id="maintenance-starts"
                                        name="starts_at"
                                        type="datetime-local"
                                        required
                                        defaultValue={localInput(start)}
                                    />
                                </Field>
                                <Field
                                    label={t('health.maintenance.form.ends_at')}
                                    htmlFor="maintenance-ends"
                                    error={errors.ends_at}
                                >
                                    <Input
                                        id="maintenance-ends"
                                        name="ends_at"
                                        type="datetime-local"
                                        required
                                        defaultValue={localInput(end)}
                                    />
                                </Field>
                                <Field
                                    label={t('health.maintenance.form.message')}
                                    htmlFor="maintenance-message"
                                    hint={t(
                                        'health.maintenance.form.message_hint',
                                    )}
                                    error={errors.message}
                                    className="sm:col-span-2"
                                >
                                    <Input
                                        id="maintenance-message"
                                        name="message"
                                        maxLength={500}
                                        defaultValue={w?.message ?? ''}
                                    />
                                </Field>
                            </div>
                            <DialogFooter>
                                <Button onClick={onClose}>
                                    {t('common.cancel')}
                                </Button>
                                <Button
                                    type="submit"
                                    variant="primary"
                                    disabled={processing}
                                >
                                    {t('health.maintenance.form.save')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
