<?php

declare(strict_types=1);

use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Jobs\SendReleaseAlert;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Projects\Models\Project;
use App\Domains\Releases\Actions\RecordReleaseEnvironmentAction;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

covers(RecordReleaseEnvironmentAction::class, SendReleaseAlert::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['name' => 'Shop']);
    $this->action = app(RecordReleaseEnvironmentAction::class);
    $this->rule = AlertRule::query()->whereForTeam($this->project->team)->whereType(AlertRuleType::NewRelease)->sole();
});

describe('when a release alerts', function () {
    beforeEach(function () {
        Bus::fake([SendReleaseAlert::class]);
    });

    it('stays quiet on the first release of an environment', function () {
        $this->action->execute($this->project, 'v1', 'production', 'web-1');

        Bus::assertNotDispatched(SendReleaseAlert::class);
    });

    it('alerts once per environment for a release', function () {
        $this->action->execute($this->project, 'v1', 'production', 'web-1');
        $this->action->execute($this->project, 'v1', 'staging', 'web-1');

        $this->action->execute($this->project, 'v2', 'staging', 'web-1');
        $this->action->execute($this->project, 'v2', 'staging', 'web-2');
        $this->action->execute($this->project, 'v2', 'production', 'web-1');

        Bus::assertDispatchedTimes(SendReleaseAlert::class, 2);
        Bus::assertDispatched(SendReleaseAlert::class, fn (SendReleaseAlert $job): bool => $job->release === 'v2' && $job->environment === 'production');
    });

    it('sends nothing when the rule is off or leaves out the environment', function (array $rule) {
        $this->rule->update($rule);
        $this->action->execute($this->project, 'v1', 'production', 'web-1');

        $this->action->execute($this->project, 'v2', 'production', 'web-1');

        Bus::assertNotDispatched(SendReleaseAlert::class);
    })->with([
        'turned off' => [['enabled' => false]],
        'another environment' => [['environments' => ['staging']]],
    ]);
});

describe('what a release alert says', function () {
    it('names the project, release, environment and host', function () {
        Http::fake(['*' => Http::response(null, 204)]);
        $channel = NotificationChannel::factory()->for($this->project->team)->create();
        $this->rule->channels()->attach($channel);
        $this->action->execute($this->project, 'v1', 'production', 'web-1');

        $this->action->execute($this->project, 'v2', 'production', 'web-1');

        Http::assertSent(fn ($request): bool => str_contains($request->body(), 'New release in Shop')
            && str_contains($request->body(), 'v2')
            && str_contains($request->body(), 'production')
            && str_contains($request->body(), 'web-1'));
    });
});
