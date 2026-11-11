<?php

declare(strict_types=1);

use App\Domains\Shared\Support\Changelog;

covers(Changelog::class);

it('splits the changelog by release, leaving out an empty Unreleased and the link definitions', function () {
    expect(Changelog::releases(dirname(__DIR__, 4).'/Fixtures/Shared/CHANGELOG.md'))->toBe([
        ['version' => '0.2.0', 'date' => '2026-11-10', 'notes' => "### Added\n\n- A **thing**."],
        ['version' => '0.1.0', 'date' => '2026-11-01', 'notes' => '- First.'],
    ]);
});

it('has nothing to show without the file', function () {
    expect(Changelog::releases('/nonexistent/CHANGELOG.md'))->toBe([]);
});
