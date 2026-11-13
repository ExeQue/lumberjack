<?php

declare(strict_types=1);

use App\Domains\Shared\Support\Contributors;

covers(Contributors::class);

it('lists the contributors by their GitHub login, most contributions first, leaving out anything else', function () {
    expect(Contributors::all(dirname(__DIR__, 4).'/Fixtures/Shared/contributors.json'))->toBe([
        ['login' => 'ExeQue', 'url' => 'https://github.com/ExeQue', 'contributions' => 812],
        ['login' => 'someone-else', 'url' => 'https://github.com/someone-else', 'contributions' => 3],
    ]);
});

it('has nobody to show without the file', function () {
    expect(Contributors::all('/nonexistent/contributors.json'))->toBe([]);
});
