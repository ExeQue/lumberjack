<?php

declare(strict_types=1);

use App\Domains\DataSources\Models\DataSource;
use App\Domains\DataSources\Models\DataSourceWindow;
use App\Domains\DataSources\Queries\ConnectedDataSourceQuery;
use App\Domains\Shared\Enums\Period;

covers(ConnectedDataSourceQuery::class);

describe('wanting a period', function () {
    beforeEach(function () {
        $this->source = DataSource::factory()->connected()->create();
    });

    it('leaves a period that is kept pulled as it is', function () {
        $window = DataSourceWindow::factory()->forSource($this->source)->answered(42)->create([
            'period' => '24h', 'bucket' => 3600, 'wanted_since' => now()->subHour(), 'next_fetch_at' => now()->addMinutes(3),
        ]);

        app(ConnectedDataSourceQuery::class)->want($this->source, Period::Day);

        expect($window->refresh())
            ->wanted_since->toEqual(now()->subHour()->startOfSecond())
            ->next_fetch_at->not->toBeNull();
    });

    it('wants one nobody kept pulled from now, pulled on the next minute without its old error', function () {
        $window = DataSourceWindow::factory()->forSource($this->source)->create([
            'period' => '24h', 'bucket' => 3600, 'fetched_at' => now()->subHours(3), 'wanted_since' => now()->subHours(4),
            'error' => 'Timed out', 'failures' => 3, 'next_fetch_at' => now()->addHour(),
        ]);

        app(ConnectedDataSourceQuery::class)->want($this->source, Period::Day);

        expect($window->refresh())
            ->wanted_since->toEqual(now()->startOfSecond())
            ->next_fetch_at->toBeNull()
            ->error->toBeNull()
            ->failures->toBe(0);
    });
});
