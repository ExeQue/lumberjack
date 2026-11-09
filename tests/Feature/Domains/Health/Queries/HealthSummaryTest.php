<?php

declare(strict_types=1);

use App\Domains\Health\Enums\HealthStatus;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Health\Queries\HealthChecksQuery;
use App\Domains\Projects\Models\Project;

covers(HealthChecksQuery::class);

describe('the health summary', function () {
    it('lists every check worst first, then by name, for the overview', function () {
        $project = Project::factory()->create();
        $check = function (string $slug, HealthStatus $status) use ($project): void {
            HealthCheck::factory()->create(['project_id' => $project->id, 'slug' => $slug])
                ->hosts()->create(['host' => 'web-1', 'status' => $status, 'last_message' => $slug.' says hi', 'last_result_at' => now()]);
        };

        $check('cache:redis', HealthStatus::Ok);
        $check('disk:s3', HealthStatus::Down);
        $check('database:pgsql', HealthStatus::Ok);
        $check('queue:default', HealthStatus::Degraded);

        $summary = app(HealthChecksQuery::class)->summary($project);

        expect(array_column($summary['checks'], 'state'))->toBe(['down', 'degraded', 'ok', 'ok'])
            ->and(array_column($summary['checks'], 'name'))->toBe(['Disk: S3', 'Queue: Default', 'Cache: Redis', 'Database: PostgreSQL'])
            ->and($summary['checks'][0]['message'])->toBe('disk:s3 says hi')
            ->and($summary['down'])->toBe(1);
    });
});
