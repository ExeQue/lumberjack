<?php

declare(strict_types=1);

use App\Domains\Jobs\Actions\MarkStuckJobsAction;
use App\Domains\Jobs\Enums\JobRunStatus;
use App\Domains\Jobs\Models\JobRun;

covers(MarkStuckJobsAction::class);

describe('stuck detection', function () {
    it('marks a run processing past its timeout plus margin', function () {
        $stuck = JobRun::factory()->processing()->create(['timeout' => 60, 'started_at' => now()->subSeconds(60 + JobRun::STUCK_MARGIN_SECONDS + 5)]);
        $fine = JobRun::factory()->processing()->create(['timeout' => 600, 'started_at' => now()->subSeconds(300)]);

        expect(app(MarkStuckJobsAction::class)->execute())->toBe(1)
            ->and($stuck->refresh()->status)->toBe(JobRunStatus::Stuck)
            ->and($fine->refresh()->status)->toBe(JobRunStatus::Processing);
    });
});
