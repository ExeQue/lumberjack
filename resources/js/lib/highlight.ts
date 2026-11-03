import { useEffect, useState } from 'react';
import type { HighlighterCore, ThemedToken } from 'shiki/core';

export type CodeLanguage = 'php' | 'sql' | 'javascript' | 'typescript';

const grammars = {
    php: () => import('shiki/langs/php.mjs'),
    sql: () => import('shiki/langs/sql.mjs'),
    javascript: () => import('shiki/langs/javascript.mjs'),
    typescript: () => import('shiki/langs/typescript.mjs'),
};

let highlighter: Promise<HighlighterCore> | null = null;
const loaded = new Map<CodeLanguage, Promise<void>>();

/**
 * Loaded on first use, in chunks of their own, each language only when a page shows
 * it: the queries page never fetches the PHP grammar.
 */
function load(language: CodeLanguage): Promise<HighlighterCore> {
    highlighter ??= Promise.all([
        import('shiki/core'),
        import('shiki/engine/javascript'),
    ]).then(([{ createHighlighterCore }, { createJavaScriptRegexEngine }]) =>
        createHighlighterCore({
            themes: [
                import('shiki/themes/github-light-default.mjs'),
                import('shiki/themes/github-dark-default.mjs'),
            ],
            langs: [],
            engine: createJavaScriptRegexEngine(),
        }),
    );

    return highlighter.then(async (h) => {
        if (!loaded.has(language)) {
            loaded.set(language, h.loadLanguage(grammars[language]()));
        }

        await loaded.get(language);

        return h;
    });
}

export function languageOf(
    file: string | null | undefined,
): CodeLanguage | null {
    // A browser frame's file is a URL; its query or fragment is no part of the name.
    const extension = file
        ?.split(/[?#]/)[0]
        .split('/')
        .pop()
        ?.split('.')
        .pop()
        ?.toLowerCase();

    return (
        (
            {
                php: 'php',
                js: 'javascript',
                mjs: 'javascript',
                cjs: 'javascript',
                jsx: 'javascript',
                ts: 'typescript',
                tsx: 'typescript',
                sql: 'sql',
            } as const
        )[extension ?? ''] ?? null
    );
}

/**
 * The lines as colored tokens, one list per line, or null until the highlighter is
 * loaded (or for a language it does not know): show the plain text meanwhile. Each
 * token's style carries --shiki-light and --shiki-dark; .lj-code picks one.
 */
export function useHighlightedLines(
    lines: string[],
    language: CodeLanguage | null,
): ThemedToken[][] | null {
    const [tokens, setTokens] = useState<ThemedToken[][] | null>(null);
    const code = lines.join('\n');

    useEffect(() => {
        if (language === null || code === '') {
            return;
        }

        let current = true;

        load(language)
            .then((h) => {
                // A snippet from the middle of a PHP file is only PHP after an opening tag.
                const prefix = language === 'php' ? '<?php\n' : '';
                const result = h.codeToTokens(prefix + code, {
                    lang: language,
                    themes: {
                        light: 'github-light-default',
                        dark: 'github-dark-default',
                    },
                    defaultColor: false,
                });

                if (current) {
                    setTokens(prefix ? result.tokens.slice(1) : result.tokens);
                }
            })
            .catch(() => {});

        return () => {
            current = false;
        };
    }, [code, language]);

    return tokens;
}
