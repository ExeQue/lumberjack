<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use App\Domains\SourceMaps\Actions\PruneSourceMapsAction;
use App\Domains\SourceMaps\Models\SourceMap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

covers(PruneSourceMapsAction::class);

describe('source map retention', function () {
    it('deletes a map older than 90 days that belongs to none of the last 10 releases', function () {
        Storage::fake();
        $this->travelTo('2026-11-11 12:00:00');
        $project = Project::factory()->create();

        // Releases r0 (oldest) to r10; r0 is not one of the last 10.
        foreach (range(0, 10) as $i) {
            DB::table('project_releases')->insert(['project_id' => $project->id, 'release' => "r{$i}", 'first_seen_at' => now()->subDays(200 - $i)]);
        }

        $old = now()->subDays(91);
        $map = fn (array $attributes) => SourceMap::factory()->for($project)->withFile('{}')->create($attributes);
        $unreleased = $map(['path' => '/a.js', 'created_at' => $old, 'updated_at' => $old]);
        $outOfReleases = $map(['path' => '/b.js', 'release' => 'r0', 'created_at' => $old, 'updated_at' => $old]);
        $recentRelease = $map(['path' => '/c.js', 'release' => 'r1', 'created_at' => $old, 'updated_at' => $old]);
        $young = $map(['path' => '/d.js', 'created_at' => now()->subDays(89), 'updated_at' => now()->subDays(89)]);
        $otherProject = SourceMap::factory()->withFile('{}')->create(['created_at' => $old, 'updated_at' => $old]);

        expect(app(PruneSourceMapsAction::class)->execute($project))->toBe(2)
            ->and(SourceMap::query()->pluck('id')->sort()->values()->all())
            ->toBe(collect([$recentRelease->id, $young->id, $otherProject->id])->sort()->values()->all());
        Storage::assertMissing($unreleased->filePath());
        Storage::assertMissing($outOfReleases->filePath());
        Storage::assertExists($recentRelease->filePath());
    });
});
