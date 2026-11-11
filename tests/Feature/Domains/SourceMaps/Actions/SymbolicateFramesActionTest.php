<?php

declare(strict_types=1);

use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\Occurrence;
use App\Domains\Projects\Models\Project;
use App\Domains\SourceMaps\Actions\SymbolicateFramesAction;
use App\Domains\SourceMaps\Models\SourceMap;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\RateLimiter;
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

    it('maps one error with the bundles of its top frames only', function () {
        $bundles = array_map(fn (int $n): string => "https://app.example.test/build/assets/chunk-{$n}.js", range(1, SymbolicateFramesAction::MAX_MAPS_PER_ERROR + 2));

        foreach ($bundles as $bundle) {
            SourceMap::factory()->for($this->project)->withFile($this->map)->create(['path' => parse_url($bundle, PHP_URL_PATH)]);
        }

        ($this->sendBrowserError)([...$this->minifiedBrowserError(), 'stacktrace' => array_map(fn (string $bundle): array => ['file' => $bundle, 'line' => 1, 'column' => 21, 'function' => 'o', 'in_app' => true], $bundles)]);

        expect(collect(Occurrence::sole()->stacktrace)->map(fn (array $frame): bool => isset($frame['minified']))->all())
            ->toBe([...array_fill(0, SymbolicateFramesAction::MAX_MAPS_PER_ERROR, true), false, false]);
    });

    it('decodes a map once for every batch after it, in the same worker', function () {
        SourceMap::factory()->for($this->project)->withFile($this->map)->create(['path' => '/build/assets/app-BxQ3kZ9a.js']);

        ($this->sendBrowserError)($this->minifiedBrowserError());
        ($this->sendBrowserError)([...$this->minifiedBrowserError(), 'message' => 'Another']);

        expect(RateLimiter::attempts('sourcemap-decodes:'.$this->project->id))->toBe(1)
            ->and(Occurrence::query()->get()->every(fn (Occurrence $o): bool => isset($o->stacktrace[0]['minified'])))->toBeTrue();
    });

    it('decodes no more maps a minute than its budget for a project, and leaves the rest minified', function () {
        $events = [];

        foreach (range(1, SymbolicateFramesAction::MAX_DECODES_PER_MINUTE + 1) as $n) {
            $bundle = "https://app.example.test/build/assets/chunk-{$n}.js";
            SourceMap::factory()->for($this->project)->withFile($this->map)->create(['path' => parse_url($bundle, PHP_URL_PATH)]);
            $events[] = [...$this->minifiedBrowserError($bundle), 'message' => "Error {$n}"];
        }

        $this->postJson('/api/ingest/browser?key='.$this->project->public_key, [...$this->batch($events), 'release' => 'a1f3c9e'], ['Origin' => 'https://app.example.test'])->assertStatus(202);

        expect(Occurrence::query()->get()->filter(fn (Occurrence $o): bool => isset($o->stacktrace[0]['minified']))->count())->toBe(SymbolicateFramesAction::MAX_DECODES_PER_MINUTE);
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
