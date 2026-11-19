<?php

declare(strict_types=1);

use App\Domains\Ai\Data\AiBudgetUsage;
use App\Domains\Ai\Models\AiBudget;
use App\Domains\Ai\Models\AiKey;
use App\Domains\Alerts\Data\AiBudgetAlert;
use App\Domains\Alerts\Data\AiErrorRateAlert;
use App\Domains\Alerts\Data\AiErrorRateSettings;
use App\Domains\Alerts\Drivers\MailDriver;
use App\Domains\Alerts\Drivers\WebhookDriver;
use App\Domains\Alerts\Enums\ChannelType;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Alerts\Notifications\AlertSummaryNotification;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

covers(AiBudgetAlert::class, AiErrorRateAlert::class, WebhookDriver::class, AlertSummaryNotification::class);

describe('AI alert messages', function () {
    beforeEach(function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-16 00:00:00'));
        Http::fake(['*' => Http::response(null, 204)]);
        $this->project = Project::factory()->create(['name' => 'Shop']);
        $this->budget = AiBudget::factory()->forProject($this->project)->forOwner('tenant', 7)->costing(100)->create(['name' => null]);
        $this->usage = new AiBudgetUsage(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-12-01'), 82.5, 100, 0.5);
        $this->key = AiKey::factory()->forProject($this->project)->labeled('prod')->create();
        $this->errors = new AiErrorRateAlert($this->project, $this->key->fingerprint, $this->key, 40, 30, new AiErrorRateSettings(), now()->subMinutes(4)->toImmutable(), false, 'https://lumberjack.test/key');
        $this->channel = fn (ChannelType $type, array $config) => NotificationChannel::factory()->for($this->project->team)->create(['type' => $type, 'name' => 'Ops', 'config' => $config]);
        $this->webhook = fn () => ($this->channel)(ChannelType::Webhook, ['url' => 'https://93.184.215.14/hook', 'secret' => 'whsec_x']);
    });

    it('posts a budget passing a threshold to a webhook', function () {
        app(WebhookDriver::class)->send(($this->webhook)(), new AiBudgetAlert($this->budget, $this->usage, 80, 'https://lumberjack.test/budgets'));

        Http::assertSent(fn (Request $request) => $request['event'] === 'ai_budget'
            && $request['budget']['id'] === $this->budget->uuid
            && $request['budget']['name'] === 'Key owner tenant:7'
            && $request['budget']['scope'] === 'owner'
            && $request['budget']['currency'] === 'USD'
            && $request['budget']['threshold'] === 80
            && $request['budget']['spent'] === 82.5
            && (float) $request['budget']['projected'] === 165.0
            && $request['budget']['owners'] === [['type' => 'tenant', 'id' => '7']]);
    });

    it('names the first few of many owners in a budget\'s title, and how many more', function () {
        $budget = AiBudget::factory()->forProject($this->project)->forOwner('tenant', 1, ['tenant', 2], ['tenant', 3], ['tenant', 4], ['tenant', 5])->create(['name' => null]);

        expect($budget->title())->toBe('Key owners tenant:1, tenant:2, tenant:3 and 2 more');
    });

    it('posts a key budget\'s keys by id, never by fingerprint, and no pace for a period that ended', function () {
        $forgotten = AiKey::factory()->forProject($this->project)->create();
        $budget = AiBudget::factory()->forKey($this->key, $forgotten)->create();
        $forgotten->delete();
        $ended = new AiBudgetUsage(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-11-01'), 82.5, 100, 1.0);

        app(WebhookDriver::class)->send(($this->webhook)(), new AiBudgetAlert($budget->refresh(), $ended, 80, 'https://lumberjack.test/budgets'));

        Http::assertSent(fn (Request $request) => collect($request['budget']['keys'])->sortBy('short_id')->values()->all() === collect([
            ['id' => $this->key->uuid, 'short_id' => $this->key->shortId(), 'name' => null, 'label' => 'prod'],
            ['id' => null, 'short_id' => $forgotten->shortId(), 'name' => null, 'label' => null],
        ])->sortBy('short_id')->values()->all()
            && ! str_contains($request->body(), $this->key->fingerprint)
            && $request['budget']['projected'] === null);
    });

    it('posts a failing key to a webhook', function () {
        app(WebhookDriver::class)->send(($this->webhook)(), $this->errors);

        Http::assertSent(fn (Request $request) => $request['event'] === 'ai_error_rate'
            && $request['key']['id'] === $this->key->uuid
            && $request['key']['state'] === 'started'
            && [$request['key']['calls'], $request['key']['errors']] === [40, 30]
            && $request['thresholds'] === ['minutes' => 15, 'rate' => 50, 'min_calls' => 10]);
    });

    it('mails both with their facts', function () {
        Notification::fake();
        $mail = ($this->channel)(ChannelType::Mail, ['address' => 'ops@example.test']);

        app(MailDriver::class)->send($mail, new AiBudgetAlert($this->budget, $this->usage, null, 'https://lumberjack.test/budgets'));
        app(MailDriver::class)->send($mail, $this->errors);

        Notification::assertSentOnDemand(AlertSummaryNotification::class, fn (AlertSummaryNotification $n): bool => $n->card()->title === 'Key owner tenant:7'
            && $n->toMail(new stdClass())->subject === '[Shop] AI budget on course to go over in Shop: Key owner tenant:7'
            && $n->card()->facts['At this pace'] === '165.00 USD');
        Notification::assertSentOnDemand(AlertSummaryNotification::class, fn (AlertSummaryNotification $n): bool => $n->card()->title === 'Key prod'
            && $n->card()->facts['Failed'] === '30 / 40 (75%)'
            && $n->card()->button['label'] === 'Open the key\'s failed calls');
    });

    it('names the period a budget alert is about once it ended, without a pace', function () {
        $ended = new AiBudgetAlert($this->budget, new AiBudgetUsage(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-11-01'), 82.5, 100, 1.0), 80, 'https://lumberjack.test/budgets');

        expect($ended->summary()['body'])->toBe(['82.50 USD of 100.00 USD used (82%) for October 2026.'])
            ->and($ended->facts())->not->toHaveKey('At this pace');
    });
});
