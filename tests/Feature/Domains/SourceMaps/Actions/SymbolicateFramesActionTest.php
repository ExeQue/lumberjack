<?php

declare(strict_types=1);

use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\Occurrence;
use App\Domains\Projects\Models\Project;
use App\Domains\SourceMaps\Actions\SymbolicateFramesAction;
use App\Domains\SourceMaps\Models\SourceMap;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Ingest\BuildsIngestPayloads;

covers(SymbolicateFramesAction::class);

uses(BuildsIngestPayloads::class);

beforeEach(function () {
    Storage::fake();
    $this->project = Project::factory()->create(['allowed_origins' => ['https://app.example.test']]);
    $this->map = (string) file_get_contents(base_path('tests/Fixtures/SourceMaps/app.js.map'));
    $this->sendBrowserError = fn (array $event, string $release = 'a1f3c9e') => $this->postJson(
        '/api/ingest/browser?key='.$this->project->public_key,
        [...$this->batch([$event]), 'release' => $release],
        ['Origin' => 'https://app.example.test'],
    )->assertStatus(202);
});

describe('browser frames with an uploaded map', function () {
    it('turns minified frames back into the original source', function () {
        SourceMap::factory()->for($this->project)->withFile($this->map)->create(['path' => '/build/assets/app-BxQ3kZ9a.js']);

        ($this->sendBrowserError)($this->minifiedBrowserError());

        $frames = Occurrence::sole()->stacktrace;
        expect($frames[0])->toMatchArray([
            'file' => 'node_modules/tiny-lib/index.js',
            'line' => 2,
            'column' => 5,
            // Named by the call in the frame below it.
            'function' => 'explode',
            'in_app' => false,
            'minified' => ['file' => 'https://app.example.test/build/assets/app-BxQ3kZ9a.js', 'line' => 1, 'column' => 21, 'function' => 'o'],
        ])
            ->and($frames[1])->toMatchArray(['file' => 'resources/js/app.js', 'line' => 7, 'column' => 9, 'in_app' => true])
            ->and($frames[1]['code']['start'])->toBe(1)
            ->and($frames[1]['code']['lines'][6])->toBe('        explode(order.id);')
            ->and(Issue::sole()->only(['file', 'line']))->toBe(['file' => 'node_modules/tiny-lib/index.js', 'line' => 2]);
    });

    it('groups the same error from two builds on one issue', function () {
        foreach (['app-BxQ3kZ9a', 'app-Kd81Lm2Q'] as $bundle) {
            SourceMap::factory()->for($this->project)->withFile($this->map)->create(['path' => "/build/assets/{$bundle}.js"]);
            ($this->sendBrowserError)($this->minifiedBrowserError("https://app.example.test/build/assets/{$bundle}.js"));
        }

        expect(Issue::query()->count())->toBe(1)
            ->and(Occurrence::query()->count())->toBe(2);
    });

    it('takes the map of the page\'s release over a newer upload of the same path', function () {
        SourceMap::factory()->for($this->project)->withFile($this->map)->create(['path' => '/build/assets/app-BxQ3kZ9a.js', 'release' => 'v1', 'updated_at' => now()->subDay()]);
        SourceMap::factory()->for($this->project)->withFile('{"version": 3, "sources": [], "mappings": ""}')->create(['path' => '/build/assets/app-BxQ3kZ9a.js', 'release' => 'v2']);

        ($this->sendBrowserError)($this->minifiedBrowserError(), 'v1');

        expect(Occurrence::sole()->stacktrace[1]['file'])->toBe('resources/js/app.js');
    });

    it('keeps the minified frames, and the error, when the map is broken', function () {
        Exceptions::fake();
        SourceMap::factory()->for($this->project)->withFile('{not a map')->create(['path' => '/build/assets/app-BxQ3kZ9a.js']);

        ($this->sendBrowserError)($this->minifiedBrowserError());

        expect(Occurrence::sole()->stacktrace[0])->toMatchArray(['file' => 'https://app.example.test/build/assets/app-BxQ3kZ9a.js', 'column' => 21])
            ->and(Occurrence::sole()->stacktrace[0])->not->toHaveKey('minified');
        Exceptions::assertReported(InvalidArgumentException::class);
    });

    it('leaves the frames of a bundle without a map as they came', function () {
        SourceMap::factory()->for($this->project)->withFile($this->map)->create(['path' => '/build/assets/other.js']);

        ($this->sendBrowserError)($this->minifiedBrowserError());

        expect(Occurrence::sole()->stacktrace[0])->toMatchArray(['file' => 'https://app.example.test/build/assets/app-BxQ3kZ9a.js', 'function' => 'o']);
    });

    it('never reads another project\'s maps', function () {
        SourceMap::factory()->withFile($this->map)->create(['path' => '/build/assets/app-BxQ3kZ9a.js']);

        ($this->sendBrowserError)($this->minifiedBrowserError());

        expect(Occurrence::sole()->stacktrace[0]['file'])->toBe('https://app.example.test/build/assets/app-BxQ3kZ9a.js');
    });
});
