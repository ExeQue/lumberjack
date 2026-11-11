<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use App\Domains\SourceMaps\Actions\StoreSourceMapAction;
use App\Domains\SourceMaps\Http\Controllers\SourceMapController;
use App\Domains\SourceMaps\Models\SourceMap;
use App\Domains\Teams\Enums\TeamRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Teams\CreatesTeamMembers;

covers(SourceMapController::class, StoreSourceMapAction::class);

uses(CreatesTeamMembers::class);

beforeEach(function () {
    Storage::fake();
    $this->map = (string) file_get_contents(base_path('tests/Fixtures/SourceMaps/app.js.map'));
});

describe('uploading a source map', function () {
    beforeEach(function () {
        $this->project = Project::factory()->withToken('lj_srv_test-token')->create();
        $this->upload = fn (array $fields, string $token = 'lj_srv_test-token') => $this->post(
            route('ingest.sourcemaps'),
            $fields,
            ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'],
        );
    });

    it('rejects an unknown token', function () {
        ($this->upload)(['file' => UploadedFile::fake()->createWithContent('app.js.map', $this->map), 'path' => '/app.js'], 'nope')
            ->assertUnauthorized();

        expect(SourceMap::query()->count())->toBe(0);
    });

    it('stores the map on the default disk under the bundle\'s path', function () {
        ($this->upload)([
            'file' => UploadedFile::fake()->createWithContent('app-BxQ3kZ9a.js.map', $this->map),
            'path' => 'https://cdn.example.test/build/assets/app-BxQ3kZ9a.js?v=1',
            'release' => 'a1f3c9e',
        ])->assertCreated()->assertJson(['path' => '/build/assets/app-BxQ3kZ9a.js', 'release' => 'a1f3c9e', 'size' => strlen($this->map)]);

        $stored = SourceMap::sole();
        expect($stored->project_id)->toBe($this->project->id);
        Storage::assertExists($stored->filePath());
        expect($stored->filePath())->toStartWith('sourcemaps/'.$this->project->id.'/');
    });

    it('replaces an upload of the same path and release, and keeps one per release', function () {
        $fields = fn (string $content, ?string $release) => array_filter([
            'file' => UploadedFile::fake()->createWithContent('app.js.map', $content),
            'path' => '/build/assets/app.js',
            'release' => $release,
        ]);
        $replacement = str_replace('"names"', '"file": "app.js", "names"', $this->map);

        ($this->upload)($fields($this->map, null))->assertCreated();
        ($this->upload)($fields($replacement, null))->assertCreated();
        ($this->upload)($fields($this->map, 'v2'))->assertCreated();

        $unreleased = SourceMap::query()->whereNull('release')->sole();
        expect(SourceMap::query()->count())->toBe(2)
            ->and(Storage::get($unreleased->filePath()))->toBe($replacement);
    });

    it('refuses what it cannot keep', function (array $fields, string $field) {
        ($this->upload)($fields)->assertUnprocessable()->assertJsonValidationErrors($field);

        expect(SourceMap::query()->count())->toBe(0);
    })->with([
        'a file over 20 MB' => [fn () => ['file' => UploadedFile::fake()->create('app.js.map', 20 * 1024 + 1), 'path' => '/app.js'], 'file'],
        'a file that is not a source map' => [fn () => ['file' => UploadedFile::fake()->createWithContent('app.js.map', '{"version": 2}'), 'path' => '/app.js'], 'file'],
        'no file' => [fn () => ['path' => '/app.js'], 'file'],
        'no path' => [fn () => ['file' => UploadedFile::fake()->createWithContent('app.js.map', $this->map)], 'path'],
        'a path longer than its column once it starts with /' => [fn () => ['file' => UploadedFile::fake()->createWithContent('app.js.map', $this->map), 'path' => str_repeat('a', 500)], 'path'],
    ]);
});

describe('deleting a source map', function () {
    it('removes the row and the file for someone who runs the project', function () {
        ['user' => $user, 'project' => $project] = $this->memberWithProject(TeamRole::Admin);
        $map = SourceMap::factory()->for($project)->withFile($this->map)->create();

        $this->actingAs($user)->delete(route('source-maps.destroy', [$project, $map]))->assertRedirect();

        expect(SourceMap::query()->count())->toBe(0);
        Storage::assertMissing($map->filePath());
    });

    it('is refused to a member, and through another project', function () {
        ['user' => $member, 'project' => $project] = $this->memberWithProject(TeamRole::Member);
        $map = SourceMap::factory()->for($project)->withFile($this->map)->create();
        ['user' => $owner, 'project' => $other] = $this->memberWithProject();

        $this->actingAs($member)->delete(route('source-maps.destroy', [$project, $map]))->assertForbidden();
        $this->actingAs($owner)->delete(route('source-maps.destroy', [$other, $map]))->assertNotFound();

        expect(SourceMap::query()->count())->toBe(1);
        Storage::assertExists($map->filePath());
    });
});
