<?php

declare(strict_types=1);

use App\Domains\Alerts\Actions\SaveNotificationChannelAction;
use App\Domains\Alerts\Data\WeeklySummaryAlert;
use App\Domains\Alerts\Drivers\WebhookDriver;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Enums\ChannelType;
use App\Domains\Alerts\Jobs\SendWeeklySummary;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Alerts\Notifications\AlertSummaryNotification;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\Occurrence;
use App\Domains\Performance\Models\RequestRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Mail\ReadsSentMails;

covers(SendWeeklySummary::class, WeeklySummaryAlert::class, AlertSummaryNotification::class, WebhookDriver::class);

uses(ReadsSentMails::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-09 08:00:00'));
    config(['alerts.webhooks.allow_private' => false]);
    Http::fake(['*' => Http::response(null, 204)]);
    $this->team = Team::factory()->create(['name' => 'Acme']);
    $this->shop = Project::factory()->for($this->team)->create(['name' => 'Shop']);
    $this->blog = Project::factory()->for($this->team)->create(['name' => 'Blog']);
    $this->rule = AlertRule::query()->whereForTeam($this->team)->whereType(AlertRuleType::WeeklySummary)->sole();
    RequestRun::factory()->for($this->shop)->count(4)->create(['occurred_at' => now()->subDay(), 'duration_us' => 200_000]);
    RequestRun::factory()->for($this->shop)->count(2)->create(['occurred_at' => now()->subDays(9), 'duration_us' => 200_000]);
    $issue = Issue::factory()->for($this->shop)->create(['title' => 'PaymentFailed', 'first_seen_at' => now()->subDay()]);
    Occurrence::factory()->for($issue)->create(['occurred_at' => now()->subDay()]);
    $this->send = fn (NotificationChannel $channel) => app()->call([new SendWeeklySummary($channel, [$this->shop->id => $this->rule->id, $this->blog->id => $this->rule->id]), 'handle']);
});

describe('a channel\'s weekly summary', function () {
    it('leaves out a project with nothing in the week, and sends nothing when none is left', function () {
        $slack = NotificationChannel::factory()->for($this->team)->create(['type' => ChannelType::Slack, 'config' => ['webhook_url' => 'https://hooks.slack.com/services/T/B/x']]);

        app()->call([new SendWeeklySummary($slack, [$this->blog->id => $this->rule->id]), 'handle']);
        Http::assertNothingSent();

        ($this->send)($slack);
        Http::assertSentCount(1);
        expect(json_encode(Http::recorded()->first()[0]->data()))->toContain('Shop')->not->toContain('Blog');
    });

    it('is one short line per project in chat, with a link', function () {
        $slack = NotificationChannel::factory()->for($this->team)->create(['type' => ChannelType::Slack, 'config' => ['webhook_url' => 'https://hooks.slack.com/services/T/B/x']]);

        ($this->send)($slack);

        $blocks = Http::recorded()->first()[0]['blocks'];
        expect($blocks[0]['text']['text'])->toContain('Weekly summary')->toContain('2 Nov - 9 Nov')
            ->and($blocks[1]['text']['text'])->toBe('Shop: 4 requests (+100%), 0% errors, p95 200 ms, 1 new issue')
            ->and(collect($blocks)->firstWhere('type', 'actions')['elements'][0]['url'])->toBe(route('projects.index'));
    });

    it('is the full report as JSON in a webhook', function () {
        $webhook = app(SaveNotificationChannelAction::class)->execute($this->team, ChannelType::Webhook, 'App', ['url' => 'https://93.184.215.14/hook'], project: $this->shop);

        ($this->send)($webhook);

        $sent = Http::recorded()->map(fn (array $pair) => $pair[0])->first(fn (Request $request): bool => $request->url() === 'https://93.184.215.14/hook');
        $body = json_decode($sent->body(), true);

        expect($sent->header('X-Lumberjack-Event'))->toBe(['weekly_summary'])
            ->and($body)->toMatchArray(['version' => '1', 'event' => 'weekly_summary', 'period' => ['from' => '2026-11-02T08:00:00+00:00', 'to' => '2026-11-09T08:00:00+00:00'], 'maintenance' => null])
            ->and($body['projects'])->toHaveCount(1)
            ->and($body['projects'][0]['project'])->toMatchArray(['id' => $this->shop->uuid, 'name' => 'Shop'])
            ->and($body['projects'][0]['requests'])->toBe(['current' => 4, 'previous' => 2])
            ->and(array_keys($body['projects'][0]))->toBe(['project', 'requests', 'error_rate', 'p95_ms', 'apdex', 'new_issues', 'regressions', 'top_issues', 'slower_routes', 'failed_jobs', 'missed_tasks', 'health_checks', 'outages', 'maintenance', 'releases', 'most_new_issues']);
    });

    it('is the full report by mail, naming the rule that sends it', function () {
        $mail = NotificationChannel::factory()->for($this->team)->mail()->create();

        ($this->send)($mail);

        $sent = $this->mailsTo('ops@example.test');
        expect($sent)->toHaveCount(1)
            ->and($sent[0]->getSubject())->toBe('Weekly summary, 2 Nov - 9 Nov')
            ->and($sent[0]->getHtmlBody())->toContain('Shop')->toContain('Most frequent issues')->toContain('PaymentFailed')
            ->and($sent[0]->getTextBody())->toContain('the Weekly summary rule of Acme')
            ->and($sent[0]->getHtmlBody())->not->toContain('Blog');
    });
});
