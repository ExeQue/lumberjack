<?php

declare(strict_types=1);

namespace App\Domains\Connectors\MobilePay\Http\Controllers;

use App\Domains\Connectors\MobilePay\Actions\ConnectMobilePayAction;
use App\Domains\Connectors\MobilePay\Exceptions\MobilePayExceptionInterface;
use App\Domains\Connectors\MobilePay\Http\Requests\ConnectMobilePayRequest;
use App\Domains\Projects\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Connecting a project to a Vipps MobilePay ledger; the keys never leave the server again.
 */
class MobilePayConnectionController
{
    public function store(ConnectMobilePayRequest $request, Project $project, ConnectMobilePayAction $action): RedirectResponse
    {
        try {
            $connection = $action->execute($project, $request->credentials(), $request->string('timezone', 'UTC')->toString());
        } catch (MobilePayExceptionInterface $e) {
            throw ValidationException::withMessages(['client_secret' => $e->getMessage()]);
        }

        return to_route('connectors.mobilepay.index', [$project, $connection])
            ->with('status', __('connectors.mobilepay.connected', ['name' => $connection->name]));
    }
}
