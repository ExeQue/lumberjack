<?php

declare(strict_types=1);

use App\Domains\Alerts\Data\BulkStatusChangeAlert;
use App\Domains\Alerts\Data\HealthCheckAlert;
use App\Domains\Alerts\Data\QueueBacklogAlert;
use App\Domains\Alerts\Data\QueueBacklogSettings;
use App\Domains\Alerts\Drivers\DiscordDriver;
use App\Domains\Alerts\Drivers\MailDriver;
use App\Domains\Alerts\Drivers\SlackDriver;
use App\Domains\Alerts\Drivers\TeamsDriver;
use App\Domains\Alerts\Drivers\TelegramDriver;
use App\Domains\Alerts\Drivers\WebhookDriver;
use App\Domains\Alerts\Enums\ChannelType;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Alerts\Notifications\AlertSummaryNotification;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Models\Issue;
use App\Domains\Projects\Models\Project;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

covers(BulkStatusChangeAlert::class, QueueBacklogAlert::class, QueueBacklogSettings::class, HealthCheckAlert::class, AlertSummaryNotification::class);

describe('queue backlog messages', function () {
    beforeEach(function () {
        Http::fake(['*' => Http::response(null, 204)]);
        $this->project = Project::factory()->create(['name' => 'Shop']);
        $this->alert = fn (bool $recovered = false) => new QueueBacklogAlert($this->project, 'redis', 'emails', 1240, 840, new QueueBacklogSettings(), now()->subMinutes(7)->toImmutable(), $recovered, 'https://lumberjack.test/jobs');
        $this->channel = fn (ChannelType $type, array $config) => NotificationChannel::factory()->for($this->project->team)->create(['type' => $type, 'name' => 'Ops', 'config' => $config]);
    });

    it('lists the same facts in Discord, Slack, Teams and Telegram', function () {
        app(DiscordDriver::class)->send(($this->channel)(ChannelType::Discord, ['webhook_url' => 'https://discord.com/api/webhooks/1/x']), ($this->alert)());
        app(SlackDriver::class)->send(($this->channel)(ChannelType::Slack, ['webhook_url' => 'https://hooks.slack.com/services/T/B/x']), ($this->alert)());
        app(TeamsDriver::class)->send(($this->channel)(ChannelType::Teams, ['webhook_url' => 'https://93.184.215.14/workflows/x']), ($this->alert)());
        app(TelegramDriver::class)->send(($this->channel)(ChannelType::Telegram, ['bot_token' => '123456:'.str_repeat('a', 35), 'chat_id' => '-100123']), ($this->alert)());

        $sent = Http::recorded()->map(fn (array $pair) => $pair[0]);
        $discord = collect($sent[0]['embeds'][0]['fields'])->pluck('value', 'name')->all();
        $slack = collect(collect($sent[1]['blocks'])->firstWhere('fields')['fields'])->mapWithKeys(fn (array $f) => [trim(explode("\n", $f['text'])[0], '*') => explode("\n", $f['text'])[1]])->all();
        $teams = collect(collect($sent[2]['attachments'][0]['content']['body'])->firstWhere('type', 'FactSet')['facts'])->pluck('value', 'title')->all();
        preg_match_all('#^<b>([^<]+):</b> (.+)$#m', $sent[3]['text'], $lines, PREG_SET_ORDER);
        $telegram = collect($lines)->mapWithKeys(fn (array $m) => [$m[1] => $m[2]])->all();

        expect($discord)->toBe([
            'Waiting' => '1,240',
            'Oldest pending' => '14 minutes',
            'Threshold' => 'more than 1,000 jobs or the oldest waiting over 10 minutes, for 5 minutes',
        ])
            ->and($sent[0]['embeds'][0]['title'])->toBe('Queue backlog in Shop')
            ->and($sent[0]['embeds'][0]['description'])->toContain('`redis:emails`')
            ->and($slack)->toBe($discord)
            ->and($teams)->toBe($discord)
            ->and($telegram)->toBe($discord);
    });

    it('links back with a button on Discord', function () {
        app(DiscordDriver::class)->send(($this->channel)(ChannelType::Discord, ['webhook_url' => 'https://discord.com/api/webhooks/1/x']), ($this->alert)());

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'with_components=true')
            && $request['components'][0]['components'][0]['style'] === 5
            && $request['components'][0]['components'][0]['url'] !== ''
            && ! str_contains($request['embeds'][0]['description'], 'Open in Lumberjack'));
    });

    it('keeps a thread in the Discord webhook address', function () {
        app(DiscordDriver::class)->send(($this->channel)(ChannelType::Discord, ['webhook_url' => 'https://discord.com/api/webhooks/1/x?thread_id=123']), ($this->alert)());

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '?thread_id=123&with_components=true'));
    });

    it('posts the backlog and its recovery to a webhook', function () {
        $channel = ($this->channel)(ChannelType::Webhook, ['url' => 'https://93.184.215.14/hook', 'secret' => 'whsec_x']);

        app(WebhookDriver::class)->send($channel, ($this->alert)(recovered: true));

        Http::assertSent(fn (Request $request) => $request['event'] === 'queue_backlog'
            && $request['queue'] === ['connection' => 'redis', 'name' => 'emails', 'state' => 'recovered', 'size' => 1240, 'oldest_age_s' => 840, 'since' => now()->subMinutes(7)->toIso8601String(), 'url' => 'https://lumberjack.test/jobs']
            && $request['thresholds'] === ['size' => 1000, 'age_minutes' => 10, 'sustain_minutes' => 5]);
    });

    it('mails the backlog', function () {
        Notification::fake();

        app(MailDriver::class)->send(($this->channel)(ChannelType::Mail, ['address' => 'ops@example.test']), ($this->alert)());

        Notification::assertSentOnDemand(AlertSummaryNotification::class, function (AlertSummaryNotification $n): bool {
            $mail = $n->toMail(new stdClass());

            return $mail->subject === '[Shop] Queue backlog: redis:emails'
                && $n->card()->facts['Waiting'] === '1,240'
                && $n->card()->button['label'] === 'Open jobs in Shop'
                && str_contains((string) $mail->render(), 'the mail channel <strong>Ops</strong>');
        });
    });
});

describe('health check messages', function () {
    beforeEach(function () {
        Http::fake(['*' => Http::response(null, 204)]);
        $this->check = HealthCheck::factory()->for(Project::factory()->create(['name' => 'Shop']))->create(['slug' => 'disk:s3']);
        $this->alert = fn (string $state = HealthCheckAlert::DOWN) => new HealthCheckAlert($this->check, $state, 'Disk is not writable', ['web-1', 'web-2'], now()->subMinutes(7)->toImmutable(), 'https://lumberjack.test/health');
        $this->channel = fn (ChannelType $type, array $config) => NotificationChannel::factory()->for($this->check->project->team)->create(['type' => $type, 'name' => 'Ops', 'config' => $config]);
    });

    it('lists the same facts in Discord, Slack, Teams and Telegram', function () {
        app(DiscordDriver::class)->send(($this->channel)(ChannelType::Discord, ['webhook_url' => 'https://discord.com/api/webhooks/1/x']), ($this->alert)());
        app(SlackDriver::class)->send(($this->channel)(ChannelType::Slack, ['webhook_url' => 'https://hooks.slack.com/services/T/B/x']), ($this->alert)());
        app(TeamsDriver::class)->send(($this->channel)(ChannelType::Teams, ['webhook_url' => 'https://93.184.215.14/workflows/x']), ($this->alert)());
        app(TelegramDriver::class)->send(($this->channel)(ChannelType::Telegram, ['bot_token' => '123456:'.str_repeat('a', 35), 'chat_id' => '-100123']), ($this->alert)());

        $sent = Http::recorded()->map(fn (array $pair) => $pair[0]);
        $discord = collect($sent[0]['embeds'][0]['fields'])->pluck('value', 'name')->all();
        $slack = collect(collect($sent[1]['blocks'])->firstWhere('fields')['fields'])->mapWithKeys(fn (array $f) => [trim(explode("\n", $f['text'])[0], '*') => explode("\n", $f['text'])[1]])->all();
        $teams = collect(collect($sent[2]['attachments'][0]['content']['body'])->firstWhere('type', 'FactSet')['facts'])->pluck('value', 'title')->all();
        preg_match_all('#^<b>([^<]+):</b> (.+)$#m', $sent[3]['text'], $lines, PREG_SET_ORDER);
        $telegram = collect($lines)->mapWithKeys(fn (array $m) => [$m[1] => $m[2]])->all();

        expect($discord)->toBe(['Status' => 'Down', 'Check' => 'disk:s3', 'Environment' => 'production', 'Hosts' => 'web-1, web-2'])
            ->and($sent[0]['embeds'][0]['title'])->toBe('Health check down in Shop')
            ->and($sent[0]['embeds'][0]['description'])->toContain('Disk is not writable')
            ->and($slack)->toBe($discord)
            ->and($teams)->toBe($discord)
            ->and($telegram)->toBe($discord);
    });

    it('posts the state to a webhook', function () {
        $channel = ($this->channel)(ChannelType::Webhook, ['url' => 'https://93.184.215.14/hook', 'secret' => 'whsec_x']);

        app(WebhookDriver::class)->send($channel, ($this->alert)(HealthCheckAlert::RECOVERED));

        Http::assertSent(fn (Request $request) => $request['event'] === 'health_recovered'
            && $request['check']['id'] === $this->check->uuid
            && $request['check']['state'] === 'recovered'
            && $request['check']['slug'] === 'disk:s3'
            && $request['check']['hosts'] === ['web-1', 'web-2']);
    });
});

describe('bulk status change messages', function () {
    beforeEach(function () {
        Http::fake(['*' => Http::response(null, 204)]);
        $this->project = Project::factory()->create(['name' => 'Shop', 'slug' => 'shop']);
        $this->issues = Issue::factory()->for($this->project)->count(12)->create()->all();
        $this->alert = fn () => new BulkStatusChangeAlert($this->project, IssueStatus::Resolved, 'Morten', $this->issues, ['production', 'staging'], 'https://lumberjack.test/issues?status=resolved');
        $this->channel = fn (ChannelType $type, array $config) => NotificationChannel::factory()->for($this->project->team)->create(['type' => $type, 'name' => 'Ops', 'config' => $config]);
    });

    it('lists the same facts in Discord, Slack, Teams and Telegram', function () {
        app(DiscordDriver::class)->send(($this->channel)(ChannelType::Discord, ['webhook_url' => 'https://discord.com/api/webhooks/1/x']), ($this->alert)());
        app(SlackDriver::class)->send(($this->channel)(ChannelType::Slack, ['webhook_url' => 'https://hooks.slack.com/services/T/B/x']), ($this->alert)());
        app(TeamsDriver::class)->send(($this->channel)(ChannelType::Teams, ['webhook_url' => 'https://93.184.215.14/workflows/x']), ($this->alert)());
        app(TelegramDriver::class)->send(($this->channel)(ChannelType::Telegram, ['bot_token' => '123456:'.str_repeat('a', 35), 'chat_id' => '-100123']), ($this->alert)());

        $sent = Http::recorded()->map(fn (array $pair) => $pair[0]);
        $discord = collect($sent[0]['embeds'][0]['fields'])->pluck('value', 'name')->all();
        $slack = collect(collect($sent[1]['blocks'])->firstWhere('fields')['fields'])->mapWithKeys(fn (array $f) => [trim(explode("\n", $f['text'])[0], '*') => explode("\n", $f['text'])[1]])->all();
        $teams = collect(collect($sent[2]['attachments'][0]['content']['body'])->firstWhere('type', 'FactSet')['facts'])->pluck('value', 'title')->all();
        preg_match_all('#^<b>([^<]+):</b> (.+)$#m', $sent[3]['text'], $lines, PREG_SET_ORDER);
        $telegram = collect($lines)->mapWithKeys(fn (array $m) => [$m[1] => $m[2]])->all();

        expect($discord)->toBe(['Status' => 'Resolved', 'Issues' => '12', 'Environment' => 'production, staging'])
            ->and($sent[0]['embeds'][0]['title'])->toBe('Morten resolved 12 issues in Shop')
            ->and($sent[0]['embeds'][0]['description'])->toContain('SHOP-1 · RuntimeException')->toContain('+2 more')->not->toContain('SHOP-11 ·')
            ->and($slack)->toBe($discord)
            ->and($teams)->toBe($discord)
            ->and($telegram)->toBe($discord);
    });

    it('posts every issue to a webhook', function () {
        $channel = ($this->channel)(ChannelType::Webhook, ['url' => 'https://93.184.215.14/hook', 'secret' => 'whsec_x']);

        app(WebhookDriver::class)->send($channel, ($this->alert)());

        Http::assertSent(fn (Request $request) => $request['event'] === 'bulk_status_change'
            && $request['status'] === 'resolved'
            && $request['actor'] === 'Morten'
            && $request['url'] === 'https://lumberjack.test/issues?status=resolved'
            && count($request['issues']) === 12
            && $request['issues'][0] === ['id' => $this->issues[0]->uuid, 'reference' => 'SHOP-1', 'title' => 'RuntimeException']);
    });

    it('mails the summary', function () {
        Notification::fake();

        app(MailDriver::class)->send(($this->channel)(ChannelType::Mail, ['address' => 'ops@example.test']), ($this->alert)());

        Notification::assertSentOnDemand(AlertSummaryNotification::class, function (AlertSummaryNotification $n): bool {
            $mail = $n->toMail(new stdClass());

            return $mail->subject === '[Shop] Morten resolved 12 issues'
                && $n->card()->preheader === 'SHOP-1, SHOP-2, SHOP-3, SHOP-4, SHOP-5, SHOP-6, SHOP-7, SHOP-8, SHOP-9, SHOP-10, +2 more'
                && count($n->card()->rows) === 10 && $n->card()->more === '+2 more'
                && $n->card()->button['label'] === 'See resolved issues';
        });
    });
});
