type Tree = { [key: string]: string | Tree };

/**
 * The UI strings, loaded once before the first page renders from the versioned file the
 * layout preloads (see Translations::url): kept here, not in every page's props.
 */
let strings: Tree = {};
let loadedFrom: string | null = null;

/** Fetches the strings at this address unless they are the ones loaded; true when new. */
export async function loadTranslations(url: string): Promise<boolean> {
    if (url === loadedFrom) {
        return false;
    }

    const response = await fetch(url, {
        headers: { Accept: 'application/json' },
    });

    if (!response.ok) {
        return false;
    }

    strings = (await response.json()) as Tree;
    loadedFrom = url;

    return true;
}

/** A line for code outside a component (a page's layout callback). */
export function t(
    key: string,
    replace: Record<string, string | number> = {},
    count?: number,
): string {
    return translate(strings, key, replace, count);
}

function lookup(tree: Tree, key: string): string | undefined {
    let node: string | Tree | undefined = tree;

    for (const part of key.split('.')) {
        if (node === undefined || typeof node === 'string') {
            return undefined;
        }

        node = node[part];
    }

    return typeof node === 'string' ? node : undefined;
}

/**
 * Laravel-style lines: ":name" placeholders and "{1} one|[2,*] many" or "one|many" plurals.
 */
export function translate(
    tree: Tree,
    key: string,
    replace: Record<string, string | number> = {},
    count?: number,
): string {
    let line = lookup(tree, key) ?? key;

    if (count !== undefined && line.includes('|')) {
        const forms = line.split('|');
        const explicit = forms.find((form) => {
            const match = form.match(/^\{(\d+)\}|^\[(\d+),(\d+|\*)\]/);

            if (!match) {
                return false;
            }

            if (match[1] !== undefined) {
                return Number(match[1]) === count;
            }

            return (
                count >= Number(match[2]) &&
                (match[3] === '*' || count <= Number(match[3]))
            );
        });

        line = (
            explicit ?? (count === 1 ? forms[0] : (forms[1] ?? forms[0]))
        ).replace(/^\{\d+\}\s*|^\[\d+,(\d+|\*)\]\s*/, '');
    }

    const values: Record<string, string | number> =
        count !== undefined ? { count, ...replace } : replace;

    return Object.keys(values)
        .sort((a, b) => b.length - a.length)
        .reduce(
            (text, name) => text.replaceAll(`:${name}`, String(values[name])),
            line,
        );
}

export function useT() {
    return t;
}
