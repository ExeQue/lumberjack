<?php

declare(strict_types=1);

use App\Domains\Alerts\Data\IssueAlert;
use App\Domains\Alerts\Drivers\NtfyDriver;
use App\Domains\Alerts\Drivers\PushoverDriver;
use App\Domains\Alerts\Drivers\SlackDriver;
use App\Domains\Alerts\Drivers\TeamsDriver;
use App\Domains\Alerts\Drivers\TelegramDriver;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Enums\ChannelType;
use App\Domains\Alerts\Exceptions\AlertDeliveryFailedException;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Issues\Models\Issue;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

covers(SlackDriver::class, TeamsDriver::class, TelegramDriver::class, NtfyDriver::class, PushoverDriver::class);

beforeEach(function () {
    $this->issue = Issue::factory()->create(['title' => 'App\\Exceptions\\PaymentFailed', 'message' => 'Card <declined> & retried']);
    $this->alert = fn (AlertRuleType $type = AlertRuleType::NewIssue, ?int $burst = null) => new IssueAlert($this->issue, $type, 'production', 'https://lumberjack.test/issues/1', burst: $burst);
    $this->channel = fn (ChannelType $type, array $config) => NotificationChannel::factory()->for($this->issue->project->team)->create(['type' => $type, 'name' => 'Ops', 'config' => $config]);
});

describe('Slack', function () {
    it('posts Block Kit with the issue linked and markup escaped', function () {
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
        $channel = ($this->channel)(ChannelType::Slack, ['webhook_url' => 'https://hooks.slack.com/services/T/B/x']);

        app(SlackDriver::class)->send($channel, ($this->alert)());

        Http::assertSent(fn (Request $r): bool => str_contains($r['blocks'][0]['text']['text'], '<https://lumberjack.test/issues/1|')
            && str_contains($r['blocks'][1]['text']['text'], 'Card &lt;declined&gt; &amp; retried'));
    });
});

describe('Microsoft Teams', function () {
    it('posts an Adaptive Card with a button to the issue', function () {
        Http::fake(['93.184.215.14/*' => Http::response(null, 202)]);
        $channel = ($this->channel)(ChannelType::Teams, ['webhook_url' => 'https://93.184.215.14/workflows/x']);

        app(TeamsDriver::class)->send($channel, ($this->alert)(AlertRuleType::Escalation, 42));

        Http::assertSent(function (Request $r): bool {
            $card = $r['attachments'][0]['content'];

            return $card['type'] === 'AdaptiveCard'
                && $card['body'][0]['color'] === 'Attention'
                && $card['actions'][0]['url'] === 'https://lumberjack.test/issues/1';
        });
    });

    it('refuses a webhook that now points at a private address', function () {
        Http::fake();
        $channel = ($this->channel)(ChannelType::Teams, ['webhook_url' => 'https://127.0.0.1/x']);

        expect(fn () => app(TeamsDriver::class)->sendTest($channel))->toThrow(AlertDeliveryFailedException::class);
        Http::assertNothingSent();
    });
});

describe('Telegram', function () {
    it('sends HTML to the chat through the bot, quietly for a note-less status change', function () {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $channel = ($this->channel)(ChannelType::Telegram, ['bot_token' => '123:'.str_repeat('a', 35), 'chat_id' => '-1001']);

        app(TelegramDriver::class)->send($channel, ($this->alert)());

        Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/bot123:'.str_repeat('a', 35).'/sendMessage')
            && $r['chat_id'] === '-1001'
            && $r['parse_mode'] === 'HTML'
            && str_contains($r['text'], 'Card &lt;declined&gt; &amp; retried')
            && $r['disable_notification'] === false);
    });

    it('says what Telegram answered when it refuses', function () {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 401)]);
        $channel = ($this->channel)(ChannelType::Telegram, ['bot_token' => '1:'.str_repeat('a', 35), 'chat_id' => '1']);

        expect(fn () => app(TelegramDriver::class)->sendTest($channel))->toThrow(AlertDeliveryFailedException::class, 'HTTP 401');
    });
});

describe('ntfy', function () {
    it('publishes JSON to the server with the topic, urgent for an escalation, with the token', function () {
        Http::fake(['93.184.215.14/*' => Http::response(['id' => 'x'])]);
        $channel = ($this->channel)(ChannelType::Ntfy, ['url' => 'https://93.184.215.14/ntfy/ops-alerts', 'access_token' => 'tk_secret']);

        app(NtfyDriver::class)->send($channel, ($this->alert)(AlertRuleType::Escalation, 30));

        Http::assertSent(fn (Request $r): bool => $r->url() === 'https://93.184.215.14/ntfy'
            && $r['topic'] === 'ops-alerts'
            && $r['priority'] === 4
            && $r['click'] === 'https://lumberjack.test/issues/1'
            && $r->hasHeader('Authorization', 'Bearer tk_secret'));
    });
});

describe('Pushover', function () {
    it('pushes with the app token and user key, linking the issue', function () {
        Http::fake(['api.pushover.net/*' => Http::response(['status' => 1])]);
        $channel = ($this->channel)(ChannelType::Pushover, ['user_key' => str_repeat('u', 30), 'app_token' => str_repeat('a', 30)]);

        app(PushoverDriver::class)->send($channel, ($this->alert)());

        Http::assertSent(fn (Request $r): bool => $r['token'] === str_repeat('a', 30)
            && $r['user'] === str_repeat('u', 30)
            && $r['url'] === 'https://lumberjack.test/issues/1'
            && (int) $r['priority'] === 0);
    });
});
