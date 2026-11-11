import { Form } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import { useState } from 'react';
import { Button, buttonClass, Field, Input } from '@/components/lj/ui';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useT } from '@/lib/i18n';
import { store as connectionStore } from '@/routes/connectors/github/connection';

/**
 * Connecting a GitHub repository with a read-only token; GitHub is asked with it before the
 * connection is saved, so a missing permission is said on the field.
 */
export function GitHubConnectDialog({
    project,
    onClose,
}: {
    project: string;
    onClose: () => void;
}) {
    const t = useT();
    const [repository, setRepository] = useState('');

    // GitHub pre-fills a fine-grained token from these query parameters. GitHub has no
    // parameter for the repository itself, so it still has to be picked under
    // "Repository access"; the owner (target_name) is taken from what was typed.
    const name = repositoryName(repository);
    const newTokenUrl = `https://github.com/settings/personal-access-tokens/new?${new URLSearchParams(
        {
            name: `Lumberjack ${name ?? project}`.slice(0, 40),
            description: t('connectors.github.connect.token_description', {
                repository: name ?? '-',
            }),
            ...(name !== null ? { target_name: name.split('/')[0] } : {}),
            expires_in: '90',
            contents: 'read',
            pull_requests: 'read',
            actions: 'read',
            // Optional: each adds sources, and connecting works without them.
            issues: 'read',
            vulnerability_alerts: 'read',
            security_events: 'read',
            secret_scanning_alerts: 'read',
        },
    ).toString()}`;

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {t('connectors.github.not_connected.title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('connectors.github.not_connected.body')}
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
                                    'connectors.github.connect.repository',
                                )}
                                htmlFor="github-repository"
                                hint={t(
                                    'connectors.github.connect.repository_hint',
                                )}
                                error={errors.repository}
                            >
                                <Input
                                    id="github-repository"
                                    name="repository"
                                    required
                                    autoFocus
                                    maxLength={200}
                                    placeholder="acme/shop"
                                    className="font-mono"
                                    autoComplete="off"
                                    value={repository}
                                    onChange={(event) =>
                                        setRepository(event.target.value)
                                    }
                                />
                            </Field>
                            <Field
                                label={t('connectors.github.connect.token')}
                                htmlFor="github-token"
                                hint={t('connectors.github.connect.token_hint')}
                                error={errors.token}
                            >
                                {/* Not a login: password managers keep out of it. */}
                                <Input
                                    id="github-token"
                                    name="token"
                                    type="password"
                                    required
                                    maxLength={255}
                                    autoComplete="off"
                                    data-1p-ignore
                                    data-lpignore="true"
                                    data-bwignore
                                />
                                <a
                                    href={newTokenUrl}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className={`${buttonClass('default')} self-start`}
                                >
                                    <ExternalLink
                                        className="size-3.5"
                                        aria-hidden="true"
                                    />
                                    {t(
                                        'connectors.github.connect.create_token',
                                    )}
                                    <span className="sr-only">
                                        {t(
                                            'connectors.github.connect.opens_new_tab',
                                        )}
                                    </span>
                                </a>
                            </Field>
                            <DialogFooter>
                                <Button type="button" onClick={onClose}>
                                    {t('connectors.github.connect.cancel')}
                                </Button>
                                <Button
                                    type="submit"
                                    variant="primary"
                                    disabled={processing}
                                >
                                    {t('connectors.github.connect.submit')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

/** owner/name from what was typed: owner/name itself, or any address on github.com under the repository. */
function repositoryName(value: string): string | null {
    const match = value
        .trim()
        .match(
            /^(?:(?:https?:\/\/)?(?:www\.)?github\.com\/)?([\w.-]+)\/([\w.-]+?)(?:\.git)?(?:[/?#].*)?$/i,
        );

    return match ? `${match[1]}/${match[2]}` : null;
}
