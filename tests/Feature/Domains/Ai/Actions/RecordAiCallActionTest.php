<?php

declare(strict_types=1);

use App\Domains\Ai\Actions\RecordAiCallAction;
use App\Domains\Ai\Enums\AiCallSource;
use App\Domains\Ai\Enums\AiCallStatus;
use App\Domains\Ai\Enums\AiOperation;
use App\Domains\Ai\Models\AiCall;
use App\Domains\Ai\Models\AiKey;
use App\Domains\Ai\Models\AiUsageDaily;
use App\Domains\Ai\Models\AiUsageHourly;
use App\Domains\Ingest\Actions\ProcessIngestBatchAction;
use App\Domains\Ingest\Jobs\ProcessIngestBatch;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Ingest\BuildsIngestPayloads;

covers(RecordAiCallAction::class);

uses(BuildsIngestPayloads::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-18 10:20:00');
    $this->project = Project::factory()->withToken('lj_srv_test-token')->create();
    $this->auth = ['Authorization' => 'Bearer lj_srv_test-token'];
    $this->sha256 = hash('sha256', 'anthropic:sk-ant-secret');
    $this->call = fn (array $call = []): array => [
        'type' => 'ai_call',
        'source' => 'laravel-ai',
        'provider' => 'anthropic',
        'driver' => 'anthropic',
        'model' => 'claude-sonnet-4-5',
        'operation' => 'text',
        'tokens' => ['input' => 1200, 'output' => 300, 'cache_read' => 800, 'cache_write' => 0, 'reasoning' => null],
        'duration_ms' => 1834.2,
        'status' => 'ok',
        'key' => ['sha256' => $this->sha256, 'label' => 'prod-primary', 'owner' => ['type' => 'tenant', 'id' => 42]],
        'actor' => ['type' => 'user', 'id' => 1337],
        'invocation_id' => 'inv-1',
        'step' => 2,
        'trace_id' => 'trace-1',
        'timestamp' => now()->getTimestamp(),
        ...$call,
    ];
});

describe('recording a call', function () {
    it('keeps the call with its tokens, key, owner, actor and envelope', function () {
        $this->postJson('/api/ingest', $this->batch([($this->call)()]), $this->auth)->assertStatus(202);

        $call = AiCall::query()->sole();

        expect($call->project_id)->toBe($this->project->id)
            ->and([$call->provider, $call->driver, $call->model])->toBe(['anthropic', 'anthropic', 'claude-sonnet-4-5'])
            ->and($call->operation)->toBe(AiOperation::Text)
            ->and($call->source)->toBe(AiCallSource::LaravelAi)
            ->and($call->status)->toBe(AiCallStatus::Ok)
            ->and([$call->input_tokens, $call->output_tokens, $call->cache_read_tokens, $call->cache_write_tokens, $call->reasoning_tokens])->toBe([1200, 300, 800, 0, null])
            ->and($call->duration_ms)->toBe(1834.2)
            ->and($call->fingerprint)->toBe(hash_hmac('sha256', $this->sha256, $this->project->ai_key_secret))
            ->and([$call->owner_type, $call->owner_id, $call->actor_type, $call->actor_id])->toBe(['tenant', '42', 'user', '1337'])
            ->and([$call->invocation_id, $call->step, $call->trace_id])->toBe(['inv-1', 2, 'trace-1'])
            ->and([$call->environment, $call->release, $call->host])->toBe(['production', 'a1f3c9e', 'web-1'])
            ->and($call->occurred_at->toDateTimeString())->toBe('2026-11-18 10:20:00');
    });

    it('never stores the hash the app sent', function () {
        $this->postJson('/api/ingest', $this->batch([($this->call)()]), $this->auth);

        foreach (['ai_calls', 'ai_keys', 'ai_usage_hourly', 'ai_usage_daily'] as $table) {
            expect(json_encode(DB::table($table)->get()))->not->toContain($this->sha256, $table);
        }
    });

    it('queues the key\'s fingerprint, never its hash, and ignores a fingerprint the app sent', function () {
        Queue::fake();

        $this->postJson('/api/ingest', $this->batch([
            ($this->call)(),
            ($this->call)(['key' => ['fingerprint' => str_repeat('a', 64)]]),
        ]), $this->auth)->assertStatus(202);

        Queue::assertPushed(ProcessIngestBatch::class, fn (ProcessIngestBatch $job): bool => ! str_contains((string) json_encode($job->payload), $this->sha256)
            && $job->payload['events'][0]['key']['fingerprint'] === hash_hmac('sha256', $this->sha256, $this->project->ai_key_secret)
            && ! isset($job->payload['events'][1]['key']['fingerprint']));
    });

    it('gives the same key a different fingerprint in another project', function () {
        Project::factory()->withToken('lj_srv_other-token')->create();

        $this->postJson('/api/ingest', $this->batch([($this->call)()]), $this->auth);
        $this->postJson('/api/ingest', $this->batch([($this->call)()]), ['Authorization' => 'Bearer lj_srv_other-token']);

        expect(AiKey::query()->pluck('fingerprint')->unique())->toHaveCount(2);
    });

    it('keeps no key for a hash that is not a SHA-256', function (mixed $sha256) {
        $this->postJson('/api/ingest', $this->batch([($this->call)(['key' => ['sha256' => $sha256, 'owner' => ['type' => 'tenant', 'id' => 42]]])]), $this->auth);

        $call = AiCall::query()->sole();

        expect($call->fingerprint)->toBeNull()
            ->and([$call->owner_type, $call->owner_id])->toBe(['tenant', '42'])
            ->and(AiKey::query()->count())->toBe(0)
            ->and(AiUsageHourly::query()->sole()->fingerprint)->toBe('');
    })->with([
        'none' => [null],
        'too short' => ['abc123'],
        'not hex' => [str_repeat('z', 64)],
        'the key itself' => ['sk-ant-secret'],
    ]);

    it('drops what is out of range instead of the call', function () {
        $this->postJson('/api/ingest', $this->batch([($this->call)([
            'operation' => 'telepathy',
            'source' => 'carrier-pigeon',
            'status' => 'maybe',
            'error' => 'Timeout',
            'tokens' => ['input' => -5, 'output' => 1.5, 'cache_read' => 'many', 'cache_write' => '12', 'reasoning' => [1]],
            'duration_ms' => -1,
            'step' => -2,
            'actor' => ['type' => 'user'],
        ])]), $this->auth)->assertStatus(202);

        $call = AiCall::query()->sole();

        expect($call->operation)->toBe(AiOperation::Text)
            ->and($call->source)->toBe(AiCallSource::Manual)
            ->and($call->status)->toBe(AiCallStatus::Error)
            ->and($call->error)->toBe('Timeout')
            ->and([$call->input_tokens, $call->output_tokens, $call->cache_read_tokens, $call->cache_write_tokens, $call->reasoning_tokens])->toBe([null, null, null, 12, null])
            ->and([$call->duration_ms, $call->step, $call->actor_type, $call->actor_id])->toBe([null, null, null, null]);
    });

    it('drops a token count no call can reach, so no total overflows', function () {
        $this->postJson('/api/ingest', $this->batch([($this->call)([
            'tokens' => ['input' => RecordAiCallAction::MAX_TOKENS + 1, 'output' => PHP_INT_MAX, 'cache_read' => RecordAiCallAction::MAX_TOKENS],
        ])]), $this->auth)->assertStatus(202);

        $call = AiCall::query()->sole();

        expect([$call->input_tokens, $call->output_tokens, $call->cache_read_tokens])->toBe([null, null, RecordAiCallAction::MAX_TOKENS]);
    });

    it('writes provider and driver in lower case', function () {
        $this->postJson('/api/ingest', $this->batch([($this->call)(['provider' => 'Anthropic', 'driver' => 'ANTHROPIC'])]), $this->auth);

        expect(AiCall::query()->sole()->only(['provider', 'driver']))->toBe(['provider' => 'anthropic', 'driver' => 'anthropic']);
    });

    it('trims owners and actors, and keeps none of only spaces', function () {
        // Straight to the action: spans over OTLP reach it without the request trimming them.
        app(ProcessIngestBatchAction::class)->execute($this->project, $this->batch([($this->call)([
            'key' => ['sha256' => $this->sha256, 'owner' => ['type' => ' tenant ', 'id' => "42\t"]],
            'actor' => ['type' => 'user', 'id' => '   '],
        ])]));

        expect(AiCall::query()->sole()->only(['owner_type', 'owner_id', 'actor_type', 'actor_id']))
            ->toBe(['owner_type' => 'tenant', 'owner_id' => '42', 'actor_type' => null, 'actor_id' => null]);
    });

    it('cuts long values to what the columns hold', function () {
        $this->postJson('/api/ingest', $this->batch([($this->call)([
            'model' => str_repeat('m', 300),
            'actor' => ['type' => str_repeat('t', 300), 'id' => str_repeat('1', 300)],
            'key' => ['sha256' => $this->sha256, 'label' => str_repeat('l', 300)],
        ])]), $this->auth);

        $call = AiCall::query()->sole();

        expect(strlen($call->model))->toBe(128)
            ->and([strlen((string) $call->actor_type), strlen((string) $call->actor_id)])->toBe([128, 64])
            ->and(strlen((string) AiKey::query()->sole()->label))->toBe(255);
    });

    it('skips a call without a provider or model', function (array $call) {
        $this->postJson('/api/ingest', $this->batch([($this->call)($call), ($this->call)()]), $this->auth)->assertStatus(202);

        expect(AiCall::query()->count())->toBe(1);
    })->with([
        'no provider' => [['provider' => null]],
        'no model' => [['model' => '']],
        'a model that is a list' => [['model' => ['gpt']]],
    ]);
});

describe('keys', function () {
    it('keeps the first and last time a key was used across batches', function () {
        $this->postJson('/api/ingest', $this->batch([($this->call)(), ($this->call)(['timestamp' => now()->subDay()->getTimestamp()])]), $this->auth);
        $this->postJson('/api/ingest', $this->batch([($this->call)(['timestamp' => now()->addHour()->getTimestamp()])]), $this->auth);

        $key = AiKey::query()->sole();

        expect($key->first_seen_at->toDateTimeString())->toBe('2026-11-17 10:20:00')
            ->and($key->last_seen_at->toDateTimeString())->toBe('2026-11-18 11:20:00')
            ->and($key->uuid)->not->toBeEmpty();
    });

    it('changes the label and owner only when a call names them', function () {
        $this->postJson('/api/ingest', $this->batch([($this->call)()]), $this->auth);
        $this->postJson('/api/ingest', $this->batch([($this->call)(['key' => ['sha256' => $this->sha256]])]), $this->auth);

        expect(AiKey::query()->sole()->only(['label', 'owner_type', 'owner_id']))->toBe(['label' => 'prod-primary', 'owner_type' => 'tenant', 'owner_id' => '42']);

        $this->postJson('/api/ingest', $this->batch([($this->call)(['key' => ['sha256' => $this->sha256, 'label' => 'renamed', 'owner' => ['type' => 'tenant', 'id' => 7]]])]), $this->auth);

        expect(AiKey::query()->sole()->only(['label', 'owner_type', 'owner_id']))->toBe(['label' => 'renamed', 'owner_type' => 'tenant', 'owner_id' => '7']);
    });

    it('never touches the name given in Lumberjack', function () {
        $this->postJson('/api/ingest', $this->batch([($this->call)()]), $this->auth);
        AiKey::query()->sole()->update(['name' => 'Checkout']);

        $this->postJson('/api/ingest', $this->batch([($this->call)(['key' => ['sha256' => $this->sha256, 'label' => 'renamed']])]), $this->auth);

        expect(AiKey::query()->sole()->only(['name', 'label']))->toBe(['name' => 'Checkout', 'label' => 'renamed']);
    });
});

describe('totals', function () {
    it('adds calls up per hour and per day across batches', function () {
        $this->postJson('/api/ingest', $this->batch([($this->call)(), ($this->call)(['status' => 'error', 'tokens' => ['input' => 100]])]), $this->auth);
        $this->postJson('/api/ingest', $this->batch([($this->call)(['timestamp' => now()->subHour()->getTimestamp()])]), $this->auth);

        $hours = AiUsageHourly::query()->orderBy('hour')->get()
            ->map(fn (AiUsageHourly $h): array => [$h->hour->format('H:i'), $h->calls, $h->errors, $h->input_tokens, $h->output_tokens, $h->cache_read_tokens])->all();
        $day = AiUsageDaily::query()->sole();

        expect($hours)->toBe([
            ['09:00', 1, 0, 1200, 300, 800],
            ['10:00', 2, 1, 1300, 300, 800],
        ])
            ->and([$day->calls, $day->errors, $day->input_tokens, $day->output_tokens, $day->cache_read_tokens])->toBe([3, 1, 2500, 600, 1600])
            ->and($day->duration_ms_sum)->toEqualWithDelta(1834.2 * 3, 0.01)
            ->and([$day->owner_type, $day->owner_id, $day->actor_type, $day->actor_id])->toBe(['tenant', '42', 'user', '1337'])
            ->and($day->day->toDateString())->toBe('2026-11-18');
    });

    it('keeps a daily total per actor, and an hourly total that knows no actors', function () {
        $this->postJson('/api/ingest', $this->batch([($this->call)(), ($this->call)(['actor' => ['type' => 'user', 'id' => 7]]), ($this->call)(['actor' => null])]), $this->auth);

        expect(AiUsageHourly::query()->sole()->calls)->toBe(3)
            ->and(AiUsageDaily::query()->orderBy('actor_id')->pluck('actor_id')->all())->toBe(['', '1337', '7']);
    });

    it('keeps adding up after the calls themselves are gone', function () {
        $this->postJson('/api/ingest', $this->batch([($this->call)()]), $this->auth);
        AiCall::query()->delete();
        $this->postJson('/api/ingest', $this->batch([($this->call)()]), $this->auth);

        expect(AiUsageDaily::query()->sole()->calls)->toBe(2)
            ->and(AiUsageHourly::query()->sole()->input_tokens)->toBe(2400);
    });
});
