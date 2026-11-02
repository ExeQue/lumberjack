import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/setup';

type Props = {
    requiresToken: boolean;
    recovery: boolean;
    passwordRules: string;
};

/**
 * First-run setup of an empty Lumberjack: the first administrator chooses their own
 * credentials. There is no default account.
 */
export default function Setup({
    requiresToken,
    recovery,
    passwordRules,
}: Props) {
    return (
        <>
            <Head title="Set up Lumberjack" />

            <Form
                {...store.form()}
                resetOnError={['password', 'password_confirmation']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <div className="grid gap-6">
                        {recovery && (
                            <p
                                role="status"
                                className="rounded-md border border-amber/40 bg-amber/10 px-4 py-3 text-sm"
                            >
                                This Lumberjack has users but no administrator
                                who can sign in. Create a new administrator.
                            </p>
                        )}
                        {requiresToken && (
                            <div className="grid gap-2">
                                <Label htmlFor="token">Setup token</Label>
                                <Input
                                    id="token"
                                    name="token"
                                    required
                                    autoFocus
                                    autoComplete="off"
                                    spellCheck={false}
                                    className="font-mono"
                                    aria-describedby="token-hint"
                                />
                                <p
                                    id="token-hint"
                                    className="text-xs text-muted-foreground"
                                >
                                    The value of LUMBERJACK_SETUP_TOKEN. With
                                    Docker and no token of your own, it is
                                    printed in the log on first start:{' '}
                                    <code className="font-mono">
                                        docker compose logs lumberjack
                                    </code>
                                </p>
                                <InputError message={errors.token} />
                            </div>
                        )}

                        <div className="grid gap-2">
                            <Label htmlFor="name">Name</Label>
                            <Input
                                id="name"
                                name="name"
                                required
                                autoFocus={!requiresToken}
                                autoComplete="name"
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="email">Email</Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                required
                                autoComplete="email"
                            />
                            <InputError message={errors.email} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password">Password</Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                required
                                autoComplete="new-password"
                                passwordrules={passwordRules}
                            />
                            <InputError message={errors.password} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password_confirmation">
                                Confirm password
                            </Label>
                            <PasswordInput
                                id="password_confirmation"
                                name="password_confirmation"
                                required
                                autoComplete="new-password"
                                passwordrules={passwordRules}
                            />
                            <InputError
                                message={errors.password_confirmation}
                            />
                        </div>

                        <Button
                            type="submit"
                            className="mt-2 w-full"
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            Create administrator
                        </Button>
                    </div>
                )}
            </Form>
        </>
    );
}

Setup.layout = {
    title: 'Set up Lumberjack',
    description:
        'No one uses this Lumberjack yet. Create the first administrator with your own credentials.',
};
