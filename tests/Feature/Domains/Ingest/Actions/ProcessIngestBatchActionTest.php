<?php

declare(strict_types=1);

use App\Domains\Ai\Models\AiCall;
use App\Domains\Ai\Models\AiUsageHourly;
use App\Domains\Ingest\Actions\ProcessIngestBatchAction;
use App\Domains\Projects\Models\Project;
use App\Domains\Releases\Actions\RecordReleaseAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\Ingest\BuildsIngestPayloads;

covers(ProcessIngestBatchAction::class);

uses(BuildsIngestPayloads::class);

describe('a retried batch', function () {
    it('counts its AI calls once when it failed before they were written', function () {
        Carbon::setTestNow('2026-11-18 10:20:00');
        $project = Project::factory()->create();
        $failures = 1;
        $this->mock(RecordReleaseAction::class)->shouldReceive('execute')->andReturnUsing(function () use (&$failures): void {
            if ($failures-- > 0) {
                throw new RuntimeException('Database went away');
            }
        });
        $batch = $this->batch([['type' => 'ai_call', 'provider' => 'openai', 'model' => 'gpt-5', 'tokens' => ['input' => 100, 'output' => 10]]]);

        expect(fn () => app(ProcessIngestBatchAction::class)->execute($project, $batch))->toThrow(RuntimeException::class);
        app(ProcessIngestBatchAction::class)->execute($project, $batch);

        expect(AiCall::query()->count())->toBe(1)
            ->and(AiUsageHourly::query()->sole()->only(['calls', 'input_tokens']))->toBe(['calls' => 1, 'input_tokens' => 100]);
    });

    it('counts its AI calls once when it was retried after they were written', function () {
        Carbon::setTestNow('2026-11-18 10:20:00');
        $project = Project::factory()->create();
        $batch = $this->batch([['type' => 'ai_call', 'provider' => 'openai', 'model' => 'gpt-5', 'tokens' => ['input' => 100, 'output' => 10]]]);

        [$job, $other] = [(string) Str::uuid(), (string) Str::uuid()];

        // A worker killed after the write, before the job was acknowledged, runs it again.
        app(ProcessIngestBatchAction::class)->execute($project, $batch, batch: $job);
        app(ProcessIngestBatchAction::class)->execute($project, $batch, batch: $job);
        app(ProcessIngestBatchAction::class)->execute($project, $batch, batch: $other);

        expect(AiCall::query()->count())->toBe(2)
            ->and(AiUsageHourly::query()->sole()->calls)->toBe(2);
    });
});
