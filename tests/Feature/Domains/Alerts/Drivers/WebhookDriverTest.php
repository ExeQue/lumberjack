<?php

declare(strict_types=1);

use App\Domains\Alerts\Actions\SaveNotificationChannelAction;
use App\Domains\Alerts\Data\IssueAlert;
use App\Domains\Alerts\Drivers\WebhookDriver;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Enums\ChannelType;
use App\Domains\Alerts\Exceptions\AlertDeliveryFailedException;
use App\Domains\Issues\Models\Issue;
use App\Domains\Projects\Models\Project;
use App\Domains\Shared\Rules\PublicUrl;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

covers(WebhookDriver::class, PublicUrl::class);

describe('the webhook channel', function () {
    it('posts the alert as JSON, signed with the channel\'s own secret', function () {
        Http::fake(['93.184.215.14/*' => Http::response(null, 204)]);
        $issue = Issue::factory()->create(['title' => 'App\\Exceptions\\PaymentFailed']);
        $channel = app(SaveNotificationChannelAction::class)->execute($issue->project->team, ChannelType::Webhook, 'App', ['url' => 'https://93.184.215.14/lumberjack/webhook'], project: $issue->project);

        app(WebhookDriver::class)->send($channel, new IssueAlert($issue, AlertRuleType::Escalation, 'production', 'https://lumberjack.test/x', burst: 42));

        expect($channel->revealSecret)->toStartWith('whsec_');
        Http::assertSent(function (Request $request) use ($channel, $issue): bool {
            $expected = 'sha256='.hash_hmac('sha256', $request->header('X-Lumberjack-Timestamp')[0].'.'.$request->body(), $channel->config['secret']);

            return $request->header('X-Lumberjack-Signature')[0] === $expected
                && $request['event'] === 'escalation'
                && $request['burst'] === 42
                && $request['issue']['title'] === 'App\\Exceptions\\PaymentFailed'
                && $request['issue']['reference'] === $issue->reference();
        });
    });

    it('refuses URLs on private or local addresses', function () {
        expect(PublicUrl::allows('http://127.0.0.1:6379'))->toBeFalse()
            ->and(PublicUrl::allows('http://10.0.0.5/hook'))->toBeFalse()
            ->and(PublicUrl::allows('http://[::1]/hook'))->toBeFalse()
            ->and(PublicUrl::allows('ftp://example.com/hook'))->toBeFalse()
            ->and(PublicUrl::allows('https://93.184.215.14/hook'))->toBeTrue();

        config()->set('alerts.webhooks.allow_private', true);
        expect(PublicUrl::allows('http://127.0.0.1:8000/hook'))->toBeTrue();
    });

    it('will not send to a URL that has come to point inside the network', function () {
        $project = Project::factory()->create();
        $channel = app(SaveNotificationChannelAction::class)->execute($project->team, ChannelType::Webhook, 'App', ['url' => 'https://93.184.215.14/hook'], project: $project);
        $channel->update(['config' => [...$channel->config, 'url' => 'http://169.254.169.254/latest']]);

        app(WebhookDriver::class)->sendTest($channel);
    })->throws(AlertDeliveryFailedException::class);
});
