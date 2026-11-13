import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { Button, Input } from '@/components/lj/ui';
import { useT } from '@/lib/i18n';
import { references as referencesRoute } from '@/routes/activity';

export type Reference = { type: string; id: string };

export type ReferenceFilters = {
    actor_type: string | null;
    actor_id: string | null;
    subject_type: string | null;
    subject_id: string | null;
};

/** "user #17": a type's last segment (App\Models\User is User) and its key. */
export function referenceLabel(reference: Reference): string {
    const type = reference.type.split('\\').pop() ?? reference.type;

    return `${type} #${reference.id}`;
}

/** "user:17" as typed, split at the first colon; null without both parts. */
function parseReference(value: string): Reference | null {
    const at = value.indexOf(':');

    if (at < 1 || at === value.length - 1) {
        return null;
    }

    return { type: value.slice(0, at).trim(), id: value.slice(at + 1).trim() };
}

function picked(type: string | null, id: string | null): Reference | null {
    return type && id ? { type, id } : null;
}

/**
 * The actor and subject filters of the Activity pages: a field for each, as type:id, and
 * the picked ones as buttons that take them off.
 */
/** Milliseconds after the last key before the suggestions are asked for. */
const SUGGEST_DELAY_MS = 200;

/**
 * At most 20 actors or subjects matching what is typed, from the server, so the list
 * stays short however many there are.
 */
function useSuggestions(
    project: string,
    kind: 'actor' | 'subject',
    term: string,
    query: Record<string, string>,
): string[] {
    const [suggestions, setSuggestions] = useState<string[]>([]);
    const key = JSON.stringify(query);

    useEffect(() => {
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            fetch(
                referencesRoute(project, {
                    query: { ...JSON.parse(key), kind, q: term },
                }).url,
                {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                },
            )
                .then((response) => (response.ok ? response.json() : []))
                .then((list: string[]) => setSuggestions(list))
                .catch(() => undefined);
        }, SUGGEST_DELAY_MS);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [project, kind, term, key]);

    return suggestions;
}

export function ActivityFilters({
    project,
    filters,
    onChange,
    event,
}: {
    project: string;
    filters: ReferenceFilters & { period: string; environment: string | null };
    onChange: (changes: Partial<ReferenceFilters>) => void;
    /** Suggest only from this activity's occurrences. */
    event?: { group: string; name: string };
}) {
    const t = useT();
    const actor = picked(filters.actor_type, filters.actor_id);
    const subject = picked(filters.subject_type, filters.subject_id);
    const [actorText, setActorText] = useState('');
    const [subjectText, setSubjectText] = useState('');
    const scope = {
        period: filters.period,
        ...(filters.environment ? { environment: filters.environment } : {}),
        ...event,
    };
    const actorSuggestions = useSuggestions(project, 'actor', actorText, scope);
    const subjectSuggestions = useSuggestions(
        project,
        'subject',
        subjectText,
        scope,
    );

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const changes: Partial<ReferenceFilters> = {};
        const nextActor = parseReference(actorText);
        const nextSubject = parseReference(subjectText);

        if (nextActor) {
            changes.actor_type = nextActor.type;
            changes.actor_id = nextActor.id;
        }

        if (nextSubject) {
            changes.subject_type = nextSubject.type;
            changes.subject_id = nextSubject.id;
        }

        setActorText('');
        setSubjectText('');
        onChange(changes);
    };

    return (
        <form
            onSubmit={submit}
            className="flex flex-wrap items-center gap-2"
            aria-label={t('activity.filters.label')}
        >
            <label className="sr-only" htmlFor="activity-actor">
                {t('activity.filters.actor')}
            </label>
            <Input
                id="activity-actor"
                value={actorText}
                onChange={(e) => setActorText(e.target.value)}
                placeholder={t('activity.filters.actor_placeholder')}
                className="w-40 font-mono"
                list="activity-actor-suggestions"
                autoComplete="off"
            />
            <datalist id="activity-actor-suggestions">
                {actorSuggestions.map((reference) => (
                    <option key={reference} value={reference} />
                ))}
            </datalist>
            <label className="sr-only" htmlFor="activity-subject">
                {t('activity.filters.subject')}
            </label>
            <Input
                id="activity-subject"
                value={subjectText}
                onChange={(e) => setSubjectText(e.target.value)}
                placeholder={t('activity.filters.subject_placeholder')}
                className="w-40 font-mono"
                list="activity-subject-suggestions"
                autoComplete="off"
            />
            <datalist id="activity-subject-suggestions">
                {subjectSuggestions.map((reference) => (
                    <option key={reference} value={reference} />
                ))}
            </datalist>
            <Button type="submit">{t('activity.filters.apply')}</Button>
            {actor && (
                <Button
                    onClick={() =>
                        onChange({ actor_type: null, actor_id: null })
                    }
                >
                    {t('activity.clear_actor', {
                        reference: referenceLabel(actor),
                    })}
                </Button>
            )}
            {subject && (
                <Button
                    onClick={() =>
                        onChange({ subject_type: null, subject_id: null })
                    }
                >
                    {t('activity.clear_subject', {
                        reference: referenceLabel(subject),
                    })}
                </Button>
            )}
        </form>
    );
}
