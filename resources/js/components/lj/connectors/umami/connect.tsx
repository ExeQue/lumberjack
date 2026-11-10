import { router, useHttp, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { TimezoneSelect } from '@/components/lj/screen/timezone-select';
import { Button, Field, Input, Segmented, Select } from '@/components/lj/ui';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useT } from '@/lib/i18n';
import {
    add as connectionAdd,
    store as connectionStore,
    update as connectionUpdate,
    websites as savedWebsites,
} from '@/routes/connectors/umami/connection';
import { websites as findWebsites } from '@/routes/connectors/umami';

export type UmamiWebsite = {
    id: string;
    name: string;
    domain: string | null;
    /** The team it belongs to; null for the user's own. */
    team: string | null;
};

type Hosting = 'cloud' | 'self_hosted';

const reportedTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
const browserTimezone =
    reportedTimezone === 'Etc/Unknown' ? 'UTC' : reportedTimezone;

/**
 * Connecting Umami in two steps: the credentials, which list the websites they can read,
 * then the website. Umami Cloud takes an API key; a self-hosted instance an API key or a
 * user name and password.
 */
export function UmamiConnectDialog({
    project,
    onClose,
}: {
    project: string;
    onClose: () => void;
}) {
    const t = useT();
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [login, setLogin] = useState(false);
    const [website, setWebsite] = useState('');
    const [timezone, setTimezone] = useState(browserTimezone);
    const http = useHttp<
        { hosting: Hosting; url: string; username: string; secret: string },
        { websites: UmamiWebsite[] }
    >({ hosting: 'cloud', url: '', username: '', secret: '' });
    const websites = http.response?.websites ?? null;
    const credentials = {
        ...http.data,
        username:
            http.data.hosting === 'self_hosted' && login
                ? http.data.username
                : '',
    };

    const find = () => {
        setWebsite('');
        http.transform(() => credentials);
        http.post(findWebsites(project).url)
            .then((answer) => setWebsite(answer.websites[0]?.id ?? ''))
            // A refusal is shown on the field it is about.
            .catch(() => undefined);
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {t('connectors.umami.not_connected.title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('connectors.umami.not_connected.body')}
                    </DialogDescription>
                </DialogHeader>
                <form
                    className="flex flex-col gap-4"
                    onSubmit={(e) => {
                        e.preventDefault();

                        if (websites === null) {
                            find();

                            return;
                        }

                        router.post(
                            connectionStore(project).url,
                            { ...credentials, website_id: website, timezone },
                            { preserveScroll: true, onSuccess: onClose },
                        );
                    }}
                >
                    <Choice label={t('connectors.umami.connect.hosting')}>
                        <Segmented<Hosting>
                            label={t('connectors.umami.connect.hosting')}
                            value={http.data.hosting}
                            onChange={(hosting) =>
                                http.setData('hosting', hosting)
                            }
                            options={[
                                {
                                    value: 'cloud',
                                    label: t('connectors.umami.connect.cloud'),
                                },
                                {
                                    value: 'self_hosted',
                                    label: t(
                                        'connectors.umami.connect.self_hosted',
                                    ),
                                },
                            ]}
                        />
                    </Choice>

                    {http.data.hosting === 'self_hosted' && (
                        <>
                            <Field
                                label={t('connectors.umami.connect.url')}
                                htmlFor="umami-url"
                                hint={t('connectors.umami.connect.url_hint')}
                                error={http.errors.url}
                            >
                                <Input
                                    id="umami-url"
                                    type="url"
                                    required
                                    placeholder="https://umami.example.com"
                                    value={http.data.url}
                                    onChange={(e) =>
                                        http.setData('url', e.target.value)
                                    }
                                />
                            </Field>
                            <Choice label={t('connectors.umami.connect.auth')}>
                                <Segmented<'key' | 'login'>
                                    label={t('connectors.umami.connect.auth')}
                                    value={login ? 'login' : 'key'}
                                    onChange={(mode) =>
                                        setLogin(mode === 'login')
                                    }
                                    options={[
                                        {
                                            value: 'key',
                                            label: t(
                                                'connectors.umami.connect.api_key',
                                            ),
                                        },
                                        {
                                            value: 'login',
                                            label: t(
                                                'connectors.umami.connect.login',
                                            ),
                                        },
                                    ]}
                                />
                            </Choice>
                        </>
                    )}

                    {http.data.hosting === 'self_hosted' && login && (
                        <Field
                            label={t('connectors.umami.connect.username')}
                            htmlFor="umami-username"
                            error={http.errors.username}
                        >
                            <Input
                                id="umami-username"
                                required
                                autoComplete="off"
                                value={http.data.username}
                                onChange={(e) =>
                                    http.setData('username', e.target.value)
                                }
                            />
                        </Field>
                    )}

                    <Field
                        label={
                            http.data.hosting === 'self_hosted' && login
                                ? t('connectors.umami.connect.password')
                                : t('connectors.umami.connect.api_key')
                        }
                        htmlFor="umami-secret"
                        hint={
                            http.data.hosting === 'cloud'
                                ? t('connectors.umami.connect.cloud_key_hint')
                                : t('connectors.umami.connect.secret_hint')
                        }
                        error={http.errors.secret}
                    >
                        <Input
                            id="umami-secret"
                            type="password"
                            required
                            autoComplete="off"
                            data-1p-ignore
                            data-lpignore="true"
                            data-bwignore
                            value={http.data.secret}
                            onChange={(e) =>
                                http.setData('secret', e.target.value)
                            }
                        />
                    </Field>

                    {websites !== null && (
                        <>
                            <Field
                                label={t('connectors.umami.connect.website')}
                                htmlFor="umami-website"
                                hint={
                                    websites.length === 0
                                        ? t(
                                              'connectors.umami.connect.no_websites',
                                          )
                                        : t(
                                              'connectors.umami.connect.website_hint',
                                          )
                                }
                                error={errors.website_id}
                            >
                                {websites.length === 0 ? (
                                    <Input
                                        id="umami-website"
                                        required
                                        placeholder="00000000-0000-0000-0000-000000000000"
                                        value={website}
                                        onChange={(e) =>
                                            setWebsite(e.target.value.trim())
                                        }
                                    />
                                ) : (
                                    <WebsiteSelect
                                        id="umami-website"
                                        websites={websites}
                                        value={website}
                                        onChange={setWebsite}
                                    />
                                )}
                            </Field>
                            <Field
                                label={t('connectors.umami.connect.timezone')}
                                htmlFor="umami-timezone"
                                hint={t(
                                    'connectors.umami.connect.timezone_hint',
                                )}
                            >
                                <TimezoneSelect
                                    id="umami-timezone"
                                    value={timezone}
                                    onChange={setTimezone}
                                />
                            </Field>
                        </>
                    )}

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            {t('connectors.umami.connect.cancel')}
                        </Button>
                        {websites !== null && (
                            <Button type="button" onClick={find}>
                                {t('connectors.umami.connect.find_again')}
                            </Button>
                        )}
                        <Button
                            type="submit"
                            variant="primary"
                            disabled={
                                http.processing ||
                                (websites !== null && website === '')
                            }
                        >
                            {websites === null
                                ? t('connectors.umami.connect.find')
                                : t('connectors.umami.connect.submit')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * A labelled row of choices, as wide as its options: the label is seen, and also names
 * the group for a screen reader.
 */
function Choice({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-1.5">
            <span className="text-[13px] font-medium">{label}</span>
            <div className="w-fit">{children}</div>
        </div>
    );
}

function WebsiteSelect({
    id,
    websites,
    value,
    onChange,
}: {
    id: string;
    websites: UmamiWebsite[];
    value: string;
    onChange: (id: string) => void;
}) {
    const t = useT();
    // The user's own first, then each team's, as the server sorted them.
    const groups = [...new Set(websites.map((site) => site.team))];

    return (
        <Select
            id={id}
            required
            value={value}
            onChange={(e) => onChange(e.target.value)}
        >
            {groups.map((team) => (
                <optgroup
                    key={team ?? ''}
                    label={team ?? t('connectors.umami.connect.personal')}
                >
                    {websites
                        .filter((site) => site.team === team)
                        .map((site) => (
                            <option key={site.id} value={site.id}>
                                {site.domain
                                    ? `${site.name} (${site.domain})`
                                    : site.name}
                            </option>
                        ))}
                </optgroup>
            ))}
        </Select>
    );
}

/**
 * Pointing a connection at another website its saved credentials can read, or connecting
 * another website with them; the list is asked for when opened, as the credentials never
 * reach the browser.
 */
export function UmamiWebsiteSwitcher({
    project,
    connection,
    current,
    mode,
}: {
    project: string;
    connection: string;
    current: string | null;
    mode: 'change' | 'add';
}) {
    const t = useT();
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [website, setWebsite] = useState(current ?? '');
    const [failure, setFailure] = useState<string | null>(null);
    const http = useHttp<object, { websites: UmamiWebsite[] }>({});
    const websites = http.response?.websites ?? null;

    if (websites === null) {
        return (
            <div className="flex flex-col gap-1.5">
                <div>
                    <Button
                        disabled={http.processing}
                        onClick={() =>
                            http
                                .get(savedWebsites([project, connection]).url)
                                .catch((error: { message?: string }) =>
                                    setFailure(
                                        error?.message ??
                                            t(
                                                'connectors.umami.settings.failed',
                                            ),
                                    ),
                                )
                        }
                    >
                        {t(
                            mode === 'add'
                                ? 'connectors.umami.settings.add_website'
                                : 'connectors.umami.settings.change_website',
                        )}
                    </Button>
                </div>
                {failure && <p className="text-xs text-err">{failure}</p>}
            </div>
        );
    }

    return (
        <form
            className="flex flex-wrap items-end gap-2"
            onSubmit={(e) => {
                e.preventDefault();
                const data = { website_id: website };

                if (mode === 'add') {
                    router.post(connectionAdd([project, connection]).url, data);
                } else {
                    router.put(
                        connectionUpdate([project, connection]).url,
                        data,
                        { preserveScroll: true },
                    );
                }
            }}
        >
            <Field
                label={t('connectors.umami.connect.website')}
                htmlFor={`umami-${mode}-website`}
                error={errors.website_id}
            >
                <WebsiteSelect
                    id={`umami-${mode}-website`}
                    websites={websites}
                    value={website}
                    onChange={setWebsite}
                />
            </Field>
            <Button
                type="submit"
                variant="primary"
                disabled={website === '' || website === current}
            >
                {t(
                    mode === 'add'
                        ? 'connectors.umami.settings.connect_website'
                        : 'connectors.umami.settings.save_website',
                )}
            </Button>
        </form>
    );
}
