<?php

declare(strict_types=1);

use App\Domains\Alerts\Data\BulkStatusChangeAlert;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Jobs\SendBulkStatusChangeAlert;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Issues\Actions\BulkChangeIssueStatusAction;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\Occurrence;
use App\Domains\Teams\Enums\TeamRole;
use Illuminate\Support\Facades\Http;
use Tests\Support\Teams\CreatesTeamMembers;

covers(BulkChangeIssueStatusAction::class, SendBulkStatusChangeAlert::class, BulkStatusChangeAlert::class);

uses(CreatesTeamMembers::class);

describe('bulk status change alerts', function () {
    beforeEach(function () {
        Http::fake(['discord.com/*' => Http::response(null, 204)]);
        ['user' => $this->user, 'project' => $this->project] = $this->memberWithProject(TeamRole::Member);
        $this->user->update(['name' => 'Morten']);
        $this->project->update(['name' => 'Demo']);
        $this->channel = NotificationChannel::factory()->for($this->project->team)->create();
        $this->rule = AlertRule::factory()->for($this->project)->create(['type' => AlertRuleType::StatusChange]);
        $this->rule->channels()->attach($this->channel);
        $this->bulk = fn (int $count, string $status = 'resolved') => $this->actingAs($this->user)
            ->post(route('issues.bulk-status', $this->project), ['status' => $status, 'issues' => Issue::factory()->for($this->project)->count($count)->create()->pluck('uuid')->all()])
            ->assertRedirect();
    });

    it('sends one summary for a bulk resolve', function () {
        ($this->bulk)(4);

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            $embed = $request['embeds'][0];

            return $embed['title'] === 'Morten resolved 4 issues in Demo'
                && $embed['url'] === route('issues.index', ['project' => $this->project, 'status' => 'resolved'])
                && Issue::all()->every(fn (Issue $issue): bool => str_contains($embed['description'], $issue->reference().' · '.$issue->title));
        });
        expect($this->channel->refresh()->last_delivery_status)->toBe('sent');
    });

    it('names ten issues and counts the rest', function () {
        ($this->bulk)(15, 'ignored');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request['embeds'][0]['title'] === 'Morten ignored 15 issues in Demo'
            && substr_count($request['embeds'][0]['description'], ' · RuntimeException') === 10
            && str_contains($request['embeds'][0]['description'], '+5 more'));
    });

    it('sends the normal message when only one issue changes', function () {
        ($this->bulk)(1);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request['embeds'][0]['title'] === 'Resolved by Morten in Demo');
    });

    it('counts only the issues that change', function () {
        Issue::factory()->for($this->project)->create(['status' => 'resolved']);
        $open = Issue::factory()->for($this->project)->create();

        $this->actingAs($this->user)->post(route('issues.bulk-status', $this->project), ['status' => 'resolved', 'issues' => Issue::query()->pluck('uuid')->all()]);

        Http::assertSent(fn ($request): bool => $request['embeds'][0]['title'] === 'Resolved by Morten in Demo'
            && str_contains($request['embeds'][0]['description'], $open->title));
    });

    it('sends nothing while the rule is off, or for a re-open', function () {
        ($this->bulk)(3, 'open');
        $this->rule->update(['enabled' => false]);
        ($this->bulk)(3);

        Http::assertNothingSent();
    });

    it('summarizes only the issues in the rule environments', function () {
        $this->rule->update(['environments' => ['production']]);
        $issues = Issue::factory()->for($this->project)->count(3)->create();
        Occurrence::factory()->for($issues[0])->create(['environment' => 'production']);
        Occurrence::factory()->for($issues[1])->create(['environment' => 'production']);
        Occurrence::factory()->for($issues[2])->create(['environment' => 'staging']);

        $this->actingAs($this->user)->post(route('issues.bulk-status', $this->project), ['status' => 'resolved', 'issues' => $issues->pluck('uuid')->all()]);

        Http::assertSent(fn ($request): bool => $request['embeds'][0]['title'] === 'Morten resolved 2 issues in Demo'
            && collect($request['embeds'][0]['fields'])->pluck('value', 'name')->all() === ['Status' => 'Resolved', 'Issues' => '2', 'Environment' => 'production']);
    });
});
