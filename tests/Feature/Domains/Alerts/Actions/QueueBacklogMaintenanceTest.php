<?php

declare(strict_types=1);

use App\Domains\Alerts\Actions\CheckQueueBacklogsAction;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Enums\ChannelType;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Jobs\Models\QueueSample;
use App\Domains\Maintenance\Actions\EndMaintenanceWindowAction;
use App\Domains\Maintenance\Models\MaintenanceWindow;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

covers(CheckQueueBacklogsAction::class);

describe('queue backlogs under maintenance', function () {
    beforeEach(function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00'));
        Http::fake(['*' => Http::response(null, 204)]);
        $this->project = Project::factory()->create(['name' => 'Shop']);
        $rule = AlertRule::factory()->for($this->project)->create(['type' => AlertRuleType::QueueBacklog, 'settings' => ['size' => 100, 'age_minutes' => null, 'sustain_minutes' => 5]]);
        $rule->channels()->attach(NotificationChannel::factory()->for($this->project->team)->create(['type' => ChannelType::Discord, 'config' => ['webhook_url' => 'https://discord.com/api/webhooks/1/x']]));
        // One check a minute, as the schedule runs it, with this size reported just before.
        $this->minute = function (int $size): void {
            $this->travel(1)->minutes();
            QueueSample::query()->create(['project_id' => $this->project->id, 'host' => 'web-1', 'connection' => 'redis', 'queue' => 'default', 'size' => $size, 'oldest_age_s' => 600, 'sampled_at' => now()]);
            app(CheckQueueBacklogsAction::class)->execute();
        };
        $this->titles = fn (): array => Http::recorded()->map(fn (array $pair) => $pair[0]['embeds'][0]['title'])->all();
    });

    it('sends nothing during a window of any environment, and alerts as usual after it ended', function () {
        // Queues report no environment, so a window of one environment mutes them all.
        $window = MaintenanceWindow::factory()->of($this->project, 'staging')->create(['expected_end_at' => now()->addHour()]);

        foreach (range(1, 10) as $i) {
            ($this->minute)(500);
        }

        expect(($this->titles)())->toBe([]);

        app(EndMaintenanceWindowAction::class)->execute($window);

        foreach (range(1, 10) as $i) {
            ($this->minute)(500);
        }

        expect(($this->titles)())->toBe(['Queue backlog in Shop']);
    });

    it('alerts as usual once the window is past its expected end, even if nobody ends it', function () {
        MaintenanceWindow::factory()->of($this->project)->overrun()->create();

        foreach (range(1, 10) as $i) {
            ($this->minute)(500);
        }

        expect(($this->titles)())->toBe(['[Under maintenance] Queue backlog in Shop']);
    });
});
