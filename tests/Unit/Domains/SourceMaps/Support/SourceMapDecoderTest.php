<?php

declare(strict_types=1);

use App\Domains\SourceMaps\Support\SourceMapDecoder;

covers(SourceMapDecoder::class);

// tests/Fixtures/SourceMaps/app.js.map is esbuild's minified build of a two-file app; the
// expected positions are what @jridgewell/trace-mapping reads from it.
it('finds where a position in the bundle came from', function (int $column, ?array $expected) {
    $map = SourceMapDecoder::decode((string) file_get_contents(dirname(__DIR__, 4).'/Fixtures/SourceMaps/app.js.map'));
    $found = $map->lookup(1, $column);

    expect($found === null ? null : array_diff_key($found, ['content' => true]))->toBe($expected);
})->with([
    'the throw in a dependency' => [21, ['source' => '../../node_modules/tiny-lib/index.js', 'line' => 2, 'column' => 5, 'name' => null]],
    'a call with its name' => [128, ['source' => '../../resources/js/app.js', 'line' => 7, 'column' => 9, 'name' => 'explode']],
    'the start of a segment' => [138, ['source' => '../../resources/js/app.js', 'line' => 13, 'column' => 1, 'name' => null]],
    'past the last segment' => [999, ['source' => '../../resources/js/app.js', 'line' => 13, 'column' => 20, 'name' => 'saveOrder']],
    'before the first segment' => [1, null],
]);

it('hands out the original source with a position', function () {
    $map = SourceMapDecoder::decode((string) file_get_contents(dirname(__DIR__, 4).'/Fixtures/SourceMaps/app.js.map'));

    expect($map->lookup(1, 128)['content'] ?? '')->toContain('function saveOrder(order)')
        ->and($map->lookup(2, 1))->toBeNull();
});

it('keeps the place of a null source, which mappings point at by index', function () {
    $map = SourceMapDecoder::decode('{"version": 3, "sources": [null, "b.js"], "sourcesContent": [null, "const b = 1;"], "names": [null, "b"], "mappings": "ACAAC"}');

    expect($map->lookup(1, 1))->toBe(['source' => 'b.js', 'line' => 1, 'column' => 1, 'name' => 'b', 'content' => 'const b = 1;']);
});

it('finds nothing in a null source, so the minified frame stays', function () {
    $map = SourceMapDecoder::decode('{"version": 3, "sources": [null], "mappings": "AAAA"}');

    expect($map->lookup(1, 1))->toBeNull();
});

it('refuses what is not a version 3 source map', function (string $json) {
    SourceMapDecoder::decode($json);
})->with([
    'not JSON' => ['{nope'],
    'version 2' => ['{"version": 2, "sources": [], "mappings": ""}'],
    'no mappings' => ['{"version": 3, "sources": []}'],
    'a character outside Base64' => ['{"version": 3, "sources": ["a.js"], "mappings": "AA!A"}'],
])->throws(InvalidArgumentException::class);
