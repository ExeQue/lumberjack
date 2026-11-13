import { Form } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { TreeSelect } from '@/components/lj/tree-select';
import type { TreeOption } from '@/components/lj/tree-select';
import { Button, Field } from '@/components/lj/ui';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatCompact } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { search as searchRoute } from '@/routes/connectors/packagist';
import { store as connectionStore } from '@/routes/connectors/packagist/connection';

/** How long typing pauses before Packagist is searched. */
const SEARCH_DELAY_MS = 250;

type Found = { name: string; description: string; downloads: number };

/**
 * The packages Packagist finds for what is typed, asked a moment after the last key; an
 * older answer never overwrites a newer one.
 */
function usePackageSearch(
    project: string,
): [TreeOption[], (query: string) => void] {
    const [options, setOptions] = useState<TreeOption[]>([]);
    const [query, setQuery] = useState('');
    const latest = useRef(0);

    useEffect(() => {
        const ticket = ++latest.current;

        if (query.trim().length < 2) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            fetch(searchRoute(project, { query: { q: query.trim() } }).url, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((response) => (response.ok ? response.json() : []))
                .then((found: Found[]) => {
                    if (ticket === latest.current) {
                        setOptions(
                            found.map((p) => ({
                                value: p.name,
                                label: `${p.name} · ${formatCompact(p.downloads)}`,
                                keywords: p.description,
                            })),
                        );
                    }
                })
                .catch(() => undefined);
        }, SEARCH_DELAY_MS);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [project, query]);

    return [options, useCallback((q: string) => setQuery(q), [])];
}

/**
 * Connecting a Packagist package, found by searching Packagist as it is typed. What is
 * typed as vendor/package can be picked too, so a package search misses is still found.
 */
export function PackagistConnectDialog({
    project,
    onClose,
}: {
    project: string;
    onClose: () => void;
}) {
    const t = useT();
    const [found, search] = usePackageSearch(project);
    const [typed, setTyped] = useState('');
    const [picked, setPicked] = useState('');
    const exact = /^[a-z0-9][a-z0-9_.-]*\/[a-z0-9][a-z0-9_.-]*$/.test(typed);
    const options =
        exact && !found.some((o) => o.value === typed)
            ? [{ value: typed, label: typed }, ...found]
            : found;

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {t('connectors.packagist.not_connected.title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('connectors.packagist.not_connected.body')}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...connectionStore.form(project)}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="flex flex-col gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <Field
                                label={t(
                                    'connectors.packagist.connect.package',
                                )}
                                htmlFor="packagist-package"
                                hint={t(
                                    'connectors.packagist.connect.package_hint',
                                )}
                                error={errors.package}
                            >
                                <TreeSelect
                                    id="packagist-package"
                                    name="package"
                                    label={t(
                                        'connectors.packagist.connect.package',
                                    )}
                                    options={options}
                                    value={picked}
                                    onChange={(value) => setPicked(value ?? '')}
                                    optionFor={(value) => ({
                                        value,
                                        label: value,
                                    })}
                                    placeholder={t(
                                        'connectors.packagist.connect.placeholder',
                                    )}
                                    searchPlaceholder={t(
                                        'connectors.packagist.connect.search',
                                    )}
                                    noMatches={t(
                                        'connectors.packagist.connect.no_matches',
                                    )}
                                    startOpen
                                    onSearch={(query) => {
                                        setTyped(query.trim().toLowerCase());
                                        search(query);
                                    }}
                                />
                            </Field>
                            <DialogFooter>
                                <Button type="button" onClick={onClose}>
                                    {t('connectors.packagist.connect.cancel')}
                                </Button>
                                <Button
                                    type="submit"
                                    variant="primary"
                                    disabled={processing || picked === ''}
                                >
                                    {t('connectors.packagist.connect.submit')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
