import { Form } from '@inertiajs/react';
import { TimezoneSelect } from '@/components/lj/screen/timezone-select';
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
import { store as connectionStore } from '@/routes/connectors/mosparo/connection';

const reportedTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
const browserTimezone =
    reportedTimezone === 'Etc/Unknown' ? 'UTC' : reportedTimezone;

/**
 * Connecting a mosparo project by its API keys; mosparo is asked once with them before the
 * connection is saved, so a wrong key is said on the field.
 */
export function MosparoConnectDialog({
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
                        {t('connectors.mosparo.not_connected.title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('connectors.mosparo.not_connected.body')}
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
                                label={t('connectors.mosparo.connect.name')}
                                htmlFor="mosparo-name"
                                hint={t('connectors.mosparo.connect.name_hint')}
                                error={errors.name}
                            >
                                <Input
                                    id="mosparo-name"
                                    name="name"
                                    required
                                    autoFocus
                                    maxLength={200}
                                    autoComplete="off"
                                />
                            </Field>
                            <Field
                                label={t('connectors.mosparo.connect.url')}
                                htmlFor="mosparo-url"
                                hint={t('connectors.mosparo.connect.url_hint')}
                                error={errors.url}
                            >
                                <Input
                                    id="mosparo-url"
                                    name="url"
                                    type="url"
                                    required
                                    maxLength={2000}
                                    placeholder="https://mosparo.example.com"
                                    className="font-mono"
                                    autoComplete="off"
                                />
                            </Field>
                            <Field
                                label={t(
                                    'connectors.mosparo.connect.public_key',
                                )}
                                htmlFor="mosparo-public-key"
                                hint={t('connectors.mosparo.connect.keys_hint')}
                                error={errors.public_key}
                            >
                                <Input
                                    id="mosparo-public-key"
                                    name="public_key"
                                    required
                                    maxLength={200}
                                    className="font-mono"
                                    autoComplete="off"
                                />
                            </Field>
                            <Field
                                label={t(
                                    'connectors.mosparo.connect.private_key',
                                )}
                                htmlFor="mosparo-private-key"
                                error={errors.private_key}
                            >
                                {/* Not a login: password managers keep out of it. */}
                                <Input
                                    id="mosparo-private-key"
                                    name="private_key"
                                    type="password"
                                    required
                                    maxLength={1000}
                                    autoComplete="off"
                                    data-1p-ignore
                                    data-lpignore="true"
                                    data-bwignore
                                />
                            </Field>
                            <Field
                                label={t('connectors.mosparo.connect.timezone')}
                                htmlFor="mosparo-timezone"
                                hint={t(
                                    'connectors.mosparo.connect.timezone_hint',
                                )}
                                error={errors.timezone}
                            >
                                <TimezoneSelect
                                    id="mosparo-timezone"
                                    name="timezone"
                                    defaultValue={browserTimezone}
                                />
                            </Field>
                            <DialogFooter>
                                <Button type="button" onClick={onClose}>
                                    {t('connectors.mosparo.connect.cancel')}
                                </Button>
                                <Button
                                    type="submit"
                                    variant="primary"
                                    disabled={processing}
                                >
                                    {t('connectors.mosparo.connect.submit')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
