import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Badge,
    Button,
    Field,
    Input,
    Select,
    Switch,
} from '@/components/lj/ui';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import {
    store as tokenStore,
    update as tokenUpdate,
} from '@/routes/mcp-tokens';

export type McpLevel = 'none' | 'read' | 'write' | 'manage';
export type McpCatalogTool = {
    name: string;
    label: string;
    level: McpLevel | null;
    deletes: boolean;
    sends_out: boolean;
    public: boolean;
};
export type McpCatalogArea = {
    key: string;
    label: string;
    description: string;
    team_wide: boolean;
    levels: McpLevel[];
    tools: McpCatalogTool[];
};
export type McpAreaGrant = {
    level?: McpLevel;
    grant?: string[];
    deny?: string[];
};
export type McpGrants = Record<string, McpAreaGrant>;
export type McpEditableToken = {
    id: number;
    name: string;
    all_projects: boolean;
    projects: { uuid: string; name: string }[];
    permissions: McpGrants;
    changes: Record<string, McpGrants>;
};

const LEVELS: McpLevel[] = ['none', 'read', 'write', 'manage'];
const rank = (level: McpLevel | undefined | null): number =>
    LEVELS.indexOf(level ?? 'none');
const DEFAULT = '*';

type Preset = 'read_only' | 'triage' | 'monitoring' | 'screens' | 'everything';
const PRESETS: Record<Preset, Record<string, McpLevel>> = {
    read_only: {
        issues: 'read',
        telemetry: 'read',
        health: 'read',
        schedule: 'read',
        alerts: 'read',
        data_sources: 'read',
        screens: 'read',
        project: 'read',
        team: 'read',
    },
    triage: {
        issues: 'write',
        telemetry: 'read',
        health: 'read',
        schedule: 'read',
        alerts: 'none',
        data_sources: 'none',
        screens: 'none',
        project: 'read',
        team: 'read',
    },
    monitoring: {
        issues: 'read',
        telemetry: 'read',
        health: 'manage',
        schedule: 'manage',
        alerts: 'write',
        data_sources: 'write',
        screens: 'none',
        project: 'read',
        team: 'read',
    },
    screens: {
        issues: 'read',
        telemetry: 'read',
        health: 'read',
        schedule: 'none',
        alerts: 'none',
        data_sources: 'read',
        screens: 'write',
        project: 'none',
        team: 'read',
    },
    everything: {
        issues: 'manage',
        telemetry: 'read',
        health: 'manage',
        schedule: 'manage',
        alerts: 'manage',
        data_sources: 'manage',
        screens: 'manage',
        project: 'manage',
        team: 'read',
    },
};

/** Per scope ('*' is Default, else a project uuid): grants per area. */
type State = Record<string, McpGrants>;

/**
 * The permission model, mirrored from McpToken::permits(): a project's single-tool
 * change, else its level, else Default's single-tool change, else Default's level.
 */
function useModel(catalog: McpCatalogArea[], state: State) {
    const areaOf = useMemo(() => {
        const map: Record<string, McpCatalogArea> = {};
        catalog.forEach((area) =>
            area.tools.forEach((tool) => (map[tool.name] = area)),
        );

        return map;
    }, [catalog]);

    const scopeFor = (area: McpCatalogArea, scope: string) =>
        area.team_wide ? DEFAULT : scope;
    const exception = (
        scope: string,
        area: string,
        tool: string,
    ): boolean | undefined => {
        const grant = state[scope]?.[area];

        if (grant?.deny?.includes(tool)) {
            return false;
        }

        return grant?.grant?.includes(tool) ? true : undefined;
    };
    const defaultLevel = (area: string): McpLevel =>
        state[DEFAULT]?.[area]?.level ?? 'none';
    const levelOf = (area: McpCatalogArea, scope: string): McpLevel =>
        state[scopeFor(area, scope)]?.[area.key]?.level ??
        defaultLevel(area.key);
    const byLevel = (tool: McpCatalogTool, level: McpLevel) =>
        rank(level) >= rank(tool.level);
    const baseline = (
        area: McpCatalogArea,
        tool: McpCatalogTool,
        scope: string,
    ): boolean => {
        const s = scopeFor(area, scope);

        if (s === DEFAULT) {
            return byLevel(tool, defaultLevel(area.key));
        }

        const own = state[s]?.[area.key]?.level;

        if (own) {
            return byLevel(tool, own);
        }

        return (
            exception(DEFAULT, area.key, tool.name) ??
            byLevel(tool, defaultLevel(area.key))
        );
    };
    const has = (
        area: McpCatalogArea,
        tool: McpCatalogTool,
        scope: string,
    ): boolean =>
        exception(scopeFor(area, scope), area.key, tool.name) ??
        baseline(area, tool, scope);

    return {
        areaOf,
        scopeFor,
        exception,
        defaultLevel,
        levelOf,
        baseline,
        has,
    };
}

/** Drops changes that say the same as what they change, Default first. */
function prune(state: State, catalog: McpCatalogArea[]): State {
    const next: State = JSON.parse(JSON.stringify(state));
    const scopes = [DEFAULT, ...Object.keys(next).filter((s) => s !== DEFAULT)];

    for (const area of catalog) {
        const byLevel = (tool: McpCatalogTool, level: McpLevel | undefined) =>
            rank(level) >= rank(tool.level);

        for (const scope of scopes) {
            const grant = next[scope]?.[area.key];

            if (!grant) {
                continue;
            }

            if (scope !== DEFAULT && area.team_wide) {
                delete next[scope][area.key];

                continue;
            }

            const fallback = next[DEFAULT]?.[area.key] ?? {};
            const defaultLevel = fallback.level ?? 'none';
            const defaultHasTools =
                (fallback.grant?.length ?? 0) + (fallback.deny?.length ?? 0) >
                0;

            if (
                scope !== DEFAULT &&
                grant.level === defaultLevel &&
                !defaultHasTools
            ) {
                delete grant.level;
            }

            const base = (tool: McpCatalogTool): boolean => {
                if (scope === DEFAULT) {
                    return byLevel(tool, defaultLevel);
                }

                if (grant.level) {
                    return byLevel(tool, grant.level);
                }

                if (fallback.deny?.includes(tool.name)) {
                    return false;
                }

                return fallback.grant?.includes(tool.name)
                    ? true
                    : byLevel(tool, defaultLevel);
            };
            const find = (name: string) =>
                area.tools.find((tool) => tool.name === name);
            grant.grant = (grant.grant ?? []).filter((name) => {
                const tool = find(name);
                return tool !== undefined && !base(tool);
            });
            grant.deny = (grant.deny ?? []).filter((name) => {
                const tool = find(name);
                return tool !== undefined && base(tool);
            });

            if (
                scope !== DEFAULT &&
                !grant.level &&
                grant.grant.length === 0 &&
                grant.deny.length === 0
            ) {
                delete next[scope][area.key];
            }
        }
    }

    return next;
}

export function McpTokenEditor({
    open,
    onClose,
    catalog,
    projects,
    token,
}: {
    open: boolean;
    onClose: () => void;
    catalog: McpCatalogArea[];
    projects: { uuid: string; name: string }[];
    token: McpEditableToken | null;
}) {
    const t = useT();
    const [name, setName] = useState(token?.name ?? '');
    const [expires, setExpires] = useState('90');
    const [allProjects, setAllProjects] = useState(
        token?.all_projects ?? false,
    );
    const [picked, setPicked] = useState<string[]>(
        token?.projects.map((p) => p.uuid) ??
            (projects.length === 1 ? [projects[0].uuid] : []),
    );
    const [state, setState] = useState<State>(() => ({
        [DEFAULT]: token
            ? { ...token.permissions }
            : Object.fromEntries(
                  Object.entries(PRESETS.read_only).map(([k, l]) => [
                      k,
                      { level: l },
                  ]),
              ),
        ...token?.changes,
    }));
    const [tab, setTab] = useState(DEFAULT);
    const [query, setQuery] = useState('');
    const [openAreas, setOpenAreas] = useState<string[]>([]);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const model = useModel(catalog, state);

    const covered = allProjects
        ? projects
        : projects.filter((p) => picked.includes(p.uuid));
    const activeTab =
        tab === DEFAULT || covered.some((p) => p.uuid === tab) ? tab : DEFAULT;
    const editable = catalog.filter(
        (area) => activeTab === DEFAULT || !area.team_wide,
    );

    const update = (change: (draft: State) => void) =>
        setState((current) => {
            const draft: State = JSON.parse(JSON.stringify(current));
            change(draft);

            return prune(draft, catalog);
        });
    const grantIn = (
        draft: State,
        scope: string,
        area: string,
    ): McpAreaGrant => {
        draft[scope] ??= {};
        draft[scope][area] ??= {};

        return draft[scope][area];
    };
    const setLevel = (area: McpCatalogArea, level: McpLevel) =>
        update((draft) => {
            grantIn(draft, model.scopeFor(area, activeTab), area.key).level =
                level;
        });
    const toggleTool = (area: McpCatalogArea, tool: McpCatalogTool) =>
        update((draft) => {
            const g = grantIn(draft, model.scopeFor(area, activeTab), area.key);
            const on = !model.has(area, tool, activeTab);
            g.grant = (g.grant ?? []).filter((n) => n !== tool.name);
            g.deny = (g.deny ?? []).filter((n) => n !== tool.name);

            if (on !== model.baseline(area, tool, activeTab)) {
                (on ? g.grant : g.deny).push(tool.name);
            }
        });
    const clearTools = (area: McpCatalogArea) =>
        update((draft) => {
            const g = grantIn(draft, model.scopeFor(area, activeTab), area.key);
            g.grant = [];
            g.deny = [];
        });
    const resetLevel = (area: McpCatalogArea) =>
        update((draft) => {
            delete grantIn(draft, activeTab, area.key).level;
        });
    const applyLevels = (levels: Record<string, McpLevel>, clear: boolean) =>
        update((draft) => {
            editable.forEach((area) => {
                const g = grantIn(draft, activeTab, area.key);
                const wanted = levels[area.key] ?? 'none';
                g.level =
                    area.levels.filter((l) => rank(l) <= rank(wanted)).pop() ??
                    'none';

                if (clear) {
                    g.grant = [];
                    g.deny = [];
                }
            });
        });

    const exceptionsIn = (area: McpCatalogArea) =>
        area.tools.filter(
            (tool) =>
                model.exception(
                    model.scopeFor(area, activeTab),
                    area.key,
                    tool.name,
                ) !== undefined,
        );
    const preset = (Object.keys(PRESETS) as Preset[]).find((key) =>
        editable.every(
            (area) =>
                model.levelOf(area, activeTab) ===
                    area.levels
                        .filter((l) => rank(l) <= rank(PRESETS[key][area.key]))
                        .pop() && exceptionsIn(area).length === 0,
        ),
    );
    const changeCount = (uuid: string) =>
        Object.values(state[uuid] ?? {}).reduce(
            (n, g) =>
                n +
                (g.level ? 1 : 0) +
                (g.grant?.length ?? 0) +
                (g.deny?.length ?? 0),
            0,
        );

    // Union over every scope the token reaches.
    const scopes = [
        ...(allProjects ? [DEFAULT] : []),
        ...covered.map((p) => p.uuid),
    ];
    const reachable = catalog.flatMap((area) =>
        area.tools.filter((tool) =>
            scopes.some((scope) => model.has(area, tool, scope)),
        ),
    );
    const deletes = reachable.filter((tool) => tool.deletes);
    const sendsOut = reachable.filter((tool) => tool.sends_out);
    const publics = reachable.filter((tool) => tool.public);
    const readOnly = reachable.every((tool) => rank(tool.level) <= 1);

    const submit = () => {
        setProcessing(true);
        const changes = Object.fromEntries(
            Object.entries(state).filter(
                ([scope]) =>
                    scope !== DEFAULT && covered.some((p) => p.uuid === scope),
            ),
        );
        const payload = {
            name,
            all_projects: allProjects,
            projects: allProjects ? [] : picked,
            permissions: state[DEFAULT],
            changes,
            ...(token
                ? {}
                : { expires_in_days: expires === '' ? null : Number(expires) }),
        };
        const options = {
            preserveScroll: true,
            onError: (e: Record<string, string>) => setErrors(e),
            onSuccess: () => onClose(),
            onFinish: () => setProcessing(false),
        };

        if (token) {
            router.put(tokenUpdate(token.id).url, payload, options);
        } else {
            router.post(tokenStore.url(), payload, options);
        }
    };

    const tabs = [
        { key: DEFAULT, label: t('team.tokens.editor.default') },
        ...covered.map((p) => ({ key: p.uuid, label: p.name })),
    ];
    const tabName = covered.find((p) => p.uuid === activeTab)?.name ?? '';

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[92dvh] gap-0 overflow-hidden border-line bg-panel p-0 sm:max-w-5xl">
                <div className="flex flex-wrap items-baseline justify-between gap-3 border-b border-line py-4 pr-12 pl-5">
                    <DialogTitle className="text-sm font-semibold">
                        {token
                            ? t('team.tokens.editor.edit_title', {
                                  name: token.name,
                              })
                            : t('team.tokens.editor.create_title')}
                    </DialogTitle>
                    <DialogDescription className="text-xs text-ink-3">
                        {t('team.tokens.editor.acts_as_you')}
                    </DialogDescription>
                </div>
                <div className="grid max-h-[calc(92dvh-60px)] grid-cols-1 overflow-y-auto md:grid-cols-[minmax(0,1fr)_280px]">
                    <div className="flex min-w-0 flex-col gap-5 p-5">
                        <div className="flex flex-wrap gap-3">
                            <Field
                                label={t('team.tokens.name')}
                                htmlFor="token-name"
                                error={errors.name}
                                className="min-w-0 flex-[2_1_220px]"
                            >
                                <Input
                                    id="token-name"
                                    value={name}
                                    onChange={(e) => setName(e.target.value)}
                                    required
                                    maxLength={100}
                                    className="font-mono"
                                    placeholder="claude-local"
                                    autoComplete="off"
                                />
                            </Field>
                            {!token && (
                                <Field
                                    label={t('team.tokens.expires')}
                                    htmlFor="token-expires"
                                    error={errors.expires_in_days}
                                    className="flex-[1_1_160px]"
                                >
                                    <Select
                                        id="token-expires"
                                        value={expires}
                                        onChange={(e) =>
                                            setExpires(e.target.value)
                                        }
                                    >
                                        {['30', '90', '365'].map((days) => (
                                            <option key={days} value={days}>
                                                {t(
                                                    'team.tokens.expires_in',
                                                    { count: Number(days) },
                                                    Number(days),
                                                )}
                                            </option>
                                        ))}
                                        <option value="">
                                            {t('team.tokens.never')}
                                        </option>
                                    </Select>
                                </Field>
                            )}
                        </div>

                        <fieldset className="flex flex-col gap-2">
                            <legend className="mb-1.5 text-[13px] font-medium">
                                {t('team.tokens.projects')}
                            </legend>
                            <div
                                role="radiogroup"
                                aria-label={t('team.tokens.projects')}
                                className="inline-flex w-fit overflow-hidden rounded-md border border-line-strong"
                            >
                                {[true, false].map((all) => (
                                    <button
                                        key={String(all)}
                                        type="button"
                                        role="radio"
                                        aria-checked={allProjects === all}
                                        onClick={() => setAllProjects(all)}
                                        className="h-[30px] cursor-pointer border-r border-line-strong bg-panel px-3 text-xs text-ink-2 last:border-r-0 hover:text-ink aria-checked:bg-line aria-checked:text-ink"
                                    >
                                        {t(
                                            all
                                                ? 'team.tokens.editor.all_projects'
                                                : 'team.tokens.editor.selected_projects',
                                        )}
                                    </button>
                                ))}
                            </div>
                            {!allProjects && (
                                <div className="flex flex-wrap gap-x-5 gap-y-2">
                                    {projects.map((project) => (
                                        <label
                                            key={project.uuid}
                                            className="inline-flex items-center gap-2 pointer-coarse:min-h-10"
                                        >
                                            <Switch
                                                small
                                                label={project.name}
                                                checked={picked.includes(
                                                    project.uuid,
                                                )}
                                                onChange={(on) =>
                                                    setPicked((p) =>
                                                        on
                                                            ? [
                                                                  ...p,
                                                                  project.uuid,
                                                              ]
                                                            : p.filter(
                                                                  (u) =>
                                                                      u !==
                                                                      project.uuid,
                                                              ),
                                                    )
                                                }
                                            />
                                            <span className="font-mono text-xs">
                                                {project.name}
                                            </span>
                                        </label>
                                    ))}
                                </div>
                            )}
                            <p className="text-xs text-ink-3">
                                {allProjects
                                    ? t(
                                          'team.tokens.editor.all_projects_hint',
                                          { count: projects.length },
                                      )
                                    : t(
                                          'team.tokens.editor.selected_projects_hint',
                                      )}
                            </p>
                            {errors.projects && (
                                <p className="text-xs text-err">
                                    {errors.projects}
                                </p>
                            )}
                        </fieldset>

                        <div className="flex flex-col">
                            <div
                                role="tablist"
                                aria-label={t(
                                    'team.tokens.editor.permissions_for',
                                )}
                                className="flex gap-0.5 overflow-x-auto border-b border-line"
                            >
                                {tabs.map((tb) => {
                                    const count =
                                        tb.key === DEFAULT
                                            ? 0
                                            : changeCount(tb.key);

                                    return (
                                        <button
                                            key={tb.key}
                                            type="button"
                                            role="tab"
                                            aria-selected={activeTab === tb.key}
                                            onClick={() => setTab(tb.key)}
                                            className="-mb-px flex cursor-pointer items-center gap-1.5 border-b-2 border-transparent px-3 py-1.5 text-[13px] whitespace-nowrap text-ink-2 aria-selected:border-amber aria-selected:font-semibold aria-selected:text-ink"
                                        >
                                            {tb.label}
                                            {count > 0 && (
                                                <Badge tone="amber">
                                                    {count}
                                                </Badge>
                                            )}
                                        </button>
                                    );
                                })}
                            </div>
                            <p className="pt-2 text-xs text-ink-3">
                                {activeTab === DEFAULT
                                    ? t('team.tokens.editor.default_hint')
                                    : t('team.tokens.editor.project_hint', {
                                          project: tabName,
                                      })}
                            </p>
                        </div>

                        <div className="flex flex-col gap-1.5">
                            <span className="text-[13px] font-medium">
                                {t('team.tokens.editor.start_from')}
                            </span>
                            <div className="flex flex-wrap gap-1.5">
                                {(Object.keys(PRESETS) as Preset[]).map(
                                    (key) => (
                                        <button
                                            key={key}
                                            type="button"
                                            aria-pressed={preset === key}
                                            onClick={() =>
                                                applyLevels(PRESETS[key], true)
                                            }
                                            className="cursor-pointer rounded-full border border-line-strong bg-panel px-3 py-1 text-xs text-ink hover:border-ink-3 aria-pressed:border-ink aria-pressed:bg-ink aria-pressed:text-panel"
                                        >
                                            {t(
                                                `team.tokens.editor.presets.${key}`,
                                            )}
                                        </button>
                                    ),
                                )}
                                <span
                                    aria-hidden={preset !== undefined}
                                    className={cn(
                                        'rounded-full border px-3 py-1 text-xs',
                                        preset === undefined
                                            ? 'border-ink bg-ink text-panel'
                                            : 'border-line text-ink-3',
                                    )}
                                >
                                    {t('team.tokens.editor.custom')}
                                </span>
                            </div>
                        </div>

                        <Field
                            label={t('team.tokens.editor.find_tool')}
                            htmlFor="token-tool-search"
                        >
                            <Input
                                id="token-tool-search"
                                type="search"
                                value={query}
                                onChange={(e) =>
                                    setQuery(
                                        e.target.value.trim().toLowerCase(),
                                    )
                                }
                                placeholder={t(
                                    'team.tokens.editor.find_tool_placeholder',
                                )}
                                autoComplete="off"
                            />
                        </Field>

                        <div className="overflow-hidden rounded-lg border border-line">
                            <div className="hidden grid-cols-[minmax(0,1fr)_300px] items-center gap-3 border-b border-line bg-panel-2 px-3.5 py-2 text-[11.5px] text-ink-3 sm:grid">
                                <span>{t('team.tokens.editor.area')}</span>
                                <div className="grid grid-cols-4 text-center">
                                    {LEVELS.map((level) => (
                                        <button
                                            key={level}
                                            type="button"
                                            title={t(
                                                'team.tokens.editor.set_all',
                                                {
                                                    level: t(
                                                        `team.tokens.editor.levels.${level}`,
                                                    ),
                                                },
                                            )}
                                            onClick={() =>
                                                applyLevels(
                                                    Object.fromEntries(
                                                        catalog.map((a) => [
                                                            a.key,
                                                            level,
                                                        ]),
                                                    ),
                                                    false,
                                                )
                                            }
                                            className="cursor-pointer rounded font-semibold tracking-wide uppercase hover:text-amber"
                                        >
                                            {t(
                                                `team.tokens.editor.levels.${level}`,
                                            )}
                                        </button>
                                    ))}
                                </div>
                            </div>
                            {catalog.map((area) => {
                                const match = query
                                    ? area.tools.filter(
                                          (tool) =>
                                              tool.name.includes(query) ||
                                              tool.label
                                                  .toLowerCase()
                                                  .includes(query),
                                      )
                                    : area.tools;

                                if (query && match.length === 0) {
                                    return null;
                                }

                                const locked =
                                    activeTab !== DEFAULT && area.team_wide;
                                const level = model.levelOf(area, activeTab);
                                const levelChanged =
                                    activeTab !== DEFAULT &&
                                    !area.team_wide &&
                                    state[activeTab]?.[area.key]?.level !==
                                        undefined;
                                const changed = exceptionsIn(area);
                                const granted = area.tools.filter((tool) =>
                                    model.has(area, tool, activeTab),
                                );
                                const expanded =
                                    openAreas.includes(area.key) ||
                                    query !== '';
                                const warnings = [
                                    granted.some((x) => x.deletes) &&
                                        t('team.tokens.editor.warn_deletes'),
                                    granted.some((x) => x.sends_out) &&
                                        t('team.tokens.editor.warn_sends_out'),
                                    granted.some((x) => x.public) &&
                                        t('team.tokens.editor.warn_public'),
                                    granted.some((x) => rank(x.level) > 1) &&
                                        !granted.some(
                                            (x) => x.level === 'read',
                                        ) &&
                                        t('team.tokens.editor.warn_no_read'),
                                ].filter(Boolean);

                                return (
                                    <div
                                        key={area.key}
                                        className={cn(
                                            'grid grid-cols-1 items-center gap-x-3 gap-y-2 border-t border-line px-3.5 py-2.5 first:border-t-0 sm:grid-cols-[minmax(0,1fr)_300px]',
                                            (levelChanged ||
                                                changed.length > 0) &&
                                                'shadow-[inset_3px_0_0_var(--color-amber)]',
                                        )}
                                    >
                                        <div
                                            className={cn(
                                                'min-w-0',
                                                locked && 'opacity-60',
                                            )}
                                        >
                                            <div className="flex flex-wrap items-baseline gap-2">
                                                <strong className="font-semibold">
                                                    {area.label}
                                                </strong>
                                                {area.team_wide && (
                                                    <Badge>
                                                        {t(
                                                            'team.tokens.editor.team_wide',
                                                        )}
                                                    </Badge>
                                                )}
                                                {locked && (
                                                    <span className="text-[11.5px] text-ink-3">
                                                        {t(
                                                            'team.tokens.editor.team_wide_hint',
                                                        )}
                                                    </span>
                                                )}
                                                {levelChanged && (
                                                    <>
                                                        <Badge tone="amber">
                                                            {t(
                                                                'team.tokens.editor.level_changed',
                                                                {
                                                                    level: t(
                                                                        `team.tokens.editor.levels.${model.defaultLevel(area.key)}`,
                                                                    ),
                                                                },
                                                            )}
                                                        </Badge>
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                resetLevel(area)
                                                            }
                                                            className="cursor-pointer text-xs text-amber"
                                                        >
                                                            {t(
                                                                'team.tokens.editor.reset',
                                                            )}
                                                        </button>
                                                    </>
                                                )}
                                                {changed.length > 0 &&
                                                    !locked && (
                                                        <>
                                                            <Badge tone="info">
                                                                {t(
                                                                    'team.tokens.editor.tools_changed',
                                                                    {
                                                                        count: changed.length,
                                                                    },
                                                                    changed.length,
                                                                )}
                                                            </Badge>
                                                            <button
                                                                type="button"
                                                                onClick={() =>
                                                                    clearTools(
                                                                        area,
                                                                    )
                                                                }
                                                                className="cursor-pointer text-xs text-amber"
                                                            >
                                                                {t(
                                                                    'team.tokens.editor.reset_tools',
                                                                )}
                                                            </button>
                                                        </>
                                                    )}
                                                {activeTab !== DEFAULT &&
                                                    !locked &&
                                                    !levelChanged &&
                                                    changed.length === 0 && (
                                                        <span className="text-[11.5px] text-ink-3">
                                                            {t(
                                                                'team.tokens.editor.follows_default',
                                                            )}
                                                        </span>
                                                    )}
                                            </div>
                                            <div className="mt-0.5 text-xs text-ink-3">
                                                {area.description}
                                            </div>
                                            {query === '' && (
                                                <button
                                                    type="button"
                                                    aria-expanded={expanded}
                                                    onClick={() =>
                                                        setOpenAreas((o) =>
                                                            o.includes(area.key)
                                                                ? o.filter(
                                                                      (k) =>
                                                                          k !==
                                                                          area.key,
                                                                  )
                                                                : [
                                                                      ...o,
                                                                      area.key,
                                                                  ],
                                                        )
                                                    }
                                                    className="mt-1 cursor-pointer text-xs text-amber"
                                                >
                                                    {expanded
                                                        ? t(
                                                              'team.tokens.editor.hide_tools',
                                                          )
                                                        : t(
                                                              'team.tokens.editor.show_tools',
                                                              {
                                                                  on: granted.length,
                                                                  total: area
                                                                      .tools
                                                                      .length,
                                                              },
                                                          )}
                                                </button>
                                            )}
                                        </div>
                                        <div
                                            role="radiogroup"
                                            aria-label={t(
                                                'team.tokens.editor.access',
                                                { area: area.label },
                                            )}
                                            className="grid grid-cols-4 gap-0.5 rounded-md border border-line bg-panel-2 p-0.5"
                                        >
                                            {LEVELS.map((l) => {
                                                const offered =
                                                    area.levels.includes(l);

                                                return (
                                                    <button
                                                        key={l}
                                                        type="button"
                                                        role="radio"
                                                        aria-checked={
                                                            level === l
                                                        }
                                                        disabled={
                                                            !offered || locked
                                                        }
                                                        title={
                                                            locked
                                                                ? t(
                                                                      'team.tokens.editor.team_wide_hint',
                                                                  )
                                                                : offered
                                                                  ? undefined
                                                                  : t(
                                                                        'team.tokens.editor.not_offered',
                                                                    )
                                                        }
                                                        onClick={() =>
                                                            setLevel(area, l)
                                                        }
                                                        className={cn(
                                                            'cursor-pointer rounded py-1 text-xs text-ink-2 hover:enabled:bg-panel hover:enabled:text-ink disabled:cursor-not-allowed disabled:opacity-35 aria-checked:bg-panel aria-checked:font-semibold aria-checked:text-ink aria-checked:ring-1 aria-checked:ring-line-strong',
                                                            level === l &&
                                                                l ===
                                                                    'manage' &&
                                                                'aria-checked:bg-amber/15 aria-checked:text-amber aria-checked:ring-amber',
                                                        )}
                                                    >
                                                        {t(
                                                            `team.tokens.editor.levels.${l}`,
                                                        )}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                        {warnings.length > 0 && !locked && (
                                            <p className="rounded bg-warn-bg px-2.5 py-1.5 text-xs text-warn sm:col-span-2">
                                                {warnings.join(' ')}
                                            </p>
                                        )}
                                        {expanded && (
                                            <div
                                                role="group"
                                                aria-label={area.label}
                                                className="overflow-hidden rounded-md border border-line sm:col-span-2"
                                            >
                                                {match.map((tool) => {
                                                    const e = model.exception(
                                                        model.scopeFor(
                                                            area,
                                                            activeTab,
                                                        ),
                                                        area.key,
                                                        tool.name,
                                                    );
                                                    const id = `tool-${tool.name}`;

                                                    return (
                                                        <label
                                                            key={tool.name}
                                                            htmlFor={id}
                                                            className={cn(
                                                                'flex cursor-pointer flex-wrap items-center gap-x-2 gap-y-1 border-t border-line px-2.5 py-1.5 text-[12.5px] first:border-t-0 hover:bg-panel-2',
                                                                e === true &&
                                                                    'shadow-[inset_3px_0_0_var(--color-ok)]',
                                                                e === false &&
                                                                    'shadow-[inset_3px_0_0_var(--color-err)]',
                                                                locked &&
                                                                    'cursor-default opacity-60',
                                                            )}
                                                        >
                                                            <input
                                                                id={id}
                                                                type="checkbox"
                                                                checked={model.has(
                                                                    area,
                                                                    tool,
                                                                    activeTab,
                                                                )}
                                                                disabled={
                                                                    locked
                                                                }
                                                                onChange={() =>
                                                                    toggleTool(
                                                                        area,
                                                                        tool,
                                                                    )
                                                                }
                                                                className="size-4 accent-amber"
                                                            />
                                                            <code
                                                                className={cn(
                                                                    'font-mono text-xs',
                                                                    e ===
                                                                        false &&
                                                                        'line-through',
                                                                )}
                                                            >
                                                                {tool.name}
                                                            </code>
                                                            {tool.level && (
                                                                <Badge
                                                                    tone={
                                                                        tool.level ===
                                                                        'read'
                                                                            ? 'info'
                                                                            : tool.level ===
                                                                                'write'
                                                                              ? 'ok'
                                                                              : 'amber'
                                                                    }
                                                                >
                                                                    {t(
                                                                        `team.tokens.editor.levels.${tool.level}`,
                                                                    )}
                                                                </Badge>
                                                            )}
                                                            {tool.deletes && (
                                                                <span className="text-[11px] text-err">
                                                                    {t(
                                                                        'team.tokens.editor.tag_deletes',
                                                                    )}
                                                                </span>
                                                            )}
                                                            {tool.sends_out && (
                                                                <span className="text-[11px] text-info">
                                                                    {t(
                                                                        'team.tokens.editor.tag_sends_out',
                                                                    )}
                                                                </span>
                                                            )}
                                                            {tool.public && (
                                                                <span className="text-[11px] text-warn">
                                                                    {t(
                                                                        'team.tokens.editor.tag_public',
                                                                    )}
                                                                </span>
                                                            )}
                                                            <span className="min-w-0 flex-[1_1_180px] text-ink-3">
                                                                {tool.label}
                                                            </span>
                                                            {e !==
                                                                undefined && (
                                                                <span
                                                                    className={cn(
                                                                        'text-[11px] font-semibold',
                                                                        e
                                                                            ? 'text-ok'
                                                                            : 'text-err',
                                                                    )}
                                                                >
                                                                    {t(
                                                                        e
                                                                            ? 'team.tokens.editor.added'
                                                                            : 'team.tokens.editor.removed',
                                                                    )}
                                                                </span>
                                                            )}
                                                        </label>
                                                    );
                                                })}
                                            </div>
                                        )}
                                    </div>
                                );
                            })}
                            {query !== '' &&
                                catalog.every(
                                    (area) =>
                                        !area.tools.some(
                                            (tool) =>
                                                tool.name.includes(query) ||
                                                tool.label
                                                    .toLowerCase()
                                                    .includes(query),
                                        ),
                                ) && (
                                    <p className="border-t border-line p-4 text-[13px] text-ink-3">
                                        {t(
                                            'team.tokens.editor.no_tool_matches',
                                            { query },
                                        )}
                                    </p>
                                )}
                        </div>
                        {(errors.permissions ||
                            errors.changes ||
                            Object.keys(errors).some(
                                (k) =>
                                    k.startsWith('permissions.') ||
                                    k.startsWith('changes.'),
                            )) && (
                            <p className="text-xs text-err">
                                {
                                    Object.entries(errors).find(
                                        ([k]) =>
                                            k.startsWith('permissions') ||
                                            k.startsWith('changes'),
                                    )?.[1]
                                }
                            </p>
                        )}
                    </div>

                    <aside
                        aria-live="polite"
                        className="border-t border-line bg-panel-2 p-5 md:border-t-0 md:border-l"
                    >
                        <div className="sticky top-0 flex flex-col gap-4">
                            <div>
                                <div className="text-[11px] font-semibold tracking-wider text-ink-3 uppercase">
                                    {t('team.tokens.editor.can_use')}
                                </div>
                                <div>
                                    <span className="text-3xl leading-none font-semibold tabular-nums">
                                        {reachable.length}
                                    </span>{' '}
                                    <span className="text-xs text-ink-3">
                                        {t(
                                            'team.tokens.tool_count',
                                            { count: reachable.length },
                                            reachable.length,
                                        ).replace(/^\d+\s/, '')}
                                    </span>
                                </div>
                            </div>
                            <dl className="flex flex-col gap-1.5 text-[12.5px]">
                                <dt className="text-[10.5px] font-semibold tracking-wider text-ink-3 uppercase">
                                    {t('team.tokens.editor.summary_default')}
                                </dt>
                                {(
                                    ['manage', 'write', 'read'] as McpLevel[]
                                ).map((l) => {
                                    const names = catalog
                                        .filter(
                                            (a) =>
                                                model.defaultLevel(a.key) === l,
                                        )
                                        .map((a) => a.label);

                                    return (
                                        <dd
                                            key={l}
                                            className="flex justify-between gap-2 text-ink-2"
                                        >
                                            <span>
                                                {t(
                                                    `team.tokens.editor.levels.${l}`,
                                                )}
                                            </span>
                                            <b className="text-right font-semibold text-ink">
                                                {names.length
                                                    ? names.join(', ')
                                                    : '-'}
                                            </b>
                                        </dd>
                                    );
                                })}
                                {covered.some(
                                    (p) => changeCount(p.uuid) > 0,
                                ) && (
                                    <>
                                        <dt className="pt-1.5 text-[10.5px] font-semibold tracking-wider text-ink-3 uppercase">
                                            {t(
                                                'team.tokens.editor.summary_per_project',
                                            )}
                                        </dt>
                                        {covered
                                            .filter(
                                                (p) => changeCount(p.uuid) > 0,
                                            )
                                            .map((p) => (
                                                <dd
                                                    key={p.uuid}
                                                    className="flex justify-between gap-2 text-ink-2"
                                                >
                                                    <span>{p.name}</span>
                                                    <b className="text-right font-semibold text-ink">
                                                        {t(
                                                            'team.tokens.editor.tools_changed',
                                                            {
                                                                count: changeCount(
                                                                    p.uuid,
                                                                ),
                                                            },
                                                            changeCount(p.uuid),
                                                        )}
                                                    </b>
                                                </dd>
                                            ))}
                                    </>
                                )}
                                <dt className="pt-1.5 text-[10.5px] font-semibold tracking-wider text-ink-3 uppercase">
                                    {t('team.tokens.editor.summary_projects')}
                                </dt>
                                <dd className="text-ink">
                                    {allProjects
                                        ? t(
                                              'team.tokens.editor.summary_all_projects',
                                          )
                                        : covered
                                              .map((p) => p.name)
                                              .join(', ') || '-'}
                                </dd>
                            </dl>
                            <div className="flex flex-col gap-2 text-[12.5px]">
                                {deletes.length > 0 && (
                                    <p className="rounded-md bg-err-bg px-2.5 py-2 text-err">
                                        {t(
                                            'team.tokens.editor.flag_deletes',
                                            { count: deletes.length },
                                            deletes.length,
                                        )}
                                    </p>
                                )}
                                {sendsOut.length > 0 && (
                                    <p className="rounded-md bg-warn-bg px-2.5 py-2 text-warn">
                                        {t(
                                            'team.tokens.editor.flag_sends_out',
                                            {
                                                tools: sendsOut
                                                    .map((x) => x.name)
                                                    .join(', '),
                                            },
                                        )}
                                    </p>
                                )}
                                {publics.length > 0 && (
                                    <p className="rounded-md bg-warn-bg px-2.5 py-2 text-warn">
                                        {t('team.tokens.editor.flag_public', {
                                            tools: publics
                                                .map((x) => x.name)
                                                .join(', '),
                                        })}
                                    </p>
                                )}
                                {allProjects && (
                                    <p className="rounded-md bg-info-bg px-2.5 py-2 text-info">
                                        {t(
                                            'team.tokens.editor.flag_new_projects',
                                        )}
                                    </p>
                                )}
                                {catalog.some(
                                    (a) =>
                                        a.team_wide &&
                                        a.tools.some(
                                            (x) =>
                                                rank(x.level) > 1 &&
                                                model.has(a, x, DEFAULT),
                                        ),
                                ) && (
                                    <p className="rounded-md bg-info-bg px-2.5 py-2 text-info">
                                        {t('team.tokens.editor.flag_team_wide')}
                                    </p>
                                )}
                                {covered.length === 0 && (
                                    <p className="rounded-md bg-err-bg px-2.5 py-2 text-err">
                                        {t(
                                            'team.tokens.editor.flag_no_projects',
                                        )}
                                    </p>
                                )}
                                {readOnly && covered.length > 0 && (
                                    <p className="rounded-md bg-ok-bg px-2.5 py-2 text-ok">
                                        {t('team.tokens.editor.flag_read_only')}
                                    </p>
                                )}
                            </div>
                            <div className="flex gap-2">
                                <Button
                                    type="button"
                                    variant="primary"
                                    disabled={
                                        processing ||
                                        covered.length === 0 ||
                                        name.trim() === ''
                                    }
                                    onClick={submit}
                                    className="flex-1"
                                >
                                    {t('team.tokens.editor.save')}
                                </Button>
                                <Button type="button" onClick={onClose}>
                                    {t('team.tokens.editor.cancel')}
                                </Button>
                            </div>
                            {!token && (
                                <p className="text-xs text-ink-3">
                                    {t('team.tokens.editor.secret_once')}
                                </p>
                            )}
                        </div>
                    </aside>
                </div>
            </DialogContent>
        </Dialog>
    );
}
