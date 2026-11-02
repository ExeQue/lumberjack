import { Form, Head, setLayoutProps, usePage } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { accept } from '@/routes/invitations';
import { login } from '@/routes';

type Props = {
    token: string;
    invitation: {
        team: string;
        email: string;
        role: 'owner' | 'admin' | 'member';
        has_account: boolean;
    } | null;
};

export default function InvitationShow({ token, invitation }: Props) {
    const t = useT();
    const { auth } = usePage<{ auth: { user: { name: string } | null } }>()
        .props;

    setLayoutProps(
        invitation === null
            ? {
                  title: t('invitation.expired_title'),
                  description: t('invitation.expired_body'),
              }
            : {
                  title: t('invitation.title', { team: invitation.team }),
                  description: t('invitation.description', {
                      email: invitation.email,
                      role: t(`common.roles.${invitation.role}`),
                  }),
              },
    );

    if (invitation === null) {
        return <Head title={t('invitation.expired_title')} />;
    }

    if (auth.user === null && invitation.has_account) {
        return (
            <>
                <Head
                    title={t('invitation.title', { team: invitation.team })}
                />
                <p className="text-center text-sm">
                    {t('invitation.has_account')}{' '}
                    <TextLink href={login()}>{t('invitation.log_in')}</TextLink>
                </p>
            </>
        );
    }

    return (
        <>
            <Head title={t('invitation.title', { team: invitation.team })} />
            <Form
                {...accept.form(token)}
                resetOnSuccess={['password', 'password_confirmation']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        {errors.token && <InputError message={errors.token} />}
                        {auth.user === null && (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="name">
                                        {t('invitation.name')}
                                    </Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        required
                                        autoFocus
                                        autoComplete="name"
                                    />
                                    <InputError message={errors.name} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="password">
                                        {t('invitation.password')}
                                    </Label>
                                    <PasswordInput
                                        id="password"
                                        name="password"
                                        required
                                        autoComplete="new-password"
                                    />
                                    <InputError message={errors.password} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="password_confirmation">
                                        {t('invitation.password_confirmation')}
                                    </Label>
                                    <PasswordInput
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        required
                                        autoComplete="new-password"
                                    />
                                    <InputError
                                        message={errors.password_confirmation}
                                    />
                                </div>
                            </>
                        )}
                        <Button
                            type="submit"
                            className="w-full"
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            {auth.user === null
                                ? t('invitation.create_and_join')
                                : t('invitation.accept')}
                        </Button>
                    </>
                )}
            </Form>
        </>
    );
}
