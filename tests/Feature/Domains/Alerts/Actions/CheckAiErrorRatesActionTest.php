<?php

declare(strict_types=1);

use App\Domains\Ai\Models\AiCall;
use App\Domains\Ai\Models\AiKey;
use App\Domains\Ai\Models\AiUsageHourly;
use App\Domains\Alerts\Actions\CheckAiErrorRatesAction;
use App\Domains\Alerts\Data\AiErrorRateAlert;
use App\Domains\Alerts\Data\AiErrorRateSettings;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Enums\ChannelType;
use App\Domains\Alerts\Jobs\SendAiErrorRateAlert;
use App\Domains\Alerts\Models\AiErrorRate;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Maintenance\Models\MaintenanceWindow;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Enums\TeamRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\Support\Teams\CreatesTeamMembers;

covers(CheckAiErrorRatesAction::class, AiErrorRateSettings::class, AiErrorRateAlert::class, SendAiErrorRateAlert::class);

uses(CreatesTeamMembers::class);

describe('AI error rate alerts', function () {
    beforeEach(function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00'));
        Http::fake(['*' => Http::response(null, 204)]);
        $this->project = Project::factory()->create(['name' => 'Shop']);
        $this->key = AiKey::factory()->forProject($this->project)->labeled('prod')->create(['last_seen_at' => now()]);
        $this->rule = AlertRule::factory()->for($this->project)->create(['type' => AlertRuleType::AiErrorRate, 'settings' => ['minutes' => 15, 'rate' => 50, 'min_calls' => 10]]);
        $this->rule->channels()->attach(NotificationChannel::factory()->for($this->project->team)->create(['type' => ChannelType::Discord, 'config' => ['webhook_url' => 'https://discord.com/api/webhooks/1/x']]));
        // These many calls with the key a minute ago, this many failed; then a check.
        $this->minute = function (int $calls, int $failed): void {
            AiCall::factory()->forKey($this->key)->count($calls - $failed)->create(['occurred_at' => now()->subMinute()]);
            AiCall::factory()->forKey($this->key)->failed()->count($failed)->create(['occurred_at' => now()->subMinute()]);
            app(CheckAiErrorRatesAction::class)->execute();
            $this->travel(1)->minutes();
        };
        $this->titles = fn (): array => Http::recorded()->map(fn (array $pair) => $pair[0]['embeds'][0]['title'])->all();
    });

    it('alerts once a key fails too often, once when it works again, and not again within the throttle', function () {
        ($this->minute)(9, 9);

        expect(($this->titles)())->toBe([]);

        ($this->minute)(4, 2);
        ($this->minute)(2, 2);

        expect(($this->titles)())->toBe(['AI calls failing in Shop']);

        // The failures age out of the 15 minute window behind a run of good calls.
        $this->travel(15)->minutes();
        ($this->minute)(20, 0);

        expect(($this->titles)())->toBe(['AI calls failing in Shop', 'AI calls working again in Shop']);

        ($this->minute)(20, 20);

        expect(($this->titles)())->toHaveCount(2);
    });

    it('needs the least number of calls before a failing key counts', function () {
        $this->rule->update(['settings' => ['minutes' => 15, 'rate' => 50, 'min_calls' => 30]]);

        ($this->minute)(20, 20);

        expect(($this->titles)())->toBe([]);
    });

    it('counts only the calls of the rule\'s environments', function () {
        $this->rule->update(['environments' => ['production']]);
        AiCall::factory()->forKey($this->key)->failed()->count(20)->create(['occurred_at' => now()->subMinute(), 'environment' => 'staging']);

        ($this->minute)(4, 4);

        expect(($this->titles)())->toBe([]);

        ($this->minute)(10, 10);

        expect(($this->titles)())->toBe(['AI calls failing in Shop']);
    });

    it('counts the whole hours of a long window from the hourly totals, and the rest from the calls', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-18 12:30:00'));
        $this->rule->update(['settings' => ['minutes' => 120, 'rate' => 50, 'min_calls' => 10], 'environments' => ['production']]);
        $hour = fn (string $at, int $calls, int $errors, string $environment = 'production') => AiUsageHourly::factory()->forKey($this->key)
            ->create(['hour' => $at, 'calls' => $calls, 'errors' => $errors, 'environment' => $environment]);
        // From 10:30: the hour from 11:00 whole; of the one from 10:00 only its calls after 10:30 count.
        $hour('2026-11-18 11:00:00', 6, 6);
        $hour('2026-11-18 11:00:00', 50, 0, 'staging');
        $hour('2026-11-18 10:00:00', 50, 0);
        // The current hour's total holds the calls below again: counted from them, not twice.
        $hour('2026-11-18 12:00:00', 4, 4);
        // Before the window.
        AiCall::factory()->forKey($this->key)->count(40)->create(['occurred_at' => '2026-11-18 10:15:00']);

        ($this->minute)(4, 4);

        expect(($this->titles)())->toBe(['AI calls failing in Shop']);
        // 10 of 10: counting the calls before 10:30 or the current hour's total again would make it more.
        expect(Http::recorded()->first()[0]['embeds'][0]['description'] ?? '')->toContain('10 of 10 calls');
    });

    it('starts over when the rule is turned off and on again', function () {
        ($this->minute)(20, 20);
        $this->rule->update(['enabled' => false]);
        ($this->minute)(20, 20);

        expect(AiErrorRate::query()->count())->toBe(0);

        $this->rule->update(['enabled' => true]);
        ($this->minute)(20, 20);

        expect(($this->titles)())->toBe(['AI calls failing in Shop', 'AI calls failing in Shop']);
    });

    it('forgets a key back to normal once it no longer holds back the next alert', function () {
        ($this->minute)(20, 20);
        $this->travel(15)->minutes();
        ($this->minute)(20, 0);

        expect(AiErrorRate::query()->count())->toBe(1);

        $this->travel(AlertRule::THROTTLE_MINUTES)->minutes();
        ($this->minute)(20, 0);

        expect(AiErrorRate::query()->count())->toBe(0);
    });

    it('sends nothing during maintenance', function () {
        MaintenanceWindow::factory()->of($this->project)->create(['expected_end_at' => now()->addHour()]);

        ($this->minute)(20, 20);

        expect(($this->titles)())->toBe([]);
    });
});

describe('the rule\'s settings', function () {
    it('saves them, and refuses what they cannot be', function () {
        ['user' => $owner] = $this->memberWithProject(TeamRole::Owner);
        $rule = AlertRule::query()->whereForTeam($owner->currentTeam)->whereType(AlertRuleType::AiErrorRate)->sole();

        expect($rule->aiErrorRateSettings()->toArray())->toBe(['minutes' => 15, 'rate' => 50, 'min_calls' => 10]);

        $this->actingAs($owner)->put(route('alerts.rules.update', $rule), [
            'enabled' => true, 'channels' => [], 'settings' => ['minutes' => 30, 'rate' => 20, 'min_calls' => 5],
        ])->assertSessionHasNoErrors();

        expect($rule->refresh()->settings)->toBe(['minutes' => 30, 'rate' => 20, 'min_calls' => 5]);

        $this->actingAs($owner)->put(route('alerts.rules.update', $rule), [
            'enabled' => true, 'channels' => [], 'settings' => ['minutes' => 2000, 'rate' => 100, 'min_calls' => 0],
        ])->assertSessionHasErrors(['settings.minutes', 'settings.rate', 'settings.min_calls']);
    });
});
