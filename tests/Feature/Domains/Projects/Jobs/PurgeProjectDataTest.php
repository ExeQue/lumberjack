<?php

declare(strict_types=1);

use App\Domains\Projects\Actions\DeleteProjectAction;
use App\Domains\Projects\Jobs\PurgeProjectData;
use App\Domains\Projects\Models\Project;
use App\Domains\SourceMaps\Models\SourceMap;
use Illuminate\Support\Facades\Storage;

covers(PurgeProjectData::class);

describe('a deleted project\'s files', function () {
    it('takes the project\'s source maps along, and no one else\'s', function () {
        Storage::fake();
        $project = Project::factory()->create();
        $map = SourceMap::factory()->for($project)->withFile('{}')->create();
        $other = SourceMap::factory()->withFile('{}')->create();

        app(DeleteProjectAction::class)->execute($project);

        expect(SourceMap::query()->pluck('id')->all())->toBe([$other->id]);
        Storage::assertMissing($map->filePath());
        Storage::assertExists($other->filePath());
    });
});
