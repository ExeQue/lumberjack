<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Packagist\Http\Controllers;

use App\Domains\Connectors\Packagist\Actions\ConnectPackagistAction;
use App\Domains\Connectors\Packagist\Exceptions\PackagistExceptionInterface;
use App\Domains\Connectors\Packagist\Http\Requests\ConnectPackagistRequest;
use App\Domains\Connectors\Packagist\Http\Requests\SearchPackagistRequest;
use App\Domains\Connectors\Packagist\Support\PackagistClient;
use App\Domains\Projects\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Connecting a project to a Packagist package, found by a search as it is typed.
 */
class PackagistConnectionController
{
    public function store(ConnectPackagistRequest $request, Project $project, ConnectPackagistAction $action): RedirectResponse
    {
        try {
            $connection = $action->execute($project, $request->string('package')->toString());
        } catch (PackagistExceptionInterface $e) {
            throw ValidationException::withMessages(['package' => $e->getMessage()]);
        }

        return to_route('connectors.packagist.index', [$project, $connection])
            ->with('status', __('connectors.packagist.connected', ['name' => $connection->name]));
    }

    /**
     * Packages matching what is typed; nothing when Packagist cannot be asked.
     */
    public function search(SearchPackagistRequest $request, Project $project, PackagistClient $client): JsonResponse
    {
        try {
            return response()->json($client->search($request->string('q')->toString()));
        } catch (PackagistExceptionInterface) {
            return response()->json([]);
        }
    }
}
