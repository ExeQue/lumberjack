<?php

declare(strict_types=1);

use App\Domains\Alerts\Actions\CheckQueueBacklogsAction;
use App\Domains\Alerts\Actions\SendQueueBacklogAlertAction;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Enums\ChannelType;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Jobs\Models\QueueSample;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Enums\TeamRole;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Teams\CreatesTeamMembers;

covers(CheckQueueBacklogsAction::class, SendQueueBacklogAlertAction::class);

uses(CreatesTeamMembers::class);

describe('queue backlog alerts', function () {
    beforeEach(function () {
        Http::fake(['*' => Http::response(null, 204)]);
        $this->project = Project::factory()->create(['name' => 'Shop']);
        $this->rule = AlertRule::factory()->for($this->project)->create(['type' => AlertRuleType::QueueBacklog, 'settings' => ['size' => 100, 'age_minutes' => null, 'sustain_minutes' => 5]]);
        $this->rule->channels()->attach(NotificationChannel::factory()->for($this->project->team)->create(['type' => ChannelType::Discord, 'config' => ['webhook_url' => 'https://discord.com/api/webhooks/1/x']]));
        // One check a minute, as the schedule runs it, with this size reported just before.
        $this->minute = function (int $size, string $host = 'web-1'): void {
            $this->travel(1)->minutes();
            QueueSample::query()->create(['project_id' => $this->project->id, 'host' => $host, 'connection' => 'redis', 'queue' => 'default', 'size' => $size, 'oldest_age_s' => 600, 'sampled_at' => now()]);
            app(CheckQueueBacklogsAction::class)->execute();
        };
        $this->titles = fn (): array => Http::recorded()->map(fn (array $pair) => $pair[0]['embeds'][0]['title'])->all();
    });

    it('alerts once a backlog has lasted, once when it clears, and not again within the throttle', function () {
        foreach ([50, 150, 200, 250, 300, 350] as $size) {
            ($this->minute)($size);
        }

        expect(($this->titles)())->toBe([]);

        ($this->minute)(400);
        ($this->minute)(500);

        expect(($this->titles)())->toBe(['Queue backlog in Shop']);

        ($this->minute)(20);
        ($this->minute)(20);

        expect(($this->titles)())->toBe(['Queue backlog in Shop', 'Queue backlog cleared in Shop']);

        // Flapping back over within 30 minutes of the last alert stays quiet.
        foreach (range(1, 10) as $i) {
            ($this->minute)(500);
        }

        expect(($this->titles)())->toHaveCount(2);
    });

    it('reads the latest sample across hosts, not their sum', function () {
        foreach (range(1, 8) as $i) {
            ($this->minute)(60, 'web-1');
            ($this->minute)(60, 'web-2');
        }

        Http::assertNothingSent();
    });

    it('fires when workers are dead: the oldest pending job only waits longer', function () {
        $this->rule->update(['settings' => ['size' => null, 'age_minutes' => 5, 'sustain_minutes' => 1]]);

        ($this->minute)(3);
        ($this->minute)(3);
        ($this->minute)(3);

        Http::assertSent(fn (Request $request) => $request['embeds'][0]['title'] === 'Queue backlog in Shop'
            && collect($request['embeds'][0]['fields'])->pluck('value', 'name')->get('Oldest pending') === '10 minutes');
    });

    it('saves the thresholds of the rule', function () {
        ['user' => $owner] = $this->memberWithProject(TeamRole::Owner);
        $rule = AlertRule::factory()->for(Project::factory()->for($owner->currentTeam))->create(['type' => AlertRuleType::QueueBacklog]);

        $this->actingAs($owner)->put(route('alerts.rules.update', $rule), [
            'enabled' => true, 'channels' => [], 'environments' => null, 'settings' => ['size' => 5000, 'age_minutes' => null, 'sustain_minutes' => 15],
        ])->assertSessionHasNoErrors();

        expect($rule->refresh()->settings)->toBe(['size' => 5000, 'age_minutes' => null, 'sustain_minutes' => 15]);

        $this->actingAs($owner)->put(route('alerts.rules.update', $rule), [
            'enabled' => true, 'channels' => [], 'settings' => ['size' => null, 'age_minutes' => null, 'sustain_minutes' => 5],
        ])->assertSessionHasErrors('settings.size');
    });
});
