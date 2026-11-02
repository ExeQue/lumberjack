<?php

declare(strict_types=1);

namespace App\Domains\Administration\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;

/**
 * Signing in with a password confirms it. Without this, someone who has to pick
 * a new password is asked for the one they typed a second earlier.
 */
class ConfirmPasswordOnLogin
{
    public function __construct(
        private readonly Request $request,
    ) {
    }

    public function handle(Login $event): void
    {
        if ($this->request->hasSession()) {
            $this->request->session()->passwordConfirmed();
        }
    }
}
