import { Form, Head, router, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import type { Reference } from '@/components/lj/activity-filters';
import {
    BudgetMeter,
    BudgetState,
    useBudgetAmount,
} from '@/components/lj/ai-budgets';
import type { AiBudgetRow } from '@/components/lj/ai-budgets';
import {
    TableSkeleton,
    decodeReference,
    encodeReference,
    keyName,
    keyOption,
    referenceOption,
    useAiTargetSearch,
} from '@/components/lj/ai-usage';
import { ConfirmButton } from '@/components/lj/confirm-button';
import { TopBar } from '@/components/lj/top-bar';
import { TreeSelect } from '@/components/lj/tree-select';
import type { TreeOption } from '@/components/lj/tree-select';
import {
    Button,
    Card,
    CardHeader,
    EmptyState,
    Field,
    Input,
    PageTitle,
    Select,
    numClass,
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
import { cn } from '@/lib/utils';
import { useProjectLive } from '@/lib/use-project-live';
import { index as aiIndex } from '@/routes/ai';
import {
    destroy as budgetDestroy,
    store as budgetStore,
    update as budgetUpdate,
} from '@/routes/ai/budgets';
import { show as projectShow } from '@/routes/projects';
import type { ProjectHeader, TeamShared } from '@/types/lumberjack';

type Props = {
    project: ProjectHeader;
    /** Deferred: each with where it stands in its current day or month. */
    budgets?: AiBudgetRow[];
    can_update: boolean;
};

export default function AiBudgets({ project, budgets, can_update }: Props) {
    const t = useT();
    const { team } = usePage<{ team: TeamShared }>().props;
    const amount = useBudgetAmount();
    // null: closed; 'new': adding; else the budget being edited.
    const [editing, setEditing] = useState<AiBudgetRow | 'new' | null>(null);

    useProjectLive(project.uuid, ['ai.changed'], ['budgets']);

    return (
        <>
            <Head title={`${t('ai.budgets.title')} · ${project.name}`} />
            <TopBar
                crumbs={[
                    { label: team.name },
                    {
                        label: project.name,
                        href: projectShow(project.uuid).url,
                        mono: true,
                    },
                    { label: t('ai.title'), href: aiIndex(project.uuid).url },
                    { label: t('common.nav.ai_budgets') },
                ]}
            />
            <PageBody>
                <PageTitle
                    title={t('ai.budgets.title')}
                    description={t('ai.budgets.description', {
                        currency: team.currency,
                    })}
                    actions={
                        can_update && (
                            <Button
                                variant="primary"
                                onClick={() => setEditing('new')}
                            >
                                {t('ai.budgets.add')}
                            </Button>
                        )
                    }
                />

                <Card aria-labelledby="ai-budgets">
                    <CardHeader
                        id="ai-budgets"
                        title={t('common.nav.ai_budgets')}
                        description={t('ai.budgets.alerts_hint')}
                    />
                    {budgets === undefined ? (
                        <TableSkeleton />
                    ) : budgets.length === 0 ? (
                        <EmptyState title={t('ai.budgets.empty')} />
                    ) : (
                        <div className="relative overflow-x-auto">
                            <table className={tableClass}>
                                <thead>
                                    <tr>
                                        <th className={thClass}>
                                            {t('ai.budgets.columns.budget')}
                                        </th>
                                        <th className={thClass}>
                                            {t('ai.budgets.columns.used')}
                                        </th>
                                        <th
                                            className={cn(
                                                thClass,
                                                'text-right',
                                            )}
                                        >
                                            {t('ai.budgets.columns.amount')}
                                        </th>
                                        <th
                                            className={cn(
                                                thClass,
                                                'hidden text-right md:table-cell',
                                            )}
                                        >
                                            {t('ai.budgets.columns.projected')}
                                        </th>
                                        {can_update && (
                                            <th className={thClass}>
                                                <span className="sr-only">
                                                    {t('ai.columns.actions')}
                                                </span>
                                            </th>
                                        )}
                                    </tr>
                                </thead>
                                <tbody>
                                    {budgets.map((budget) => (
                                        <tr
                                            key={budget.uuid}
                                            className="hover:bg-panel-2/60"
                                        >
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    'text-xs',
                                                )}
                                            >
                                                <span className="mr-2 font-medium">
                                                    {budget.title}
                                                </span>
                                                <BudgetState budget={budget} />
                                                <div className="mt-0.5 text-[11px] text-ink-3">
                                                    {t(
                                                        `ai.enums.budget_scope.${budget.scope}`,
                                                    )}
                                                    {` · ${t(`ai.enums.budget_payer.${budget.payer}`)}`}
                                                    <TargetsSummary
                                                        budget={budget}
                                                    />
                                                </div>
                                            </td>
                                            <td className={tdClass}>
                                                <BudgetMeter budget={budget} />
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    numClass,
                                                    'text-xs',
                                                )}
                                            >
                                                {amount(
                                                    budget,
                                                    budget.usage.spent,
                                                )}{' '}
                                                /{' '}
                                                {amount(budget, budget.amount)}{' '}
                                                <span className="text-ink-3">
                                                    {t(
                                                        `ai.budgets.per.${budget.period}`,
                                                    )}
                                                </span>
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    numClass,
                                                    'hidden text-xs text-ink-2 md:table-cell',
                                                )}
                                            >
                                                {amount(
                                                    budget,
                                                    budget.usage.projected,
                                                )}
                                            </td>
                                            {can_update && (
                                                <td
                                                    className={cn(
                                                        tdClass,
                                                        'text-right whitespace-nowrap',
                                                    )}
                                                >
                                                    <div className="inline-flex gap-2">
                                                        <Button
                                                            variant="ghost"
                                                            onClick={() =>
                                                                setEditing(
                                                                    budget,
                                                                )
                                                            }
                                                            aria-label={t(
                                                                'ai.budgets.edit_label',
                                                                {
                                                                    name: budget.title,
                                                                },
                                                            )}
                                                        >
                                                            {t(
                                                                'ai.budgets.edit',
                                                            )}
                                                        </Button>
                                                        <ConfirmButton
                                                            question={t(
                                                                'ai.budgets.delete_question',
                                                            )}
                                                            confirmLabel={t(
                                                                'common.delete',
                                                            )}
                                                            onConfirm={() =>
                                                                router.delete(
                                                                    budgetDestroy(
                                                                        [
                                                                            project.uuid,
                                                                            budget.uuid,
                                                                        ],
                                                                    ).url,
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            <span className="sr-only">
                                                                {t(
                                                                    'ai.budgets.delete_label',
                                                                    {
                                                                        name: budget.title,
                                                                    },
                                                                )}
                                                            </span>
                                                            <span aria-hidden="true">
                                                                {t(
                                                                    'common.delete',
                                                                )}
                                                            </span>
                                                        </ConfirmButton>
                                                    </div>
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>
            </PageBody>

            {editing !== null && (
                <BudgetDialog
                    project={project.uuid}
                    budget={editing === 'new' ? undefined : editing}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

/** How many keys, key owners or actors a budget counts, with their names a click or tap away. */
function TargetsSummary({ budget }: { budget: AiBudgetRow }) {
    const t = useT();
    // Keyed by the pair, and with the whole type, so App\Models\User and App\Legacy\User differ.
    const entries =
        budget.scope === 'key'
            ? budget.keys.map((entry) => ({
                  key: entry.value,
                  name: budgetKeyLabel(entry, t),
              }))
            : budget.references.map((reference) => ({
                  key: JSON.stringify([reference.type, reference.id]),
                  name: `${reference.type} #${reference.id}`,
              }));

    if (budget.scope === 'project' || entries.length === 0) {
        return null;
    }

    return (
        <details className="mt-0.5">
            <summary className="cursor-pointer hover:text-ink-2">
                {t(`ai.enums.budget_${budget.scope}s`, {}, entries.length)}
            </summary>
            <ul className="mt-1 max-h-72 overflow-y-auto font-mono text-ink-2">
                {entries.map((entry) => (
                    <li key={entry.key} className="break-all">
                        {entry.name}
                    </li>
                ))}
            </ul>
        </details>
    );
}

/**
 * The key owners or actors a budget counts: picked from the ones seen, grouped by type, or
 * added by a type and an id not seen yet.
 */
function ReferencePicker({
    kind,
    project,
    kept,
    error,
}: {
    kind: 'owner' | 'actor';
    project: string;
    kept: Reference[];
    error?: string;
}) {
    const t = useT();
    const encode = encodeReference;
    const decode = decodeReference;
    const field = kind === 'owner' ? 'owners' : 'actors';
    const search = useAiTargetSearch(project, field);
    const [picked, setPicked] = useState<string[]>(kept.map(encode));
    const [type, setType] = useState('');
    const [id, setId] = useState('');
    const idInput = useRef<HTMLInputElement>(null);
    const option = (value: string): TreeOption =>
        search.known(value) ?? referenceOption(decode(value));
    const toggle = (value: string | null) =>
        setPicked(
            value === null
                ? []
                : picked.includes(value)
                  ? picked.filter((each) => each !== value)
                  : [...picked, value],
        );
    const add = () => {
        if (type.trim() === '' || id.trim() === '') {
            return;
        }

        const value = encode({ type: type.trim(), id: id.trim() });

        if (!picked.includes(value)) {
            setPicked([...picked, value]);
        }

        setId('');
    };
    // Typed in but not added yet: sent along, as if Add were pressed.
    const typed =
        type.trim() !== '' && id.trim() !== ''
            ? encode({ type: type.trim(), id: id.trim() })
            : null;
    const sent =
        typed !== null && !picked.includes(typed) ? [...picked, typed] : picked;

    return (
        <Field
            label={t(`ai.budgets.form.${field}`)}
            htmlFor={`ai-budget-${field}`}
            error={error}
        >
            <div className="flex flex-col gap-2">
                <TreeSelect
                    id={`ai-budget-${field}`}
                    label={t(`ai.budgets.form.${field}`)}
                    options={search.options}
                    onSearch={search.onSearch}
                    value={picked}
                    onChange={toggle}
                    multiple
                    optionFor={option}
                    pickedLabel={(count) =>
                        t(`ai.enums.budget_${kind}s`, { count }, count)
                    }
                    placeholder={t(`ai.budgets.form.pick_${field}`)}
                    searchPlaceholder={t('ai.keys.search')}
                    noMatches={search.noMatches}
                    busy={search.busy}
                    startOpen
                />
                {sent.map(decode).map((reference, index) => (
                    <span key={encode(reference)} hidden>
                        <input
                            type="hidden"
                            name={`${field}[${index}][type]`}
                            value={reference.type}
                        />
                        <input
                            type="hidden"
                            name={`${field}[${index}][id]`}
                            value={reference.id}
                        />
                    </span>
                ))}
                {/* One not seen yet, by its type and id. */}
                <div className="flex flex-wrap items-end gap-2">
                    <Field
                        label={t('ai.budgets.form.reference_type')}
                        htmlFor={`ai-budget-${field}-type`}
                        className="min-w-0 flex-1"
                    >
                        <Input
                            id={`ai-budget-${field}-type`}
                            list={`ai-budget-${field}-types`}
                            maxLength={128}
                            autoComplete="off"
                            value={type}
                            onChange={(e) => setType(e.target.value)}
                            onKeyDown={(e) => {
                                // On to the id, not submitting the budget.
                                if (
                                    e.key === 'Enter' &&
                                    !e.nativeEvent.isComposing &&
                                    e.keyCode !== 229
                                ) {
                                    e.preventDefault();
                                    idInput.current?.focus();
                                }
                            }}
                        />
                        <datalist id={`ai-budget-${field}-types`}>
                            {[
                                ...new Set(
                                    search.options.map(
                                        (each) => decode(each.value).type,
                                    ),
                                ),
                            ].map((each) => (
                                <option key={each} value={each} />
                            ))}
                        </datalist>
                    </Field>
                    <Field
                        label={t('ai.budgets.form.reference_id')}
                        htmlFor={`ai-budget-${field}-id`}
                        className="min-w-0 flex-1"
                    >
                        <Input
                            id={`ai-budget-${field}-id`}
                            ref={idInput}
                            maxLength={64}
                            autoComplete="off"
                            value={id}
                            onChange={(e) => setId(e.target.value)}
                            onKeyDown={(e) => {
                                if (
                                    e.key === 'Enter' &&
                                    !e.nativeEvent.isComposing &&
                                    e.keyCode !== 229
                                ) {
                                    e.preventDefault();
                                    add();
                                }
                            }}
                        />
                    </Field>
                    <Button
                        type="button"
                        onClick={add}
                        disabled={type.trim() === '' || id.trim() === ''}
                    >
                        {t('ai.budgets.form.add_reference')}
                    </Button>
                </div>
            </div>
        </Field>
    );
}

function BudgetDialog({
    project,
    budget,
    onClose,
}: {
    project: string;
    budget?: AiBudgetRow;
    onClose: () => void;
}) {
    const t = useT();
    const { team } = usePage<{ team: TeamShared }>().props;
    const [scope, setScope] = useState<AiBudgetRow['scope']>(
        budget?.scope ?? 'project',
    );
    const [basis, setBasis] = useState<AiBudgetRow['basis']>(
        budget?.basis ?? 'cost',
    );
    const form = budget
        ? budgetUpdate.form([project, budget.uuid])
        : budgetStore.form(project);

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>
                        {budget
                            ? t('ai.budgets.form.edit_title')
                            : t('ai.budgets.form.add_title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('ai.budgets.alerts_hint')}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...form}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="flex flex-col gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <Field
                                label={t('ai.budgets.form.name')}
                                htmlFor="ai-budget-name"
                                hint={t('ai.budgets.form.name_hint')}
                                error={errors.name}
                            >
                                <Input
                                    id="ai-budget-name"
                                    name="name"
                                    maxLength={255}
                                    autoComplete="off"
                                    defaultValue={budget?.name ?? ''}
                                />
                            </Field>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field
                                    label={t('ai.budgets.form.scope')}
                                    htmlFor="ai-budget-scope"
                                    error={errors.scope}
                                >
                                    <Select
                                        id="ai-budget-scope"
                                        name="scope"
                                        value={scope}
                                        onChange={(e) =>
                                            setScope(
                                                e.target
                                                    .value as AiBudgetRow['scope'],
                                            )
                                        }
                                    >
                                        {(
                                            [
                                                'project',
                                                'key',
                                                'owner',
                                                'actor',
                                            ] as const
                                        ).map((value) => (
                                            <option key={value} value={value}>
                                                {t(
                                                    `ai.enums.budget_scope.${value}`,
                                                )}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                            </div>
                            {scope === 'key' && (
                                <KeyPicker
                                    project={project}
                                    kept={budget?.keys ?? []}
                                    error={
                                        errors.keys ??
                                        Object.entries(errors).find(([field]) =>
                                            field.startsWith('keys.'),
                                        )?.[1]
                                    }
                                />
                            )}
                            {(scope === 'owner' || scope === 'actor') && (
                                <ReferencePicker
                                    key={scope}
                                    kind={scope}
                                    project={project}
                                    kept={
                                        budget?.scope === scope
                                            ? budget.references
                                            : []
                                    }
                                    error={
                                        Object.entries(errors).find(
                                            ([field]) =>
                                                field === `${scope}s` ||
                                                field.startsWith(`${scope}s.`),
                                        )?.[1]
                                    }
                                />
                            )}
                            <div className="grid gap-3 sm:grid-cols-3">
                                <Field
                                    label={t('ai.budgets.form.period')}
                                    htmlFor="ai-budget-period"
                                    error={errors.period}
                                >
                                    <Select
                                        id="ai-budget-period"
                                        name="period"
                                        defaultValue={budget?.period ?? 'month'}
                                    >
                                        {(['day', 'month'] as const).map(
                                            (value) => (
                                                <option
                                                    key={value}
                                                    value={value}
                                                >
                                                    {t(
                                                        `ai.enums.budget_period.${value}`,
                                                    )}
                                                </option>
                                            ),
                                        )}
                                    </Select>
                                </Field>
                                <Field
                                    label={t('ai.budgets.form.basis')}
                                    htmlFor="ai-budget-basis"
                                    error={errors.basis}
                                >
                                    <Select
                                        id="ai-budget-basis"
                                        name="basis"
                                        value={basis}
                                        onChange={(e) =>
                                            setBasis(
                                                e.target
                                                    .value as AiBudgetRow['basis'],
                                            )
                                        }
                                    >
                                        <option value="cost">
                                            {`${t('ai.enums.budget_basis.cost')} (${team.currency})`}
                                        </option>
                                        <option value="tokens">
                                            {t('ai.enums.budget_basis.tokens')}
                                        </option>
                                    </Select>
                                </Field>
                                <Field
                                    label={t('ai.budgets.form.amount')}
                                    htmlFor="ai-budget-amount"
                                    hint={t('ai.budgets.form.amount_hint', {
                                        currency: team.currency,
                                    })}
                                    error={errors.amount}
                                >
                                    <Input
                                        id="ai-budget-amount"
                                        name="amount"
                                        type="number"
                                        inputMode={
                                            basis === 'cost'
                                                ? 'decimal'
                                                : 'numeric'
                                        }
                                        min={basis === 'cost' ? 0.01 : 1}
                                        step={basis === 'cost' ? 'any' : 1}
                                        required
                                        className="font-mono"
                                        defaultValue={budget?.amount}
                                    />
                                </Field>
                            </div>
                            <Field
                                label={t('ai.budgets.form.payer')}
                                htmlFor="ai-budget-payer"
                                hint={t('ai.budgets.form.payer_hint')}
                                error={errors.payer}
                                className="sm:max-w-xs"
                            >
                                <Select
                                    id="ai-budget-payer"
                                    name="payer"
                                    key={budget ? 'saved' : scope}
                                    defaultValue={
                                        budget?.payer ??
                                        (scope === 'owner' || scope === 'actor'
                                            ? 'all'
                                            : 'us')
                                    }
                                >
                                    {(['all', 'us', 'customer'] as const).map(
                                        (value) => (
                                            <option key={value} value={value}>
                                                {t(
                                                    `ai.enums.budget_payer.${value}`,
                                                )}
                                            </option>
                                        ),
                                    )}
                                </Select>
                            </Field>
                            <DialogFooter>
                                <Button onClick={onClose}>
                                    {t('common.cancel')}
                                </Button>
                                <Button
                                    type="submit"
                                    variant="primary"
                                    disabled={processing}
                                >
                                    {budget
                                        ? t('ai.budgets.form.save')
                                        : t('ai.budgets.form.add')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

type BudgetKey = AiBudgetRow['keys'][number];

function budgetKeyLabel(entry: BudgetKey, t: ReturnType<typeof useT>): string {
    return entry.key
        ? `${keyName(entry.key)} (${entry.short_id})`
        : t('ai.budgets.form.forgotten_key', { id: entry.short_id });
}

/** Keys a budget counts, searched and picked in a TreeSelect; forgotten ones stay picked. */
function KeyPicker({
    project,
    kept,
    error,
}: {
    project: string;
    kept: BudgetKey[];
    error?: string;
}) {
    const t = useT();
    const search = useAiTargetSearch(project, 'keys');
    const [picked, setPicked] = useState<string[]>(
        kept.map((entry) => entry.value),
    );
    // A key the budget counts, also one the project forgot, by what the budget says of it.
    const optionFor = (value: string): TreeOption => {
        const entry = kept.find((each) => each.value === value);

        return (
            search.known(value) ??
            (entry?.key
                ? keyOption(entry.key)
                : {
                      value,
                      label: entry ? budgetKeyLabel(entry, t) : value,
                  })
        );
    };

    return (
        <Field
            label={t('ai.budgets.form.keys')}
            htmlFor="ai-budget-keys"
            hint={t('ai.budgets.form.keys_hint')}
            error={error}
        >
            <TreeSelect
                id="ai-budget-keys"
                name="keys[]"
                label={t('ai.budgets.form.keys')}
                options={search.options}
                onSearch={search.onSearch}
                value={picked}
                onChange={(value) =>
                    value === null
                        ? setPicked([])
                        : setPicked(
                              picked.includes(value)
                                  ? picked.filter((each) => each !== value)
                                  : [...picked, value],
                          )
                }
                multiple
                optionFor={optionFor}
                pickedLabel={(count) =>
                    t('ai.enums.budget_keys', { count }, count)
                }
                placeholder={t('ai.budgets.form.pick_keys')}
                searchPlaceholder={t('ai.keys.search')}
                noMatches={search.noMatches}
                busy={search.busy}
                startOpen
            />
        </Field>
    );
}
