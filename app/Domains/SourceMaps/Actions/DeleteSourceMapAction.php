<?php

declare(strict_types=1);

namespace App\Domains\SourceMaps\Actions;

use App\Domains\SourceMaps\Models\SourceMap;
use Illuminate\Support\Facades\Storage;

class DeleteSourceMapAction
{
    public function execute(SourceMap $map): void
    {
        Storage::delete($map->filePath());
        $map->delete();
    }
}
