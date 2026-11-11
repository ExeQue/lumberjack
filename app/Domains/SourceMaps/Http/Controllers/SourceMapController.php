<?php

declare(strict_types=1);

namespace App\Domains\SourceMaps\Http\Controllers;

use App\Domains\Projects\Models\Project;
use App\Domains\SourceMaps\Actions\DeleteSourceMapAction;
use App\Domains\SourceMaps\Actions\StoreSourceMapAction;
use App\Domains\SourceMaps\Http\Requests\StoreSourceMapRequest;
use App\Domains\SourceMaps\Models\SourceMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class SourceMapController
{
    /** Uploaded after a build, with the project's server token. */
    public function store(StoreSourceMapRequest $request, StoreSourceMapAction $action): JsonResponse
    {
        $map = $action->execute(
            $request->project(),
            $request->map(),
            $request->string('path')->toString(),
            $request->filled('release') ? $request->string('release')->toString() : null,
        );

        return response()->json(['path' => $map->path, 'release' => $map->release, 'size' => $map->size], 201);
    }

    public function destroy(Project $project, SourceMap $sourceMap, DeleteSourceMapAction $action): RedirectResponse
    {
        Gate::authorize('update', $project);

        $action->execute($sourceMap);

        return back()->with('status', __('source_maps.deleted', ['path' => $sourceMap->path]));
    }
}
