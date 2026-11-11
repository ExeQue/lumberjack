<?php

declare(strict_types=1);

namespace App\Domains\SourceMaps\Actions;

use App\Domains\Projects\Models\Project;
use App\Domains\SourceMaps\Exceptions\SourceMapNotStoredException;
use App\Domains\SourceMaps\Models\SourceMap;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Keeps an uploaded source map. The same path and release uploaded again replaces it.
 */
class StoreSourceMapAction
{
    public function execute(Project $project, UploadedFile $file, string $path, ?string $release): SourceMap
    {
        // createOrFirst underneath: two uploads of the same map at once both land on one row.
        $map = SourceMap::query()->firstOrCreate([
            'project_id' => $project->id,
            'path' => SourceMap::normalizePath($path),
            'release' => $release !== '' ? $release : null,
        ], ['size' => (int) $file->getSize()]);

        try {
            $stored = Storage::putFileAs(SourceMap::directory($project->id), $file, $map->id.'.map');
        } catch (Throwable $e) {
            $stored = false;
            report($e);
        }

        // A row without its file would fail every frame it should map. One a concurrent upload
        // of the same map wrote the file for is kept.
        if ($stored === false) {
            if ($map->wasRecentlyCreated && ! Storage::exists($map->filePath())) {
                $map->delete();
            }

            throw new SourceMapNotStoredException(__('source_maps.not_stored'));
        }

        // Once the file is there: a replaced map counts as new, also when its size did not change,
        // and a decoded copy of the old one is not taken for it.
        if (! $map->wasRecentlyCreated) {
            $map->size = (int) $file->getSize();
            $map->updated_at = now();
            $map->save();
        }

        return $map;
    }
}
