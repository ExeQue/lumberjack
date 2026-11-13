import { useCallback, useEffect, useRef, useState } from 'react';
import { TreeSelect } from '@/components/lj/tree-select';
import type { TreeOption } from '@/components/lj/tree-select';
import { useT } from '@/lib/i18n';
import { references as referencesRoute } from '@/routes/activity';

export type Reference = { type: string; id: string };

/** Actors and subjects picked, each as type:id; any of them matches. */
export type ReferenceFilters = {
    actors: string[];
    subjects: string[];
};

type Kind = 'actor' | 'subject';

/**
 * Filters as a page's query: empty values and the default sort left out, picked actors
 * and subjects as lists.
 */
export function filterQuery(
    filters: Record<string, string | string[] | null>,
): Record<string, string | string[]> {
    return Object.fromEntries(
        Object.entries(filters).filter(
            ([key, v]) =>
                v !== null &&
                v !== '' &&
                !(Array.isArray(v) && v.length === 0) &&
                !(key === 'sort' && v === 'time'),
        ),
    ) as Record<string, string | string[]>;
}

/** Milliseconds after the last key before the server is asked. */
const SEARCH_DELAY_MS = 200;

/** "User #17": a type's last segment (App\Models\User is User) and its key. */
export function referenceLabel(reference: Reference): string {
    const type = reference.type.split('\\').pop() ?? reference.type;

    return `${type} #${reference.id}`;
}

/** "user:17" split at the first colon. */
export function parseReference(value: string): Reference | null {
    const at = value.indexOf(':');

    return at > 0
        ? { type: value.slice(0, at), id: value.slice(at + 1) }
        : null;
}

/** "user:17" as an option filed under its type. */
function option(value: string): TreeOption {
    const reference = parseReference(value);

    return reference
        ? { value, label: `#${reference.id}`, path: [reference.type] }
        : { value, label: value };
}

/**
 * The actors or subjects the server finds for what is typed, at most 20, asked a moment
 * after the last key; an older answer never overwrites a newer one.
 */
function useReferenceSearch(
    project: string,
    kind: Kind,
    scope: Record<string, string>,
): [TreeOption[], (query: string) => void] {
    const [options, setOptions] = useState<TreeOption[]>([]);
    const [query, setQuery] = useState<string | null>(null);
    const scopeKey = JSON.stringify(scope);
    const latest = useRef(0);

    useEffect(() => {
        if (query === null) {
            return;
        }

        const controller = new AbortController();
        const ticket = ++latest.current;
        const timer = window.setTimeout(() => {
            fetch(
                referencesRoute(project, {
                    query: { ...JSON.parse(scopeKey), kind, q: query },
                }).url,
                {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                },
            )
                .then((response) => (response.ok ? response.json() : []))
                .then((list: string[]) => {
                    if (ticket === latest.current) {
                        // Naturally, so #2 comes before #10.
                        setOptions(
                            list
                                .toSorted((a, b) =>
                                    a.localeCompare(b, undefined, {
                                        numeric: true,
                                    }),
                                )
                                .map(option),
                        );
                    }
                })
                .catch(() => undefined);
        }, SEARCH_DELAY_MS);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [project, kind, query, scopeKey]);

    return [options, useCallback((q: string) => setQuery(q), [])];
}

/**
 * The actor and subject filters of the Activity pages: a searchable select for each,
 * searched on the server and grouped by type.
 */
export function ActivityFilters({
    project,
    filters,
    onChange,
    event,
}: {
    project: string;
    filters: ReferenceFilters & { period: string; environment: string | null };
    onChange: (changes: Partial<ReferenceFilters>) => void;
    /** Search only this activity's occurrences. */
    event?: { group: string; name: string };
}) {
    const t = useT();
    const scope = {
        period: filters.period,
        ...(filters.environment ? { environment: filters.environment } : {}),
        ...event,
    };
    const [actors, searchActors] = useReferenceSearch(project, 'actor', scope);
    const [subjects, searchSubjects] = useReferenceSearch(
        project,
        'subject',
        scope,
    );
    const listOf = (kind: Kind) =>
        kind === 'actor' ? filters.actors : filters.subjects;
    // A value toggles in or out; null clears them all.
    const toggle = (kind: Kind, value: string | null) => {
        const list = listOf(kind);
        const next =
            value === null
                ? []
                : list.includes(value)
                  ? list.filter((v) => v !== value)
                  : [...list, value];

        onChange(kind === 'actor' ? { actors: next } : { subjects: next });
    };

    return (
        <div
            role="group"
            aria-label={t('activity.filters.label')}
            className="flex flex-wrap items-center gap-2"
        >
            {(['actor', 'subject'] as const).map((kind) => (
                <div key={kind} className="w-56">
                    <TreeSelect
                        id={`activity-${kind}`}
                        label={t(`activity.filters.${kind}`)}
                        options={kind === 'actor' ? actors : subjects}
                        value={listOf(kind)}
                        onChange={(value) => toggle(kind, value)}
                        multiple
                        optionFor={option}
                        pickedLabel={(count) =>
                            t(
                                `activity.filters.${kind}_count`,
                                { count },
                                count,
                            )
                        }
                        onSearch={
                            kind === 'actor' ? searchActors : searchSubjects
                        }
                        placeholder={t(`activity.filters.${kind}_placeholder`)}
                        searchPlaceholder={t('activity.filters.search')}
                        noMatches={t('activity.filters.no_matches')}
                        clearLabel={t(`activity.filters.${kind}_any`)}
                        startOpen
                    />
                </div>
            ))}
        </div>
    );
}
