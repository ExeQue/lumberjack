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
import { store as connectionStore } from '@/routes/connectors/mobilepay/connection';

const reportedTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
const browserTimezone =
    reportedTimezone === 'Etc/Unknown' ? 'UTC' : reportedTimezone;

/** The secret fields: not a login, so password managers keep out of them. */
const SECRET = {
    type: 'password',
    autoComplete: 'off',
    'data-1p-ignore': true,
    'data-lpignore': 'true',
    'data-bwignore': true,
} as const;

/**
 * Connecting a Vipps MobilePay ledger by a sales unit's API keys; the ledger is looked up
 * with them before the connection is saved, so wrong keys are said on the secret.
 */
export function MobilePayConnectDialog({
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
                        {t('connectors.mobilepay.not_connected.title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('connectors.mobilepay.not_connected.body')}
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
                                label={t('connectors.mobilepay.connect.msn')}
                                htmlFor="mobilepay-msn"
                                hint={t(
                                    'connectors.mobilepay.connect.keys_hint',
                                )}
                                error={errors.msn}
                            >
                                <Input
                                    id="mobilepay-msn"
                                    name="msn"
                                    required
                                    autoFocus
                                    inputMode="numeric"
                                    maxLength={20}
                                    className="font-mono"
                                    autoComplete="off"
                                />
                            </Field>
                            <Field
                                label={t(
                                    'connectors.mobilepay.connect.client_id',
                                )}
                                htmlFor="mobilepay-client-id"
                                error={errors.client_id}
                            >
                                <Input
                                    id="mobilepay-client-id"
                                    name="client_id"
                                    required
                                    maxLength={200}
                                    className="font-mono"
                                    autoComplete="off"
                                />
                            </Field>
                            <Field
                                label={t(
                                    'connectors.mobilepay.connect.client_secret',
                                )}
                                htmlFor="mobilepay-client-secret"
                                error={errors.client_secret}
                            >
                                <Input
                                    id="mobilepay-client-secret"
                                    name="client_secret"
                                    required
                                    maxLength={1000}
                                    {...SECRET}
                                />
                            </Field>
                            <Field
                                label={t(
                                    'connectors.mobilepay.connect.subscription_key',
                                )}
                                htmlFor="mobilepay-subscription-key"
                                error={errors.subscription_key}
                            >
                                <Input
                                    id="mobilepay-subscription-key"
                                    name="subscription_key"
                                    required
                                    maxLength={200}
                                    {...SECRET}
                                />
                            </Field>
                            <Field
                                label={t(
                                    'connectors.mobilepay.connect.timezone',
                                )}
                                htmlFor="mobilepay-timezone"
                                hint={t(
                                    'connectors.mobilepay.connect.timezone_hint',
                                )}
                                error={errors.timezone}
                            >
                                <TimezoneSelect
                                    id="mobilepay-timezone"
                                    name="timezone"
                                    defaultValue={browserTimezone}
                                />
                            </Field>
                            <DialogFooter>
                                <Button type="button" onClick={onClose}>
                                    {t('connectors.mobilepay.connect.cancel')}
                                </Button>
                                <Button
                                    type="submit"
                                    variant="primary"
                                    disabled={processing}
                                >
                                    {t('connectors.mobilepay.connect.submit')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
