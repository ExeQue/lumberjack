import { Fragment } from 'react';
import { Relative } from '@/components/lj/relative';
import {
    Button,
    Input,
    numClass,
    tableClass,
    tdClass,
    thClass,
} from '@/components/lj/ui';
import { formatDateTime, formatNumber } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type {
    ResolvedRetention,
    RetentionRow,
    RetentionStats,
} from '@/types/lumberjack';

/** Days per data type as typed ('' inherits), and the types kept forever. */
export type RetentionForm = { days: Record<string, string>; forever: string[] };

const DAY_MS = 86_400_000;

export function retentionFormData(rows: RetentionRow[]): RetentionForm {
    return {
        days: Object.fromEntries(
            rows.map((r) => [r.category, r.stored?.days?.toString() ?? '']),
        ),
        forever: rows
            .filter((r) => r.stored !== null && r.stored.days === null)
            .map((r) => r.category),
    };
}

export function retentionPayload(data: RetentionForm): {
    days: Record<string, number | null>;
    forever: string[];
} {
    return {
        days: Object.fromEntries(
            Object.entries(data.days).map(([key, value]) => [
                key,
                value.trim() === '' || data.forever.includes(key)
                    ? null
                    : Number(value),
            ]),
        ),
        forever: data.forever,
    };
}

/** What would apply with the form as it is: the typed value under the cap, or the inherited one. */
function pending(row: RetentionRow, data: RetentionForm): number | null {
    const forever = data.forever.includes(row.category);
    const typed = data.days[row.category]?.trim() ?? '';

    if (!forever && typed === '') {
        return row.inherited.days;
    }

    const cap = row.effective.cap_days;
    const days = forever ? null : Number(typed);

    if (cap === null) {
        return days;
    }

    return days === null ? cap : Math.min(days, cap);
}

type Level = 'application' | 'team' | 'project';

/**
 * One grouped table of every data type at a level: what it inherits, what it stores, the cap
 * from above, how much is stored and up to when the next prune deletes.
 */
export function RetentionTable({
    level,
    rows,
    stats,
    data,
    setData,
    errors,
    readOnly = false,
}: {
    level: Level;
    rows: RetentionRow[];
    stats: RetentionStats | undefined;
    data: RetentionForm;
    setData: (data: RetentionForm) => void;
    errors: Record<string, string>;
    readOnly?: boolean;
}) {
    const t = useT();
    const groups = [...new Set(rows.map((r) => r.group))];
    const days = (value: number | null): string =>
        value === null
            ? t('settings.retention.forever')
            : t('settings.days', { count: value }, value);

    const inheritedText = (inherited: ResolvedRetention): string => {
        const value = days(inherited.days);

        if (level === 'project') {
            return t('settings.retention.team_default', { value });
        }

        return level === 'team' && inherited.source !== 'default'
            ? t('settings.retention.application_default', { value })
            : t('settings.retention.built_in_default', { value });
    };

    return (
        <div className="overflow-x-auto">
            <table className={tableClass}>
                <thead>
                    <tr>
                        <th className={thClass}>
                            {t('settings.retention.data_type')}
                        </th>
                        <th className={thClass}>
                            {t('settings.retention.inherited')}
                        </th>
                        <th className={thClass}>
                            {t('settings.retention.value')}
                        </th>
                        <th className={cn(thClass, 'text-right')}>
                            {t('settings.retention.rows')}
                        </th>
                        <th className={thClass}>
                            {t('settings.retention.oldest')}
                        </th>
                        <th className={thClass}>
                            {t('settings.retention.deletes_before')}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {groups.map((group) => (
                        <Fragment key={group}>
                            <tr>
                                <th
                                    colSpan={6}
                                    scope="colgroup"
                                    className="border-b border-line bg-panel-2 px-3 py-1.5 text-left text-[11px] font-medium tracking-wide text-ink-3 uppercase"
                                >
                                    {t(`settings.retention.groups.${group}`)}
                                </th>
                            </tr>
                            {rows
                                .filter((r) => r.group === group)
                                .map((row) => {
                                    const key = row.category;
                                    const forever = data.forever.includes(key);
                                    const typed = data.days[key] ?? '';
                                    const set = forever || typed !== '';
                                    const error = errors[`days.${key}`];
                                    const stat = stats?.[key];
                                    const applies = pending(row, data);
                                    const cap = row.effective.cap_days;
                                    const capLevel = t(
                                        `settings.retention.levels.${row.effective.cap_level ?? 'default'}`,
                                    );

                                    return (
                                        <tr key={key}>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    'max-w-80',
                                                )}
                                            >
                                                <div className="font-medium">
                                                    {row.label}
                                                </div>
                                                <div className="text-xs text-ink-3">
                                                    {row.description}
                                                </div>
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    'text-xs whitespace-nowrap text-ink-2',
                                                )}
                                            >
                                                <div>
                                                    {inheritedText(
                                                        row.inherited,
                                                    )}
                                                </div>
                                                {cap !== null && (
                                                    <div className="text-ink-3">
                                                        {t(
                                                            'settings.retention.cap',
                                                            {
                                                                value: days(
                                                                    cap,
                                                                ),
                                                                level: capLevel,
                                                            },
                                                        )}
                                                    </div>
                                                )}
                                            </td>
                                            <td className={tdClass}>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <label
                                                        className="sr-only"
                                                        htmlFor={`retention-${level}-${key}`}
                                                    >
                                                        {t(
                                                            'settings.retention.days_for',
                                                            {
                                                                label: row.label,
                                                            },
                                                        )}
                                                    </label>
                                                    <Input
                                                        id={`retention-${level}-${key}`}
                                                        type="number"
                                                        min={1}
                                                        max={cap ?? 3650}
                                                        inputMode="numeric"
                                                        className="w-24 font-mono"
                                                        placeholder={t(
                                                            'settings.retention.inherit',
                                                        )}
                                                        readOnly={readOnly}
                                                        disabled={forever}
                                                        value={
                                                            forever ? '' : typed
                                                        }
                                                        onChange={(e) =>
                                                            setData({
                                                                ...data,
                                                                days: {
                                                                    ...data.days,
                                                                    [key]: e
                                                                        .target
                                                                        .value,
                                                                },
                                                            })
                                                        }
                                                        aria-invalid={
                                                            error !== undefined
                                                        }
                                                    />
                                                    {cap === null && (
                                                        <label className="flex items-center gap-1.5 text-xs text-ink-2">
                                                            <input
                                                                type="checkbox"
                                                                checked={
                                                                    forever
                                                                }
                                                                disabled={
                                                                    readOnly
                                                                }
                                                                aria-label={t(
                                                                    'settings.retention.forever_for',
                                                                    {
                                                                        label: row.label,
                                                                    },
                                                                )}
                                                                onChange={(e) =>
                                                                    setData({
                                                                        ...data,
                                                                        forever:
                                                                            e
                                                                                .target
                                                                                .checked
                                                                                ? [
                                                                                      ...data.forever,
                                                                                      key,
                                                                                  ]
                                                                                : data.forever.filter(
                                                                                      (
                                                                                          f,
                                                                                      ) =>
                                                                                          f !==
                                                                                          key,
                                                                                  ),
                                                                    })
                                                                }
                                                            />
                                                            {t(
                                                                'settings.retention.forever',
                                                            )}
                                                        </label>
                                                    )}
                                                    {set && !readOnly && (
                                                        <Button
                                                            variant="ghost"
                                                            onClick={() =>
                                                                setData({
                                                                    days: {
                                                                        ...data.days,
                                                                        [key]: '',
                                                                    },
                                                                    forever:
                                                                        data.forever.filter(
                                                                            (
                                                                                f,
                                                                            ) =>
                                                                                f !==
                                                                                key,
                                                                        ),
                                                                })
                                                            }
                                                        >
                                                            {t(
                                                                'settings.retention.reset',
                                                            )}
                                                            <span className="sr-only">
                                                                {t(
                                                                    'settings.retention.reset_for',
                                                                    {
                                                                        label: row.label,
                                                                    },
                                                                )}
                                                            </span>
                                                        </Button>
                                                    )}
                                                </div>
                                                {error && (
                                                    <p className="mt-1 text-xs text-err">
                                                        {error}
                                                    </p>
                                                )}
                                                {row.effective.capped &&
                                                    row.stored !== null && (
                                                        <p className="mt-1 text-xs text-warn">
                                                            {t(
                                                                'settings.retention.capped',
                                                                {
                                                                    value: days(
                                                                        row
                                                                            .effective
                                                                            .days,
                                                                    ),
                                                                    level: capLevel,
                                                                    stored: days(
                                                                        row
                                                                            .stored
                                                                            .days,
                                                                    ),
                                                                },
                                                            )}
                                                        </p>
                                                    )}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    numClass,
                                                    'text-xs',
                                                )}
                                            >
                                                {stat === undefined ? (
                                                    <span className="text-ink-3">
                                                        {t(
                                                            'settings.retention.measuring',
                                                        )}
                                                    </span>
                                                ) : (
                                                    <>
                                                        {stat.rows_more
                                                            ? t(
                                                                  'settings.retention.more',
                                                                  {
                                                                      count: formatNumber(
                                                                          stat.rows,
                                                                      ),
                                                                  },
                                                              )
                                                            : formatNumber(
                                                                  stat.rows,
                                                              )}
                                                        {stat.rows_estimated && (
                                                            <div className="text-ink-3">
                                                                {t(
                                                                    'settings.retention.estimated',
                                                                )}
                                                            </div>
                                                        )}
                                                    </>
                                                )}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    'text-xs whitespace-nowrap text-ink-2',
                                                )}
                                            >
                                                {stat?.oldest_at ? (
                                                    <Relative
                                                        iso={stat.oldest_at}
                                                    />
                                                ) : (
                                                    '-'
                                                )}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    'text-xs whitespace-nowrap text-ink-2',
                                                )}
                                            >
                                                {applies === null
                                                    ? t(
                                                          'settings.retention.never',
                                                      )
                                                    : formatDateTime(
                                                          new Date(
                                                              Date.now() -
                                                                  applies *
                                                                      DAY_MS,
                                                          ).toISOString(),
                                                      )}
                                            </td>
                                        </tr>
                                    );
                                })}
                        </Fragment>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
