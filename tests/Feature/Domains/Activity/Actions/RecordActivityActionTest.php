<?php

declare(strict_types=1);

use App\Domains\Activity\Actions\RecordActivityAction;
use App\Domains\Activity\Models\Activity;
use App\Domains\Activity\Models\ActivityCount;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Carbon;
use Tests\Support\Ingest\BuildsIngestPayloads;

covers(RecordActivityAction::class);

uses(BuildsIngestPayloads::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-13 10:20:00');
    $this->project = Project::factory()->withToken('lj_srv_test-token')->create(['scrub_keys' => ['card']]);
    $this->auth = ['Authorization' => 'Bearer lj_srv_test-token'];
    $this->event = fn (array $event = []): array => ['type' => 'event', 'name' => 'order.placed', 'timestamp' => now()->getTimestamp(), ...$event];
});

describe('recording an event', function () {
    it('keeps the event with its group, actor, subject, payload and envelope', function () {
        $this->postJson('/api/ingest', $this->batch([($this->event)([
            'group' => 'Billing',
            'actor' => ['type' => 'App\\Models\\User', 'id' => 17],
            'subject' => ['type' => 'order', 'id' => '42'],
            'payload' => ['total' => 499, 'card' => '4242'],
            'trace_id' => 'trace-1',
        ])]), $this->auth)->assertStatus(202);

        $event = Activity::query()->sole();

        expect($event->project_id)->toBe($this->project->id)
            ->and($event->group)->toBe('billing')
            ->and($event->name)->toBe('order.placed')
            ->and([$event->actor_type, $event->actor_id])->toBe(['App\\Models\\User', '17'])
            ->and([$event->subject_type, $event->subject_id])->toBe(['order', '42'])
            ->and($event->payload['total'])->toBe(499)
            ->and($event->payload['card'])->not->toBe('4242')
            ->and($event->trace_id)->toBe('trace-1')
            ->and([$event->environment, $event->release, $event->host])->toBe(['production', 'a1f3c9e', 'web-1']);
    });

    it('puts an event without a group in the default group, and without an actor keeps none', function () {
        $this->postJson('/api/ingest', $this->batch([($this->event)(['actor' => ['type' => 'user']])]), $this->auth);

        $event = Activity::query()->sole();

        expect($event->group)->toBe(Activity::DEFAULT_GROUP)
            ->and($event->actor_type)->toBeNull()
            ->and($event->actor_id)->toBeNull();
    });

    it('skips an event without a usable name', function (mixed $name) {
        $this->postJson('/api/ingest', $this->batch([($this->event)(['name' => $name]), ($this->event)()]), $this->auth)->assertStatus(202);

        expect(Activity::query()->pluck('name')->all())->toBe(['order.placed']);
    })->with([
        'none' => [null],
        'empty' => [''],
        'with spaces' => ['order placed'],
        'an array' => [['order']],
    ]);

    it('replaces a payload too large to keep with a marker', function () {
        $this->postJson('/api/ingest', $this->batch([($this->event)(['payload' => ['blob' => str_repeat('x', RecordActivityAction::MAX_PAYLOAD_BYTES)]])]), $this->auth);

        expect(Activity::query()->sole()->payload)->toBe(RecordActivityAction::TRUNCATED);
    });
});

describe('hourly counts', function () {
    it('counts events per hour, group, name and environment across batches', function () {
        $earlier = now()->subHour()->getTimestamp();

        $this->postJson('/api/ingest', $this->batch([($this->event)(), ($this->event)(), ($this->event)(['timestamp' => $earlier])]), $this->auth);
        $this->postJson('/api/ingest', $this->batch([($this->event)(), ($this->event)(['group' => 'other'])]), $this->auth);
        $this->postJson('/api/ingest', $this->batch([($this->event)()], 'staging'), $this->auth);

        $counts = ActivityCount::query()->orderBy('hour')->orderBy('group')->orderBy('environment')->get()
            ->map(fn (ActivityCount $c): array => [$c->hour->format('H:i'), $c->group, $c->environment, $c->count])->all();

        expect($counts)->toBe([
            ['09:00', 'default', 'production', 1],
            ['10:00', 'default', 'production', 3],
            ['10:00', 'default', 'staging', 1],
            ['10:00', 'other', 'production', 1],
        ]);
    });

    it('keeps counting after the events themselves are gone', function () {
        $this->postJson('/api/ingest', $this->batch([($this->event)()]), $this->auth);
        Activity::query()->delete();
        $this->postJson('/api/ingest', $this->batch([($this->event)()]), $this->auth);

        expect(ActivityCount::query()->sole()->count)->toBe(2);
    });
});
