<?php

declare(strict_types=1);

use App\Domains\Administration\Models\SystemSample;
use App\Domains\Administration\Queries\SystemStatusQuery;
use App\Domains\Shared\Enums\Period;
use Tests\Support\Administration\FakesSystemProbe;

covers(SystemStatusQuery::class);

uses(FakesSystemProbe::class);

beforeEach(function () {
    $this->travelTo('2026-11-15 12:30:00');
    $this->fakeSystem();
});

describe('the samples over a period', function () {
    it('averages the load per core and takes the highest memory and disk of each bucket', function () {
        SystemSample::factory()->load(0.2, 0.5, 0.4)->create(['sampled_at' => '2026-11-15 11:05:00']);
        SystemSample::factory()->load(0.6, 0.7, 0.41)->create(['sampled_at' => '2026-11-15 11:55:00']);

        $charts = app(SystemStatusQuery::class)->charts(Period::Day);
        $at = fn (string $figure): array => collect($charts[$figure])->firstWhere('t', '2026-11-15T11:00:00Z');

        expect($at('cpu')['v'])->toBe(0.4)
            ->and($at('memory')['v'])->toBe(0.7)
            ->and($at('disk')['v'])->toBe(0.41);
    });

    it('leaves a bucket without samples empty instead of at zero', function () {
        SystemSample::factory()->create(['sampled_at' => '2026-11-15 12:10:00']);

        $cpu = app(SystemStatusQuery::class)->charts(Period::Day)['cpu'];

        expect($cpu)->toHaveCount(25)
            ->and(end($cpu))->toBe(['t' => '2026-11-15T12:00:00Z', 'v' => 0.2])
            ->and($cpu[0])->toBe(['t' => '2026-11-14T12:00:00Z', 'v' => null]);
    });
});

describe('what is read as the page loads', function () {
    it('gives the newest sample', function () {
        SystemSample::factory()->create(['sampled_at' => now()->subMinutes(5), 'cpu_load_1m' => 3]);
        SystemSample::factory()->create(['sampled_at' => now()->subMinute(), 'cpu_load_1m' => 1]);

        expect(app(SystemStatusQuery::class)->latest()['cpu_load_1m'])->toBe(1.0);
    });

    it('lists the largest tables under the name of the data they keep', function () {
        $database = app(SystemStatusQuery::class)->database();
        $tables = collect($database['tables']);

        expect($database['bytes'])->toBeGreaterThan(0)
            ->and($tables)->not->toBeEmpty()
            ->and($tables->pluck('bytes')->all())->toBe($tables->pluck('bytes')->sortDesc()->values()->all());
    });
});
