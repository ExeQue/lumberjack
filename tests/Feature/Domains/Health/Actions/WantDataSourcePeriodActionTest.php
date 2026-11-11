<?php

declare(strict_types=1);

use App\Domains\DataSources\Models\DataSource;
use App\Domains\DataSources\Models\DataSourceWindow;
use App\Domains\Health\Actions\ArchiveHealthCheckAction;
use App\Domains\Health\Actions\RunHealthCheckAction;
use App\Domains\Health\Actions\SaveHealthCheckAction;
use App\Domains\Health\Actions\WantDataSourcePeriodAction;
use App\Domains\Health\Enums\HealthCheckType;
use App\Domains\Health\Models\HealthCheck;

covers(WantDataSourcePeriodAction::class, ArchiveHealthCheckAction::class, SaveHealthCheckAction::class);

describe('a check that starts reading a period', function () {
    beforeEach(function () {
        $this->source = DataSource::factory()->connected()->create();
        $this->window = DataSourceWindow::factory()->forSource($this->source)->answered(42)->create([
            'fetched_at' => now()->subDay(), 'wanted_since' => now()->subDays(2),
        ]);
    });

    it('waits for the next pull of its period when it comes back from the archive', function () {
        $check = HealthCheck::factory()->for($this->source->project)
            ->monitored(HealthCheckType::DataSource, ['data_source' => $this->source->uuid, 'period' => '24h'])->create(['archived_at' => now()]);

        app(ArchiveHealthCheckAction::class)->execute($check, false);

        expect($this->window->refresh()->wanted_since->equalTo(now()->startOfSecond()))->toBeTrue();
    });

    it('judges the latest fetch again with new limits', function () {
        $this->window->update(['fetched_at' => now(), 'wanted_since' => now()->subHour()]);
        $check = HealthCheck::factory()->for($this->source->project)
            ->monitored(HealthCheckType::DataSource, ['data_source' => $this->source->uuid, 'period' => '24h', 'fail' => 100])->create(['last_result_at' => now()]);
        $attributes = ['name' => 'Orders', 'interval_minutes' => 5, 'degrade_after' => 2, 'recover_after' => 5, 'down_after' => 5];

        app(SaveHealthCheckAction::class)->execute($check->project, $check, $attributes, ['data_source' => $this->source->uuid, 'period' => '24h', 'operator' => '>', 'fail' => 10]);

        expect($check->refresh()->last_result_at)->toBeNull()
            ->and(app(RunHealthCheckAction::class)->execute($check)?->message)->toBe('The value is 42, > 10.');
    });
});
