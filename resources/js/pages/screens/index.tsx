import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ConfirmButton } from '@/components/lj/confirm-button';
import { Relative } from '@/components/lj/relative';
import { TopBar } from '@/components/lj/top-bar';
import {
    Button,
    Card,
    EmptyState,
    Field,
    Input,
    LinkButton,
    PageTitle,
    tableClass,
    tdClass,
    thClass,
} from '@/components/lj/ui';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { PageBody } from '@/layouts/lumberjack-layout';
import { useT } from '@/lib/i18n';
import {
    destroy,
    duplicate,
    edit,
    show,
    store,
    update,
} from '@/routes/screens';
import type { TeamShared } from '@/types/lumberjack';

type Row = {
    uuid: string;
    name: string;
    cols: number;
    rows: number;
    theme: string;
    widgets: number;
    updated_at: string | null;
};

/** The team's status screens: open one on a TV, design it, or make another. */
export default function Screens({ screens }: { screens: Row[] }) {
    const t = useT();
    const { team } = usePage<{ team: TeamShared }>().props;
    const [creating, setCreating] = useState(false);
    const [renaming, setRenaming] = useState<Row | null>(null);

    return (
        <>
            <Head title={t('screens.title')} />
            <TopBar
                crumbs={[{ label: team.name }, { label: t('screens.title') }]}
            />
            <PageBody>
                <PageTitle
                    title={t('screens.title')}
                    description={t('screens.description')}
                    actions={
                        <Button
                            variant="primary"
                            onClick={() => setCreating(true)}
                        >
                            {t('screens.new')}
                        </Button>
                    }
                />

                <Card>
                    {screens.length === 0 ? (
                        <EmptyState title={t('screens.empty.title')}>
                            {t('screens.empty.body')}
                        </EmptyState>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className={tableClass}>
                                <thead>
                                    <tr>
                                        <th className={thClass}>
                                            {t('screens.table.name')}
                                        </th>
                                        <th className={thClass}>
                                            {t('screens.table.grid')}
                                        </th>
                                        <th className={thClass}>
                                            {t('screens.table.widgets')}
                                        </th>
                                        <th className={thClass}>
                                            {t('screens.table.theme')}
                                        </th>
                                        <th className={thClass}>
                                            {t('screens.table.updated')}
                                        </th>
                                        <th className={thClass}>
                                            <span className="sr-only">
                                                {t('screens.table.actions')}
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {screens.map((screen) => (
                                        <tr key={screen.uuid}>
                                            <td className={tdClass}>
                                                <Link
                                                    href={edit(screen.uuid).url}
                                                    className="font-medium text-ink hover:text-amber"
                                                >
                                                    {screen.name}
                                                </Link>
                                            </td>
                                            <td
                                                className={`${tdClass} font-mono`}
                                            >
                                                {`${screen.cols} × ${screen.rows}`}
                                            </td>
                                            <td
                                                className={`${tdClass} font-mono`}
                                            >
                                                {screen.widgets}
                                            </td>
                                            <td className={tdClass}>
                                                {t(
                                                    `screens.theme.${screen.theme}`,
                                                )}
                                            </td>
                                            <td className={tdClass}>
                                                <Relative
                                                    iso={screen.updated_at}
                                                />
                                            </td>
                                            <td className={tdClass}>
                                                <div className="flex justify-end gap-1.5">
                                                    <a
                                                        href={
                                                            show(screen.uuid)
                                                                .url
                                                        }
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="inline-flex h-8 items-center rounded-md border border-line-strong bg-panel-2 px-3 font-medium hover:bg-line"
                                                    >
                                                        {t(
                                                            'screens.actions.open',
                                                        )}
                                                    </a>
                                                    <LinkButton
                                                        href={
                                                            edit(screen.uuid)
                                                                .url
                                                        }
                                                    >
                                                        {t(
                                                            'screens.actions.edit',
                                                        )}
                                                    </LinkButton>
                                                    <Button
                                                        onClick={() =>
                                                            setRenaming(screen)
                                                        }
                                                    >
                                                        {t(
                                                            'screens.actions.rename',
                                                        )}
                                                    </Button>
                                                    <Button
                                                        onClick={() =>
                                                            router.post(
                                                                duplicate(
                                                                    screen.uuid,
                                                                ).url,
                                                                {},
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        {t(
                                                            'screens.actions.duplicate',
                                                        )}
                                                    </Button>
                                                    <ConfirmButton
                                                        question={t(
                                                            'screens.actions.delete_question',
                                                            {
                                                                name: screen.name,
                                                            },
                                                        )}
                                                        confirmLabel={t(
                                                            'screens.actions.delete',
                                                        )}
                                                        onConfirm={() =>
                                                            router.delete(
                                                                destroy(
                                                                    screen.uuid,
                                                                ).url,
                                                            )
                                                        }
                                                    >
                                                        {t(
                                                            'screens.actions.delete',
                                                        )}
                                                    </ConfirmButton>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>
            </PageBody>

            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent>
                    <Form
                        {...store.form()}
                        onSuccess={() => setCreating(false)}
                        className="flex flex-col gap-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <DialogHeader>
                                    <DialogTitle>
                                        {t('screens.new')}
                                    </DialogTitle>
                                    <DialogDescription>
                                        {t('screens.create_hint')}
                                    </DialogDescription>
                                </DialogHeader>
                                <Field
                                    label={t('screens.table.name')}
                                    htmlFor="screen-name"
                                    error={errors.name}
                                >
                                    <Input
                                        id="screen-name"
                                        name="name"
                                        required
                                        maxLength={100}
                                        autoFocus
                                    />
                                </Field>
                                <DialogFooter>
                                    <Button onClick={() => setCreating(false)}>
                                        {t('common.cancel')}
                                    </Button>
                                    <Button
                                        type="submit"
                                        variant="primary"
                                        disabled={processing}
                                    >
                                        {t('screens.create')}
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={renaming !== null}
                onOpenChange={(open) => !open && setRenaming(null)}
            >
                <DialogContent>
                    {renaming && (
                        <Form
                            {...update.form(renaming.uuid)}
                            onSuccess={() => setRenaming(null)}
                            options={{ preserveScroll: true }}
                            className="flex flex-col gap-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <DialogHeader>
                                        <DialogTitle>
                                            {t('screens.actions.rename')}
                                        </DialogTitle>
                                        <DialogDescription>
                                            {renaming.name}
                                        </DialogDescription>
                                    </DialogHeader>
                                    <Field
                                        label={t('screens.table.name')}
                                        htmlFor="screen-rename"
                                        error={errors.name}
                                    >
                                        <Input
                                            id="screen-rename"
                                            name="name"
                                            defaultValue={renaming.name}
                                            required
                                            maxLength={100}
                                            autoFocus
                                        />
                                    </Field>
                                    <DialogFooter>
                                        <Button
                                            onClick={() => setRenaming(null)}
                                        >
                                            {t('common.cancel')}
                                        </Button>
                                        <Button
                                            type="submit"
                                            variant="primary"
                                            disabled={processing}
                                        >
                                            {t('screens.actions.save')}
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}
