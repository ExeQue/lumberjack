<?php

declare(strict_types=1);

namespace App\Domains\Administration\Http\Controllers;

use App\Domains\Administration\Enums\SetupState;
use App\Domains\Administration\Http\Middleware\RedirectToSetup;
use App\Domains\Administration\Http\Requests\SetupRequest;
use App\Domains\Teams\Actions\CreateUserAction;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * First-run setup: creates the first administrator with credentials of their
 * own choosing. Where an active administrator exists it answers 404, as if it
 * were not there. With users but no active administrator it is a recovery,
 * open only with LUMBERJACK_SETUP_TOKEN; without one, recover from the shell
 * with `php artisan lumberjack:make-admin`. See docs/administration.md.
 */
class SetupController
{
    public function show(): Response
    {
        $state = SetupState::current();

        return Inertia::render('auth/setup', [
            'requiresToken' => (string) config('app.setup_token') !== '',
            'recovery' => $state === SetupState::Recovery,
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function store(SetupRequest $request, CreateUserAction $createUser): RedirectResponse
    {
        // The lock keeps two people submitting at once from both becoming administrator.
        $user = Cache::lock('lumberjack.setup', 10)->block(5, fn (): ?User => DB::transaction(function () use ($request, $createUser): ?User {
            if (! SetupState::current()->isOpen()) {
                return null;
            }

            return $createUser->execute($request->string('name')->toString(), $request->string('email')->toString(), $request->string('password')->toString(), admin: true);
        }));

        abort_if($user === null, 404);

        RedirectToSetup::markComplete();
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('admin.teams.index');
    }
}
