import { Form, Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    keyOption,
    useAiTargetSearch,
    usePriceScope,
    usePriceText,
} from '@/components/lj/ai-usage';
import type { Reference } from '@/components/lj/activity-filters';
import type { AiKeyRow, AiPriceRow } from '@/components/lj/ai-usage';
import { ConfirmButton } from '@/components/lj/confirm-button';
import { TopBar } from '@/components/lj/top-bar';
import { TreeSelect } from '@/components/lj/tree-select';
import {
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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { PageBody } from '@/layouts/lumberjack-layout';
import { formatDateTime } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as aiIndex, payer as defaultPayer } from '@/routes/ai';
import {
    destroy as priceDestroy,
    store as priceStore,
    update as priceUpdate,
} from '@/routes/ai/prices';
import { show as projectShow } from '@/routes/projects';
import type { ProjectHeader, TeamShared } from '@/types/lumberjack';

type Model = { provider: string; model: string };

type Props = {
    project: ProjectHeader;
    prices: AiPriceRow[];
    /** The models seen in the project, to match a pattern against. */
    models: Model[];
    /** The key owners used most lately, to suggest as typed. */
    owners: Reference[];
    /** The start of the day the project's first kept AI call was made, if any. */
    first_used_at: string | null;
    default_payer: 'us' | 'customer';
    can_update: boolean;
};

/** The models a pattern (a name, or a glob with *) matches, the way the server matches. */
function matching(models: Model[], pattern: string, provider: string | null) {
    const glob = new RegExp(
        `^${pattern
            .split('*')
            .map((part) => part.replace(/[.+?^${}()|[\]\\]/g, '\\$&'))
            .join('.*')}$`,
    );

    return models.filter(
        (m) =>
            (!provider ||
                m.provider.toLowerCase() === provider.toLowerCase()) &&
            glob.test(m.model),
    );
}

/** An ISO time as a datetime-local input wants it, in the browser's time zone. */
function localInput(iso: string): string {
    const date = new Date(iso);

    return new Date(date.getTime() - date.getTimezoneOffset() * 60_000)
        .toISOString()
        .slice(0, 16);
}

export default function AiPrices({
    project,
    prices,
    models,
    owners,
    first_used_at,
    default_payer,
    can_update,
}: Props) {
    const t = useT();
    const { team } = usePage<{ team: TeamShared }>().props;
    const scope = usePriceScope();
    const priceText = usePriceText();
    // null: closed; 'new': adding; else the price being edited.
    const [editing, setEditing] = useState<AiPriceRow | 'new' | null>(null);

    const matchText = (pattern: string, provider: string | null) => {
        const found = matching(models, pattern, provider);

        return t(
            'ai.prices.matches',
            {
                count: found.length,
                models: found
                    .slice(0, 5)
                    .map((m) => m.model)
                    .join(', ')
                    .concat(found.length > 5 ? ', ...' : ''),
            },
            found.length,
        );
    };

    return (
        <>
            <Head title={`${t('ai.prices.title')} · ${project.name}`} />
            <TopBar
                crumbs={[
                    { label: team.name },
                    {
                        label: project.name,
                        href: projectShow(project.uuid).url,
                        mono: true,
                    },
                    { label: t('ai.title'), href: aiIndex(project.uuid).url },
                    { label: t('common.nav.ai_prices') },
                ]}
            />
            <PageBody>
                <PageTitle
                    title={t('ai.prices.title')}
                    description={t('ai.prices.description', {
                        currency: team.currency,
                    })}
                    actions={
                        can_update && (
                            <Button
                                variant="primary"
                                onClick={() => setEditing('new')}
                            >
                                {t('ai.prices.add')}
                            </Button>
                        )
                    }
                />

                <Card aria-labelledby="ai-prices">
                    <CardHeader
                        id="ai-prices"
                        title={t('common.nav.ai_prices')}
                        description={t('ai.prices.rules')}
                    />
                    {prices.length === 0 ? (
                        <EmptyState title={t('ai.prices.empty')} />
                    ) : (
                        <div className="relative overflow-x-auto">
                            <table className={tableClass}>
                                <thead>
                                    <tr>
                                        <th className={thClass}>
                                            {t('ai.columns.model')}
                                        </th>
                                        <th className={thClass}>
                                            {t('ai.prices.applies_to')}
                                        </th>
                                        <th className={thClass}>
                                            {t('ai.prices.valid_from')}
                                        </th>
                                        <th className={thClass}>
                                            {t('ai.columns.price')}
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
                                    {prices.map((price) => (
                                        <tr
                                            key={price.uuid}
                                            className="align-top hover:bg-panel-2/60"
                                        >
                                            <td className={tdClass}>
                                                <span className="font-mono text-xs">
                                                    {price.model_pattern}
                                                </span>
                                                <span className="ml-2 font-mono text-[11px] text-ink-3">
                                                    {price.provider ??
                                                        t(
                                                            'ai.prices.any_provider',
                                                        )}
                                                </span>
                                                <p className="mt-0.5 text-[11px] text-ink-3">
                                                    {matchText(
                                                        price.model_pattern,
                                                        price.provider,
                                                    )}
                                                </p>
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    'text-xs text-ink-2',
                                                )}
                                            >
                                                {scope(price)}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    'font-mono text-xs whitespace-nowrap text-ink-2',
                                                )}
                                            >
                                                <time
                                                    dateTime={price.valid_from}
                                                >
                                                    {formatDateTime(
                                                        price.valid_from,
                                                    )}
                                                </time>
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    'text-xs',
                                                )}
                                            >
                                                {priceText(price)}
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
                                                                    price,
                                                                )
                                                            }
                                                            aria-label={t(
                                                                'ai.prices.edit_label',
                                                                {
                                                                    pattern:
                                                                        price.model_pattern,
                                                                },
                                                            )}
                                                        >
                                                            {t(
                                                                'ai.prices.edit',
                                                            )}
                                                        </Button>
                                                        <ConfirmButton
                                                            question={t(
                                                                'ai.prices.delete_question',
                                                            )}
                                                            confirmLabel={t(
                                                                'common.delete',
                                                            )}
                                                            onConfirm={() =>
                                                                router.delete(
                                                                    priceDestroy(
                                                                        [
                                                                            project.uuid,
                                                                            price.uuid,
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
                                                                    'ai.prices.delete_label',
                                                                    {
                                                                        pattern:
                                                                            price.model_pattern,
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

                <Card aria-labelledby="ai-payer">
                    <CardHeader
                        id="ai-payer"
                        title={t('ai.prices.payer.title')}
                        description={t('ai.prices.payer.description')}
                    />
                    {can_update ? (
                        <Form
                            {...defaultPayer.form(project.uuid)}
                            options={{ preserveScroll: true }}
                            className="flex flex-wrap items-end gap-3 p-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <Field
                                        label={t('ai.prices.payer.label')}
                                        htmlFor="ai-default-payer"
                                        error={errors.payer}
                                    >
                                        <Select
                                            id="ai-default-payer"
                                            name="payer"
                                            defaultValue={default_payer}
                                        >
                                            <option value="us">
                                                {t('ai.enums.payer.us')}
                                            </option>
                                            <option value="customer">
                                                {t('ai.enums.payer.customer')}
                                            </option>
                                        </Select>
                                    </Field>
                                    <Button
                                        type="submit"
                                        variant="primary"
                                        disabled={processing}
                                    >
                                        {t('ai.prices.payer.save')}
                                    </Button>
                                </>
                            )}
                        </Form>
                    ) : (
                        <p className="p-4 text-[13px]">
                            {t(`ai.enums.payer.${default_payer}`)}
                        </p>
                    )}
                </Card>
            </PageBody>

            {editing !== null && (
                <PriceDialog
                    project={project.uuid}
                    price={editing === 'new' ? undefined : editing}
                    owners={owners}
                    models={models}
                    firstUsedAt={first_used_at}
                    matchText={matchText}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

function PriceDialog({
    project,
    price,
    owners,
    models,
    firstUsedAt,
    matchText,
    onClose,
}: {
    project: string;
    price?: AiPriceRow;
    firstUsedAt: string | null;
    owners: Reference[];
    models: Model[];
    matchText: (pattern: string, provider: string | null) => string;
    onClose: () => void;
}) {
    const t = useT();
    const { team } = usePage<{ team: TeamShared }>().props;
    const [scope, setScope] = useState(price?.scope ?? 'project');
    const [pattern, setPattern] = useState(price?.model_pattern ?? '');
    const [provider, setProvider] = useState(price?.provider ?? '');
    const form = price
        ? priceUpdate.form([project, price.uuid])
        : priceStore.form(project);
    const providers = [...new Set(models.map((m) => m.provider))];
    const amount = (
        field: 'input' | 'output' | 'cache_read' | 'cache_write' | 'reasoning',
        error: string | undefined,
        hint?: string,
    ) => (
        <Field
            label={t(`ai.prices.form.${field}`)}
            htmlFor={`ai-price-${field}`}
            error={error}
            hint={hint}
        >
            <Input
                id={`ai-price-${field}`}
                name={field}
                type="number"
                inputMode="decimal"
                min={0}
                step="any"
                required={field === 'input' || field === 'output'}
                defaultValue={price?.[field] ?? undefined}
            />
        </Field>
    );

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {price
                            ? t('ai.prices.form.edit_title')
                            : t('ai.prices.form.add_title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('ai.prices.form.description', {
                            currency: team.currency,
                        })}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...form}
                    options={{ preserveScroll: true }}
                    transform={(data) => ({
                        ...data,
                        // The browser's local time, sent with its offset.
                        valid_from: data.valid_from
                            ? new Date(String(data.valid_from)).toISOString()
                            : '',
                    })}
                    onSuccess={onClose}
                    className="flex flex-col gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field
                                    label={t('ai.prices.form.scope')}
                                    htmlFor="ai-price-scope"
                                    error={errors.scope}
                                >
                                    <Select
                                        id="ai-price-scope"
                                        name="scope"
                                        value={scope}
                                        onChange={(e) =>
                                            setScope(
                                                e.target
                                                    .value as AiPriceRow['scope'],
                                            )
                                        }
                                    >
                                        <option value="project">
                                            {t('ai.enums.scope.project')}
                                        </option>
                                        <option value="owner">
                                            {t('ai.enums.scope.owner')}
                                        </option>
                                        <option value="key">
                                            {t('ai.enums.scope.key')}
                                        </option>
                                    </Select>
                                </Field>
                                {scope === 'project' && (
                                    <label className="flex items-center gap-2 self-end pb-1.5 text-[13px]">
                                        <input
                                            type="checkbox"
                                            name="team_wide"
                                            value="1"
                                            defaultChecked={price?.team_wide}
                                            className="size-4 accent-amber"
                                        />
                                        {t('ai.prices.form.team_wide')}
                                    </label>
                                )}
                                {scope === 'key' && (
                                    <PriceKeyPicker
                                        project={project}
                                        priced={price?.key ?? null}
                                        error={errors.key}
                                    />
                                )}
                            </div>
                            {scope === 'owner' && (
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <Field
                                        label={t('ai.prices.form.owner_type')}
                                        htmlFor="ai-price-owner-type"
                                        error={errors.owner_type}
                                    >
                                        <Input
                                            id="ai-price-owner-type"
                                            name="owner_type"
                                            list="ai-price-owner-types"
                                            required
                                            maxLength={128}
                                            defaultValue={price?.owner?.type}
                                        />
                                        <datalist id="ai-price-owner-types">
                                            {[
                                                ...new Set(
                                                    owners.map((o) => o.type),
                                                ),
                                            ].map((type) => (
                                                <option
                                                    key={type}
                                                    value={type}
                                                />
                                            ))}
                                        </datalist>
                                    </Field>
                                    <Field
                                        label={t('ai.prices.form.owner_id')}
                                        htmlFor="ai-price-owner-id"
                                        error={errors.owner_id}
                                    >
                                        <Input
                                            id="ai-price-owner-id"
                                            name="owner_id"
                                            list="ai-price-owner-ids"
                                            required
                                            maxLength={64}
                                            defaultValue={price?.owner?.id}
                                        />
                                        <datalist id="ai-price-owner-ids">
                                            {owners.map((o) => (
                                                <option
                                                    key={`${o.type}\0${o.id}`}
                                                    value={o.id}
                                                >
                                                    {`${o.type}: ${o.id}`}
                                                </option>
                                            ))}
                                        </datalist>
                                    </Field>
                                </div>
                            )}
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field
                                    label={t('ai.prices.form.provider')}
                                    htmlFor="ai-price-provider"
                                    hint={t('ai.prices.form.provider_hint')}
                                    error={errors.provider}
                                >
                                    <Input
                                        id="ai-price-provider"
                                        name="provider"
                                        list="ai-price-providers"
                                        maxLength={64}
                                        value={provider}
                                        onChange={(e) =>
                                            setProvider(e.target.value)
                                        }
                                        autoComplete="off"
                                    />
                                    <datalist id="ai-price-providers">
                                        {providers.map((p) => (
                                            <option key={p} value={p} />
                                        ))}
                                    </datalist>
                                </Field>
                                <Field
                                    label={t('ai.prices.form.model_pattern')}
                                    htmlFor="ai-price-model"
                                    hint={t('ai.prices.form.model_hint')}
                                    error={errors.model_pattern}
                                >
                                    <Input
                                        id="ai-price-model"
                                        name="model_pattern"
                                        list="ai-price-models"
                                        required
                                        maxLength={128}
                                        value={pattern}
                                        onChange={(e) =>
                                            setPattern(e.target.value)
                                        }
                                        autoComplete="off"
                                        aria-describedby="ai-price-matches"
                                        className="font-mono"
                                    />
                                    <datalist id="ai-price-models">
                                        {models.map((m) => (
                                            <option
                                                key={`${m.provider}:${m.model}`}
                                                value={m.model}
                                            />
                                        ))}
                                    </datalist>
                                </Field>
                            </div>
                            <p
                                id="ai-price-matches"
                                aria-live="polite"
                                className="-mt-2 text-xs text-ink-2"
                            >
                                {pattern.trim() !== '' &&
                                    matchText(pattern.trim(), provider.trim())}
                            </p>
                            <Field
                                label={t('ai.prices.form.valid_from')}
                                htmlFor="ai-price-valid-from"
                                error={errors.valid_from}
                                className="sm:max-w-xs"
                            >
                                <Input
                                    id="ai-price-valid-from"
                                    name="valid_from"
                                    type="datetime-local"
                                    required
                                    defaultValue={localInput(
                                        price?.valid_from ??
                                            firstUsedAt ??
                                            new Date().toISOString(),
                                    )}
                                />
                            </Field>
                            <div className="grid gap-3 sm:grid-cols-3">
                                {amount('input', errors.input)}
                                {amount('output', errors.output)}
                                {amount(
                                    'reasoning',
                                    errors.reasoning,
                                    t('ai.prices.form.reasoning_hint'),
                                )}
                                {amount(
                                    'cache_read',
                                    errors.cache_read,
                                    t('ai.prices.form.cache_hint'),
                                )}
                                {amount(
                                    'cache_write',
                                    errors.cache_write,
                                    t('ai.prices.form.cache_hint'),
                                )}
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
                                    {price
                                        ? t('ai.prices.form.save')
                                        : t('ai.prices.form.add')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

/** The key a price is for, searched as typed; asks the server only while key is the scope. */
function PriceKeyPicker({
    project,
    priced,
    error,
}: {
    project: string;
    priced: AiKeyRow | null;
    error?: string;
}) {
    const t = useT();
    const [key, setKey] = useState(priced?.uuid ?? '');
    const search = useAiTargetSearch(project, 'keys');

    return (
        <Field
            label={t('ai.prices.form.key')}
            htmlFor="ai-price-key"
            error={error}
        >
            <TreeSelect
                id="ai-price-key"
                name="key"
                label={t('ai.prices.form.key')}
                options={search.options}
                onSearch={search.onSearch}
                optionFor={(value) =>
                    search.known(value) ??
                    (priced?.uuid === value
                        ? keyOption(priced)
                        : { value, label: value })
                }
                value={key}
                onChange={(value) => setKey(value ?? '')}
                placeholder={t('ai.prices.form.pick_key')}
                searchPlaceholder={t('ai.keys.search')}
                noMatches={search.noMatches}
                busy={search.busy}
                startOpen
            />
        </Field>
    );
}
