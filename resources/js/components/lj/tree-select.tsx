import { Command } from 'cmdk';
import { Check, ChevronDown, ChevronRight } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { CSSProperties } from 'react';
import { createPortal } from 'react-dom';
import { inputClass } from '@/components/lj/ui';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type TreeOption = {
    value: string;
    label: string;
    /** The levels it is filed under, outermost first; none for the top. */
    path?: string[];
    /** More to find it by, not shown. */
    keywords?: string;
};

type Row =
    | { kind: 'group'; key: string; name: string; depth: number; count: number }
    | { kind: 'source'; source: TreeOption; depth: number };

type Node = { children: Map<string, Node>; sources: TreeOption[] };

const KEY = '\0';
const MAX_HEIGHT = 380;
const MIN_ROOM = 240;
const MIN_WIDTH = 320;
const GAP = 4;
/** So few that folding them only hides them. */
const ALL_OPEN_UP_TO = 12;

function countOf(node: Node): number {
    return [...node.children.values()].reduce(
        (total, child) => total + countOf(child),
        node.sources.length,
    );
}

function treeOf(sources: TreeOption[]): Node {
    const root: Node = { children: new Map(), sources: [] };

    for (const source of sources) {
        let node = root;

        for (const name of source.path ?? []) {
            const next = node.children.get(name) ?? {
                children: new Map(),
                sources: [],
            };
            node.children.set(name, next);
            node = next;
        }

        node.sources.push(source);
    }

    return root;
}

/** What shows of the tree, in order: a level's own sources before its subgroups. */
function visibleRows(
    node: Node,
    open: Set<string>,
    depth = 0,
    parent: string[] = [],
): Row[] {
    return [
        ...node.sources.map((source): Row => ({
            kind: 'source',
            source,
            depth,
        })),
        ...[...node.children.entries()]
            .sort(([a], [b]) =>
                a.localeCompare(b, undefined, { numeric: true }),
            )
            .flatMap(([name, child]): Row[] => {
                const key = [...parent, name].join(KEY);

                return [
                    { kind: 'group', key, name, depth, count: countOf(child) },
                    ...(open.has(key)
                        ? visibleRows(child, open, depth + 1, [...parent, name])
                        : []),
                ];
            }),
    ];
}

const rowClass =
    'flex cursor-pointer items-center gap-1.5 rounded px-2 py-1 text-[13px] data-[selected=true]:bg-line/60 pointer-coarse:min-h-10';
const indent = (depth: number) => ({ paddingLeft: `${0.5 + depth}rem` });

/** Every level of the tree, by key. */
function allKeys(node: Node, parent: string[] = []): string[] {
    return [...node.children.entries()].flatMap(([name, child]) => [
        [...parent, name].join(KEY),
        ...allKeys(child, [...parent, name]),
    ]);
}

/**
 * A select for long or nested lists: a tree as deep as its options, and a search over
 * them. It floats over the page, so a narrow panel never clips it. Short fixed lists
 * stay native selects.
 */
export function TreeSelect({
    id,
    name,
    label,
    options,
    value,
    onChange,
    placeholder,
    searchPlaceholder,
    noMatches,
    clearLabel,
    startOpen = false,
    onSearch,
    multiple = false,
    pickedLabel,
}: {
    id: string;
    /** Submits the value with a surrounding form. */
    name?: string;
    label: string;
    options: TreeOption[];
    /** The picked value, or the picked values when multiple. */
    value: string | string[];
    /**
     * The value picked, or null to clear. When multiple, the value toggled, and the list
     * stays open.
     */
    onChange: (value: string | null) => void;
    multiple?: boolean;
    /** How a multiple choice shows when closed, given how many are picked. */
    pickedLabel?: (count: number) => string;
    placeholder: string;
    searchPlaceholder: string;
    noMatches: string;
    /** The row that empties the choice; without it one must stay picked. */
    clearLabel?: string;
    startOpen?: boolean;
    /**
     * Searches somewhere else (the server) while open: called with what is typed, and the
     * options given back are shown as they are, unfiltered.
     */
    onSearch?: (query: string) => void;
}) {
    const t = useT();
    const trigger = useRef<HTMLButtonElement>(null);
    const panel = useRef<HTMLDivElement>(null);
    const [position, setPosition] = useState<CSSProperties>();
    const [container, setContainer] = useState<HTMLElement>();
    const title = (source: TreeOption) => source.label;
    const picked = Array.isArray(value) ? value : value ? [value] : [];
    const isPicked = (candidate: string) => picked.includes(candidate);
    const first = picked[0] ?? '';
    // A server search may not have the picked value among what it answered.
    const current =
        options.find((s) => s.value === first) ??
        (onSearch && first ? { value: first, label: first } : undefined);
    const currentPath = current?.path ?? [];
    const expanded = position !== undefined;
    const [query, setQuery] = useState('');
    const tree = useMemo(() => treeOf(options), [options]);
    // A short list starts unfolded; a long one only down to what is picked.
    const [open, setOpen] = useState(
        () =>
            new Set(
                startOpen || options.length <= ALL_OPEN_UP_TO
                    ? allKeys(tree)
                    : currentPath.map((_, i) =>
                          currentPath.slice(0, i + 1).join(KEY),
                      ),
            ),
    );
    const words = query.toLowerCase().split(/\s+/).filter(Boolean);
    const matches = onSearch
        ? options
        : options.filter((source) => {
              const text =
                  `${source.label} ${source.value} ${(source.path ?? []).join(' ')} ${source.keywords ?? ''}`.toLowerCase();

              return words.every((word) => text.includes(word));
          });

    // Asked only while open, so a closed field costs nothing.
    useEffect(() => {
        if (expanded) {
            onSearch?.(query);
        }
    }, [expanded, query, onSearch]);

    const dismiss = () => {
        setPosition(undefined);
        setQuery('');
    };
    const close = () => {
        dismiss();
        trigger.current?.focus();
    };
    // Below the button, or above it when that is where the room is.
    const expand = () => {
        const button = trigger.current!;
        const box = button.getBoundingClientRect();
        // Inside a dialog it has to stay in it, which also becomes what it is placed against.
        const dialog = button.closest<HTMLElement>('[role="dialog"]');
        const frame = dialog?.getBoundingClientRect() ?? {
            left: 0,
            top: 0,
            height: window.innerHeight,
        };
        const below = window.innerHeight - box.bottom;
        const above = below < MIN_ROOM && box.top > below;
        const width = Math.min(
            Math.max(box.width, MIN_WIDTH),
            window.innerWidth - GAP * 2,
        );
        // What is picked is unfolded to find, whatever was folded since.
        setOpen(
            (was) =>
                new Set([
                    ...was,
                    ...currentPath.map((_, i) =>
                        currentPath.slice(0, i + 1).join(KEY),
                    ),
                    ...(startOpen ? allKeys(tree) : []),
                ]),
        );
        setContainer(dialog ?? document.body);
        setPosition({
            left:
                Math.max(
                    GAP,
                    Math.min(box.left, window.innerWidth - width - GAP),
                ) - frame.left,
            width,
            maxHeight: Math.min(
                MAX_HEIGHT,
                (above ? box.top : below) - GAP * 2,
            ),
            ...(above
                ? { bottom: frame.height - (box.top - frame.top) + GAP }
                : { top: box.bottom - frame.top + GAP }),
        });
    };

    // A click elsewhere, or the page moving away from under it, closes it.
    useEffect(() => {
        if (!expanded) {
            return;
        }

        const inside = (target: EventTarget | null) =>
            target instanceof globalThis.Node &&
            (panel.current?.contains(target) ||
                trigger.current?.contains(target));
        const outside = (event: Event) => {
            if (!inside(event.target)) {
                dismiss();
            }
        };
        // Only what carries the button away closes it; a widget scrolling itself does not.
        const scrolled = (event: Event) => {
            if (
                event.target instanceof globalThis.Node &&
                event.target.contains(trigger.current)
            ) {
                dismiss();
            }
        };
        // At the window, ahead of a surrounding dialog that would close on Escape too.
        const keydown = (event: KeyboardEvent) => {
            if (event.key === 'Escape' || event.key === 'Tab') {
                event.preventDefault();
                event.stopPropagation();
                dismiss();
                trigger.current?.focus();
            }
        };
        document.addEventListener('pointerdown', outside);
        window.addEventListener('scroll', scrolled, true);
        window.addEventListener('keydown', keydown, true);
        window.addEventListener('resize', dismiss);

        return () => {
            document.removeEventListener('pointerdown', outside);
            window.removeEventListener('scroll', scrolled, true);
            window.removeEventListener('keydown', keydown, true);
            window.removeEventListener('resize', dismiss);
        };
    }, [expanded]);

    const pick = (uuid: string | null) => {
        onChange(uuid);

        if (!multiple || uuid === null) {
            close();
        }
    };
    const toggle = (key: string) =>
        setOpen((was) => {
            const next = new Set(was);

            if (!next.delete(key)) {
                next.add(key);
            }

            return next;
        });
    const sourceItem = (source: TreeOption, depth: number, path?: string) => (
        <Command.Item
            key={source.value}
            value={source.value}
            aria-current={isPicked(source.value) ? 'true' : undefined}
            onSelect={() => pick(source.value)}
            className={rowClass}
            style={indent(depth)}
        >
            {isPicked(source.value) ? (
                <>
                    <Check aria-hidden="true" className="size-3.5 shrink-0" />
                    <span className="sr-only">
                        {t('screens.picker.selected')}
                    </span>
                </>
            ) : (
                <span aria-hidden="true" className="size-3.5 shrink-0" />
            )}
            <span className="min-w-0">
                <span className="block truncate">{title(source)}</span>
                {path && (
                    <span className="block truncate text-xs text-ink-3">
                        {path}
                    </span>
                )}
            </span>
        </Command.Item>
    );

    return (
        <>
            {name &&
                picked.map((v) => (
                    <input key={v} type="hidden" name={name} value={v} />
                ))}
            <button
                ref={trigger}
                id={id}
                type="button"
                disabled={!onSearch && options.length === 0 && !value}
                role="combobox"
                aria-haspopup="dialog"
                aria-expanded={expanded}
                onClick={() => (expanded ? close() : expand())}
                className={cn(
                    inputClass,
                    'flex w-full items-center gap-2 text-left disabled:opacity-50',
                )}
            >
                <span
                    className={cn(
                        'min-w-0 shrink-0 truncate',
                        current ? 'max-w-[70%]' : 'text-ink-3',
                    )}
                >
                    {multiple && picked.length > 1 && pickedLabel
                        ? pickedLabel(picked.length)
                        : current
                          ? title(current)
                          : placeholder}
                </span>
                {currentPath.length > 0 && picked.length === 1 && (
                    <span className="min-w-0 truncate text-xs text-ink-3">
                        {currentPath.join(' > ')}
                    </span>
                )}
                <ChevronDown
                    aria-hidden="true"
                    className="ml-auto size-3.5 shrink-0"
                />
            </button>
            {expanded &&
                createPortal(
                    <Command
                        ref={panel}
                        shouldFilter={false}
                        defaultValue={current ? first : undefined}
                        label={label}
                        className="fixed z-50 flex flex-col rounded-md border border-line-strong bg-panel shadow-lg"
                        style={position}
                    >
                        <Command.Input
                            autoFocus
                            value={query}
                            onValueChange={setQuery}
                            placeholder={searchPlaceholder}
                            className="w-full border-b border-line bg-transparent px-3 py-2 text-[13px] text-ink outline-none placeholder:text-ink-3"
                        />
                        <Command.List className="min-h-0 flex-1 overflow-y-auto p-1">
                            {words.length > 0 ? (
                                <>
                                    {matches.length === 0 && (
                                        <p className="px-2 py-3 text-center text-xs text-ink-3">
                                            {noMatches}
                                        </p>
                                    )}
                                    {matches.map((source) =>
                                        sourceItem(
                                            source,
                                            0,
                                            (source.path ?? []).join(' > '),
                                        ),
                                    )}
                                </>
                            ) : (
                                <>
                                    {picked.length > 0 && clearLabel && (
                                        <Command.Item
                                            value="none"
                                            onSelect={() => pick(null)}
                                            className={cn(
                                                rowClass,
                                                'mb-1 border-b border-line text-ink-3',
                                            )}
                                        >
                                            <span
                                                aria-hidden="true"
                                                className="size-3.5 shrink-0"
                                            />
                                            {clearLabel}
                                        </Command.Item>
                                    )}
                                    {visibleRows(tree, open).map((row) =>
                                        row.kind === 'source' ? (
                                            sourceItem(row.source, row.depth)
                                        ) : (
                                            <Command.Item
                                                key={row.key}
                                                value={`group:${row.key}`}
                                                aria-expanded={open.has(
                                                    row.key,
                                                )}
                                                onSelect={() => toggle(row.key)}
                                                className={cn(
                                                    rowClass,
                                                    'font-medium text-ink-2',
                                                )}
                                                style={indent(row.depth)}
                                            >
                                                <ChevronRight
                                                    aria-hidden="true"
                                                    className={cn(
                                                        'size-3.5 shrink-0 transition-transform',
                                                        open.has(row.key) &&
                                                            'rotate-90',
                                                    )}
                                                />
                                                <span className="min-w-0 truncate">
                                                    {row.name}
                                                </span>
                                                <span className="ml-auto text-xs font-normal text-ink-3">
                                                    {row.count}
                                                </span>
                                            </Command.Item>
                                        ),
                                    )}
                                </>
                            )}
                        </Command.List>
                    </Command>,
                    container ?? document.body,
                )}
        </>
    );
}
