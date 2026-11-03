<?php

declare(strict_types=1);

use App\Domains\Health\Actions\RecordHealthResultAction;
use App\Domains\Health\Data\HealthResultData;
use App\Domains\Health\Enums\HealthResultStatus;
use App\Domains\Health\Enums\HealthStatus;
use App\Domains\Health\Http\Controllers\HealthStatesController;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Health\Models\HealthCheckHost;
use App\Domains\Health\Queries\HealthChecksQuery;
use App\Domains\Projects\Models\Project;

covers(HealthStatesController::class, HealthChecksQuery::class);

beforeEach(function () {
    $this->freezeTime();
    $this->project = Project::factory()->withToken('lj_srv_test-token')->create();
    $this->auth = ['Authorization' => 'Bearer lj_srv_test-token'];
    $this->check = function (string $slug, string $environment, array $hosts, array $attributes = []): HealthCheck {
        $check = HealthCheck::factory()->for($this->project)->create(['slug' => $slug, 'environment' => $environment, ...$attributes]);

        foreach ($hosts as $host => [$status, $minutesAgo]) {
            HealthCheckHost::query()->create([
                'health_check_id' => $check->id, 'host' => $host, 'status' => $status, 'interval_s' => 60,
                'last_message' => "{$host} says {$status->value}", 'last_result_at' => now()->subMinutes($minutesAgo),
            ]);
        }

        return $check;
    };
});

describe('reading health states with the server token', function () {
    it('answers 401 without a token or with an unknown one', function () {
        $this->getJson('/api/health')->assertUnauthorized();
        $this->getJson('/api/health', ['Authorization' => 'Bearer nope'])->assertUnauthorized();
    });

    it('lists the reported checks with their state, since, last message and hosts when there are several', function () {
        ($this->check)('database:pgsql', 'production', ['web-1' => [HealthStatus::Ok, 1]], ['status' => HealthStatus::Ok, 'status_changed_at' => now()->subHour()]);
        ($this->check)('disk:s3', 'production', ['web-1' => [HealthStatus::Ok, 2], 'web-2' => [HealthStatus::Down, 1]], ['status' => HealthStatus::Down, 'status_changed_at' => now()->subMinutes(5)]);
        HealthCheck::factory()->for($this->project)->monitored()->create();
        ($this->check)('old', 'production', [], ['archived_at' => now()]);

        $this->getJson('/api/health', $this->auth)->assertOk()->assertExactJson([
            'checks' => [
                ['slug' => 'database:pgsql', 'environment' => 'production', 'state' => 'ok', 'since' => now()->subHour()->toIso8601String(), 'message' => 'web-1 says ok'],
                ['slug' => 'disk:s3', 'environment' => 'production', 'state' => 'down', 'since' => now()->subMinutes(5)->toIso8601String(), 'message' => 'web-2 says down', 'hosts' => [
                    ['host' => 'web-1', 'state' => 'ok'],
                    ['host' => 'web-2', 'state' => 'down'],
                ]],
            ],
            'at' => now()->toIso8601String(),
        ]);
    });

    it('filters by environment and slug', function () {
        ($this->check)('database:pgsql', 'production', ['web-1' => [HealthStatus::Ok, 1]]);
        ($this->check)('database:pgsql', 'staging', ['web-1' => [HealthStatus::Ok, 1]]);
        ($this->check)('disk:s3', 'staging', ['web-1' => [HealthStatus::Ok, 1]]);

        expect($this->getJson('/api/health?environment=staging', $this->auth)->json('checks.*.slug'))->toBe(['database:pgsql', 'disk:s3'])
            ->and($this->getJson('/api/health?environment=staging&slug=disk:s3', $this->auth)->json('checks.*.environment'))->toBe(['staging'])
            ->and($this->getJson('/api/health', $this->auth)->json('checks'))->toHaveCount(3);
    });

    it('shows a check whose hosts went quiet as stale, since its last result', function () {
        ($this->check)('database:pgsql', 'production', ['web-1' => [HealthStatus::Ok, 10]], ['status' => HealthStatus::Ok, 'last_result_at' => now()->subMinutes(10)]);

        $this->getJson('/api/health', $this->auth)->assertOk()
            ->assertJsonPath('checks.0.state', 'stale')
            ->assertJsonPath('checks.0.since', now()->subMinutes(10)->toIso8601String());
    });

    it('serves from a short cache that a result moving a check clears', function () {
        $check = ($this->check)('database:pgsql', 'production', []);
        $this->getJson('/api/health', $this->auth)->assertJsonPath('checks.0.state', 'pending');

        HealthCheckHost::query()->create(['health_check_id' => $check->id, 'host' => 'web-1', 'status' => HealthStatus::Ok, 'last_result_at' => now()]);
        $this->getJson('/api/health', $this->auth)->assertJsonPath('checks.0.state', 'pending');

        app(RecordHealthResultAction::class)->execute($check, new HealthResultData(HealthResultStatus::Failed, host: 'web-1'));
        $this->getJson('/api/health', $this->auth)->assertJsonPath('checks.0.state', 'ok');
    });
});
