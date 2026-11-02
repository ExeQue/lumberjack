<?php

declare(strict_types=1);

/**
 * The Docker docs show compose.yaml and .env.docker.example in full, so people
 * can copy them without access to the repository. This keeps the copies true.
 */
describe('the Docker docs', function () {
    test('show the files they copy exactly as they are', function (string $file) {
        $docs = (string) file_get_contents(base_path('docs/docker.md'));

        // The block after the "<!-- file -->" marker, indented as a list item.
        expect(preg_match('/<!-- '.preg_quote($file, '/').' -->\s*```\w+\n(.*?)\n\s*```/s', $docs, $match))->toBe(1, "docs/docker.md has no block for {$file}");

        $shown = preg_replace('/^ {4}/m', '', $match[1]);

        expect($shown)->toBe(rtrim((string) file_get_contents(base_path($file))), "docs/docker.md shows an old {$file}: copy it in again");
    })->with(['compose.yaml', '.env.docker.example']);
});
