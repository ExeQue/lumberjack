<?php

declare(strict_types=1);

namespace App\Domains\SourceMaps\Actions;

use App\Domains\Projects\Models\Project;
use App\Domains\SourceMaps\Models\SourceMap;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Keeps an uploaded source map. The same path and release uploaded again replaces it.
 */
class StoreSourceMapAction
{
    public function execute(Project $project, UploadedFile $file, string $path, ?string $release): SourceMap
    {
        $map = SourceMap::query()->firstOrNew([
            'project_id' => $project->id,
            'path' => SourceMap::normalizePath($path),
            'release' => $release !== '' ? $release : null,
        ]);
        $map->size = (int) $file->getSize();
        // A replaced map counts as new, also when its size did not change.
        $map->updated_at = now();
        $map->save();

        Storage::putFileAs(SourceMap::directory($project->id), $file, $map->id.'.map');

        return $map;
    }
}
