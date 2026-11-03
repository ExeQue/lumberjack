<?php

declare(strict_types=1);

use App\Domains\Health\Actions\ArchiveSilentHealthChecksAction;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Health\Models\HealthCheckHost;
use Illuminate\Support\Facades\Artisan;

covers(ArchiveSilentHealthChecksAction::class, HealthCheckHost::class);

describe('health cleanup', function () {
    it('archives reported checks silent for a week, and leaves checks Lumberjack runs', function () {
        $silent = HealthCheck::factory()->create(['last_result_at' => now()->subDays(8)]);
        $neverReported = HealthCheck::factory()->create(['slug' => 'cache:redis', 'created_at' => now()->subDays(8)]);
        $recent = HealthCheck::factory()->create(['slug' => 'redis:default', 'last_result_at' => now()->subDay()]);
        $monitored = HealthCheck::factory()->monitored()->create(['last_result_at' => now()->subDays(30)]);

        expect(app(ArchiveSilentHealthChecksAction::class)->execute())->toBe(2)
            ->and($silent->refresh()->archived_at)->not->toBeNull()
            ->and($neverReported->refresh()->archived_at)->not->toBeNull()
            ->and($recent->refresh()->archived_at)->toBeNull()
            ->and($monitored->refresh()->archived_at)->toBeNull();
    });

    it('forgets hosts of reported checks after a day of silence', function () {
        $check = HealthCheck::factory()->create();
        $gone = $check->hosts()->create(['host' => 'pod-a', 'last_result_at' => now()->subHours(25)]);
        $live = $check->hosts()->create(['host' => 'pod-b', 'last_result_at' => now()->subMinute()]);
        $own = HealthCheck::factory()->monitored()->create()->hosts()->create(['host' => '', 'last_result_at' => now()->subDays(3)]);

        Artisan::call('model:prune', ['--model' => [HealthCheckHost::class]]);

        expect(HealthCheckHost::query()->pluck('id')->all())->toEqualCanonicalizing([$live->id, $own->id])
            ->and($gone->exists && HealthCheckHost::query()->whereKey($gone->id)->exists())->toBeFalse();
    });
});
