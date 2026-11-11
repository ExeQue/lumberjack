import { Form } from '@inertiajs/react';
import { Button, Field, Input } from '@/components/lj/ui';
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
