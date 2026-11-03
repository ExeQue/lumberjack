<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Administration\Http\Middleware\RedirectToSetup;
use App\Domains\Administration\Listeners\ConfirmPasswordOnLogin;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\Events\DatabaseRefreshed;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Behind a reverse proxy, HTTPS and the client's address come from its X-Forwarded-* headers.
        $proxies = (string) config('app.trusted_proxies');
        if ($proxies !== '') {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        // Links (password resets, invitations, alerts) always point at APP_URL, never at a
        // Host or X-Forwarded-Host header someone sent.
        $root = rtrim((string) config('app.url'), '/');
        if ($root !== '') {
            URL::forceRootUrl($root);
            URL::forceScheme(str_starts_with($root, 'https://') ? 'https' : 'http');
        }

        // A wiped database has no users, so first-run setup has to come back with it.
        Event::listen(DatabaseRefreshed::class, RedirectToSetup::forget(...));
        Event::listen(Login::class, ConfirmPasswordOnLogin::class);

        // A system administrator may do anything: they run this Lumberjack.
        Gate::before(fn (User $user): ?bool => $user->isAdmin() ? true : null);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(
            fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
