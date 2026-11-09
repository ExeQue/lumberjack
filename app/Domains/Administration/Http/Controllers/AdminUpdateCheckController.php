<?php

declare(strict_types=1);

namespace App\Domains\Administration\Http\Controllers;

use App\Domains\Administration\Actions\CheckForUpdateAction;
use Illuminate\Http\RedirectResponse;

class AdminUpdateCheckController
{
    public function __invoke(CheckForUpdateAction $action): RedirectResponse
    {
        return $action->execute()
            ? back()->with('status', __('administration.update_checked'))
            : back()->withErrors(['update_check' => __('administration.update_check_failed')]);
    }
}
