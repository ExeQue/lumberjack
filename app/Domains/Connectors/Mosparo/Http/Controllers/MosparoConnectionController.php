<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Mosparo\Http\Controllers;

use App\Domains\Connectors\Mosparo\Actions\ConnectMosparoAction;
use App\Domains\Connectors\Mosparo\Exceptions\MosparoExceptionInterface;
use App\Domains\Connectors\Mosparo\Http\Requests\ConnectMosparoRequest;
use App\Domains\Projects\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Connecting a project to a mosparo project; the keys never leave the server again.
 */
class MosparoConnectionController
{
    public function store(ConnectMosparoRequest $request, Project $project, ConnectMosparoAction $action): RedirectResponse
    {
        try {
            $connection = $action->execute($project, $request->credentials(), $request->string('name')->trim()->toString(), $request->string('timezone', 'UTC')->toString());
        } catch (MosparoExceptionInterface $e) {
            throw ValidationException::withMessages(['private_key' => $e->getMessage()]);
        }

        return to_route('connectors.mosparo.index', [$project, $connection])
            ->with('status', __('connectors.mosparo.connected', ['name' => $connection->name]));
    }
}
