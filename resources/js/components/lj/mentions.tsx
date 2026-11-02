import { useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import Markdown from 'react-markdown';
import { inputClass } from '@/components/lj/ui';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type Mentionable = { id: number; name: string; agent?: boolean };

const TAG = /<@(mcp:)?(\d+)>/g;

const escape = (text: string) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

const tokenFor = (c: Mentionable) =>
    c.agent ? `<@mcp:${c.id}>` : `<@${c.id}>`;

/**
 * "@Sofie" as typed becomes "<@12>" as stored, so a tag survives a rename.
 * Longer names first, so "@Ann Marie" is not taken for "@Ann"; people before agents.
 */
export function toStored(text: string, candidates: Mentionable[]): string {
    return [...candidates]
        .sort(
            (a, b) =>
                b.name.length - a.name.length ||
                Number(!!a.agent) - Number(!!b.agent),
        )
        .reduce(
            (out, c) =>
                out.replace(
                    new RegExp(`@${escape(c.name)}(?![\\w-])`, 'g'),
                    tokenFor(c),
                ),
            text,
        );
}

/**
 * A stored note as markdown, with its tags as chips. Markdown becomes React
 * elements, never raw HTML, so a note cannot inject anything into the page.
 */
export function NoteBody({
    body,
    candidates,
}: {
    body: string;
    candidates: Mentionable[];
}) {
    // A tag becomes a link to a made-up anchor, which the link renderer turns into a chip.
    const source = body.replace(
        TAG,
        (raw, mcp: string | undefined, id: string) => {
            const who = candidates.find(
                (c) => c.id === Number(id) && !!c.agent === (mcp !== undefined),
            );

            return who
                ? `[@${who.name}${who.agent ? ' (MCP)' : ''}](#mention)`
                : raw;
        },
    );

    return (
        <div className="note-markdown flex flex-col gap-2 leading-relaxed break-words">
            <Markdown
                components={{
                    a: ({ href, children }) =>
                        href === '#mention' ? (
                            <span className="rounded bg-amber/15 px-1 font-medium text-amber">
                                {children}
                            </span>
                        ) : (
                            <a
                                href={href}
                                target="_blank"
                                rel="noreferrer"
                                className="text-amber underline"
                            >
                                {children}
                            </a>
                        ),
                    ul: ({ children }) => (
                        <ul className="flex list-disc flex-col gap-1 pl-5">
                            {children}
                        </ul>
                    ),
                    ol: ({ children }) => (
                        <ol className="flex list-decimal flex-col gap-1 pl-5">
                            {children}
                        </ol>
                    ),
                    strong: ({ children }) => (
                        <strong className="font-semibold text-ink">
                            {children}
                        </strong>
                    ),
                    code: ({ children, className }) =>
                        className ? (
                            <code className="font-mono text-xs">
                                {children}
                            </code>
                        ) : (
                            <code className="rounded bg-panel-2 px-1 py-px font-mono text-[12px] text-ink">
                                {children}
                            </code>
                        ),
                    pre: ({ children }) => (
                        <pre className="overflow-x-auto rounded-md border border-line bg-ground px-3 py-2 font-mono text-xs">
                            {children}
                        </pre>
                    ),
                    h1: ({ children }) => (
                        <p className="font-semibold text-ink">{children}</p>
                    ),
                    h2: ({ children }) => (
                        <p className="font-semibold text-ink">{children}</p>
                    ),
                    h3: ({ children }) => (
                        <p className="font-semibold text-ink">{children}</p>
                    ),
                    blockquote: ({ children }) => (
                        <blockquote className="border-l-2 border-line pl-3 text-ink-2">
                            {children}
                        </blockquote>
                    ),
                }}
            >
                {source}
            </Markdown>
        </div>
    );
}

/**
 * A textarea that offers team members and agents when "@" is typed.
 */
export function MentionTextarea({
    id,
    value,
    onChange,
    candidates,
    placeholder,
    invalid,
    required = true,
}: {
    id: string;
    value: string;
    onChange: (value: string) => void;
    candidates: Mentionable[];
    placeholder?: string;
    invalid?: boolean;
    required?: boolean;
}) {
    const t = useT();
    const ref = useRef<HTMLTextAreaElement>(null);
    const [query, setQuery] = useState<string | null>(null);
    const [active, setActive] = useState(0);

    const matches =
        query === null
            ? []
            : candidates
                  .filter((c) =>
                      c.name.toLowerCase().includes(query.toLowerCase()),
                  )
                  .slice(0, 8);

    const update = (text: string, caret: number) => {
        onChange(text);
        const before = text.slice(0, caret).match(/(?:^|\s)@([^\s@]*)$/);
        setQuery(before ? before[1] : null);
        setActive(0);
    };

    const pick = (c: Mentionable) => {
        const el = ref.current;
        const caret = el?.selectionStart ?? value.length;
        const start = value.slice(0, caret).lastIndexOf('@');
        const text = `${value.slice(0, start)}@${c.name} ${value.slice(caret)}`;
        const at = start + c.name.length + 2;

        onChange(text);
        setQuery(null);
        requestAnimationFrame(() => {
            el?.focus();
            el?.setSelectionRange(at, at);
        });
    };

    const onKeyDown = (e: KeyboardEvent<HTMLTextAreaElement>) => {
        // Ctrl+Enter, or Cmd+Enter on a Mac, saves the note.
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
            e.preventDefault();
            setQuery(null);
            e.currentTarget.form?.requestSubmit();

            return;
        }

        if (matches.length === 0) {
            return;
        }

        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            setActive(
                (i) =>
                    (i + (e.key === 'ArrowDown' ? 1 : matches.length - 1)) %
                    matches.length,
            );
        } else if (e.key === 'Enter' || e.key === 'Tab') {
            e.preventDefault();
            pick(matches[active]);
        } else if (e.key === 'Escape') {
            setQuery(null);
        }
    };

    return (
        <div className="relative w-full">
            <textarea
                ref={ref}
                id={id}
                value={value}
                onChange={(e) =>
                    update(e.target.value, e.target.selectionStart)
                }
                onKeyDown={onKeyDown}
                onBlur={() => window.setTimeout(() => setQuery(null), 150)}
                placeholder={placeholder}
                required={required}
                aria-invalid={invalid ? true : undefined}
                aria-autocomplete="list"
                aria-controls={
                    matches.length > 0 ? `${id}-mentions` : undefined
                }
                className={cn(
                    inputClass,
                    'h-auto min-h-[96px] w-full resize-y py-2',
                )}
            />
            {matches.length > 0 && (
                <ul
                    id={`${id}-mentions`}
                    role="listbox"
                    aria-label={t('issue.notes.mention')}
                    className="absolute top-full left-0 z-20 mt-1 w-64 overflow-hidden rounded-md border border-line bg-panel py-1 shadow-lg"
                >
                    {matches.map((c, i) => (
                        <li
                            key={`${c.agent ? 'mcp' : 'user'}-${c.id}`}
                            role="option"
                            aria-selected={i === active}
                            onMouseDown={(e) => {
                                e.preventDefault();
                                pick(c);
                            }}
                            className={cn(
                                'flex cursor-pointer items-center justify-between px-3 py-1.5 text-[13px]',
                                i === active && 'bg-panel-2',
                            )}
                        >
                            <span>{c.name}</span>
                            {c.agent && (
                                <span className="text-[11px] text-ink-3">
                                    MCP
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
