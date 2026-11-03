<?php

declare(strict_types=1);

use App\Domains\Alerts\Actions\CheckQueueBacklogsAction;
use App\Domains\Alerts\Actions\SetQueueAlertThresholdAction;
use App\Domains\Alerts\Data\QueueBacklogSettings;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Enums\ChannelType;
use App\Domains\Alerts\Http\Controllers\QueueAlertThresholdController;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Alerts\Models\QueueAlertThreshold;
use App\Domains\Jobs\Models\QueueSample;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Illuminate\Support\Facades\Http;

covers(QueueBacklogSettings::class, SetQueueAlertThresholdAction::class, QueueAlertThresholdController::class);

beforeEach(function () {
    Http::fake(['*' => Http::response(null, 204)]);
    $this->project = Project::factory()->create(['name' => 'Shop']);
    $this->rule = AlertRule::factory()->for($this->project)->create(['type' => AlertRuleType::QueueBacklog, 'settings' => ['size' => 100, 'age_minutes' => 30, 'sustain_minutes' => 5]]);
    $this->rule->channels()->attach(NotificationChannel::factory()->for($this->project->team)->create(['type' => ChannelType::Discord, 'config' => ['webhook_url' => 'https://discord.com/api/webhooks/1/x']]));
    $this->own = fn (string $queue, array $fields) => app(SetQueueAlertThresholdAction::class)->execute($this->project, 'redis', $queue, $fields['size'] ?? null, $fields['oldest'] ?? null, $fields['sustained'] ?? null, $fields['muted'] ?? false);
    // One check a minute with these sizes reported just before, per queue.
    $this->minutes = function (int $count, array $sizes): void {
        foreach (range(1, $count) as $i) {
            $this->travel(1)->minutes();

            foreach ($sizes as $queue => $size) {
                QueueSample::query()->create(['project_id' => $this->project->id, 'host' => 'web-1', 'connection' => 'redis', 'queue' => $queue, 'size' => $size, 'oldest_age_s' => 60, 'sampled_at' => now()]);
            }

            app(CheckQueueBacklogsAction::class)->execute();
        }
    };
    $this->alerted = fn (): array => Http::recorded()->map(fn (array $pair) => $pair[0]['embeds'][0]['description'] ?? '')->all();
});

describe('per queue thresholds', function () {
    it('uses a queue\'s own threshold for that queue only, and names it in the alert', function () {
        ($this->own)('emails', ['size' => 500]);

        ($this->minutes)(7, ['emails' => 300, 'default' => 300]);

        $sent = Http::recorded();
        expect($sent)->toHaveCount(1)
            ->and(collect($sent[0][0]['embeds'][0]['fields'])->pluck('value', 'name')->get('Threshold'))->toBe('more than 100 jobs or the oldest waiting over 30 minutes, for 5 minutes');

        ($this->minutes)(7, ['emails' => 600, 'default' => 50]);

        expect(Http::recorded()->filter(fn (array $pair) => str_contains(json_encode($pair[0]['embeds'][0]), 'redis:emails'))->map(fn (array $pair) => collect($pair[0]['embeds'][0]['fields'])->pluck('value', 'name')->get('Threshold'))->values()->all())
            ->toBe(['more than 500 jobs or the oldest waiting over 30 minutes, for 5 minutes (this queue)']);
    });

    it('falls back to the rule per field', function () {
        ($this->own)('emails', ['sustained' => 1]);
        $settings = QueueBacklogSettings::forQueue($this->rule, $this->project, 'redis', 'emails');

        expect([$settings->size, $settings->ageMinutes, $settings->sustainMinutes, $settings->custom])->toBe([100, 30, 1, true]);

        ($this->minutes)(2, ['emails' => 300]);

        expect(Http::recorded())->toHaveCount(1);
    });

    it('never alerts for a muted queue', function () {
        ($this->own)('emails', ['muted' => true]);

        ($this->minutes)(10, ['emails' => 5000]);

        Http::assertNothingSent();
    });

    it('forgets a queue\'s own thresholds when every field is empty again', function () {
        ($this->own)('emails', ['size' => 5]);
        ($this->own)('emails', []);

        expect(QueueAlertThreshold::query()->count())->toBe(0);
    });
});

describe('setting them', function () {
    it('lets whoever may change the project set them, from the Jobs page', function () {
        $admin = User::factory()->inTeam($this->project->team, TeamRole::Admin)->create();

        $this->actingAs($admin)->put(route('queues.alert', $this->project), ['connection' => 'redis', 'queue' => 'emails', 'size' => 500, 'oldest_minutes' => null, 'sustained_minutes' => '', 'muted' => false])
            ->assertRedirect();

        expect(QueueAlertThreshold::query()->sole()->only(['queue', 'size', 'oldest_minutes', 'sustained_minutes', 'muted']))
            ->toBe(['queue' => 'emails', 'size' => 500, 'oldest_minutes' => null, 'sustained_minutes' => null, 'muted' => false]);

        $this->actingAs($admin)->get(route('jobs.index', $this->project))
            ->assertInertia(fn ($page) => $page->where('queue_alerts.own.0.size', 500)->where('queue_alerts.can_update', true)->where('queue_alerts.rule.size', 100));
        $this->actingAs($admin)->get(route('projects.settings', $this->project))
            ->assertInertia(fn ($page) => $page->where('alerts.queue_thresholds.0.queue', 'emails'));
    });

    it('refuses anyone who may not change the project', function () {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->put(route('queues.alert', $this->project), ['connection' => 'redis', 'queue' => 'emails', 'size' => 5, 'muted' => false])->assertForbidden();

        expect(QueueAlertThreshold::query()->count())->toBe(0);
    });
});
