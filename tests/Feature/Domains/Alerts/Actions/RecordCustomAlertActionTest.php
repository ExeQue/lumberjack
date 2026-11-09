<?php

declare(strict_types=1);

use App\Domains\Alerts\Actions\CreateAlertTopicAction;
use App\Domains\Alerts\Actions\DeleteAlertTopicAction;
use App\Domains\Alerts\Actions\RecordCustomAlertAction;
use App\Domains\Alerts\Data\CustomAlertMessage;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Enums\ChannelType;
use App\Domains\Alerts\Jobs\SendCustomAlert;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\CustomAlert;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Ingest\Data\Envelope;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

covers(RecordCustomAlertAction::class, CreateAlertTopicAction::class, DeleteAlertTopicAction::class, SendCustomAlert::class, CustomAlertMessage::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['name' => 'Shop']);
    $this->envelope = new Envelope('production', 'v1', 'web-1');
    $this->record = fn (array $event, ?Envelope $envelope = null): ?CustomAlert => app(RecordCustomAlertAction::class)->execute($this->project, $event, $envelope ?? $this->envelope);
    $this->topic = fn (string $topic): AlertRule => AlertRule::query()->whereForTeam($this->project->team)->whereType(AlertRuleType::CustomAlert)->where('topic', $topic)->sole();
});

describe('recording an alert', function () {
    beforeEach(function () {
        Bus::fake([SendCustomAlert::class]);
    });

    it('needs a title', function () {
        expect(($this->record)(['message' => 'no title']))->toBeNull()
            ->and(CustomAlert::count())->toBe(0);
    });

    it('goes to the default topic without one, and makes a new topic it has not seen', function () {
        expect(($this->record)(['title' => 'A'])->topic)->toBe('default')
            ->and(($this->record)(['title' => 'B', 'topic' => 'Big Payments'])->topic)->toBe('big-payments')
            ->and(($this->topic)('big-payments')->channels()->count())->toBe(0);

        Bus::assertDispatchedTimes(SendCustomAlert::class, 2);
    });

    it('drops links that are not web addresses and an unknown link style', function () {
        $alert = ($this->record)(['title' => 'A', 'url' => 'javascript:alert(1)', 'links' => [
            ['label' => 'Evil', 'url' => 'javascript:alert(1)'],
            ['label' => 'Fine', 'url' => 'https://example.test/a', 'style' => 'rainbow'],
            ['label' => 'Danger', 'url' => 'https://example.test/b', 'style' => 'danger'],
        ]]);

        expect($alert->content['url'])->toBeNull()
            ->and($alert->content['links'])->toBe([
                ['label' => 'Fine', 'url' => 'https://example.test/a', 'style' => null],
                ['label' => 'Danger', 'url' => 'https://example.test/b', 'style' => 'danger'],
            ]);
    });

    it('keeps links within what every channel takes', function () {
        $alert = ($this->record)(['title' => 'A', 'links' => [
            ['label' => str_repeat('x', 100), 'url' => 'https://example.test/a'],
            ['label' => 'Too long', 'url' => 'https://example.test/'.str_repeat('y', 512)],
        ]]);

        expect($alert->content['links'])->toHaveCount(1)
            ->and(mb_strlen($alert->content['links'][0]['label']))->toBe(75);
    });

    it('cuts a long table and says how many rows were left out', function () {
        $alert = ($this->record)(['title' => 'A', 'tables' => [['headers' => ['a'], 'rows' => array_fill(0, 25, ['x'])]]]);

        expect($alert->content['tables'][0]['rows'])->toHaveCount(20)
            ->and($alert->content['tables'][0]['omitted'])->toBe(5);
    });

    it('keeps a duplicate in the list but does not send it again', function () {
        ($this->record)(['title' => 'A', 'dedupe_key' => 'k', 'dedupe_minutes' => 10]);
        $second = ($this->record)(['title' => 'A', 'dedupe_key' => 'k', 'dedupe_minutes' => 10]);

        expect($second->suppressed)->toBe(CustomAlert::SUPPRESSED_DEDUPE);
        Bus::assertDispatchedTimes(SendCustomAlert::class, 1);
    });

    it('stops sending past the rate limit but keeps the alerts', function () {
        RateLimiter::increment('custom-alerts:'.$this->project->id, amount: RecordCustomAlertAction::PER_MINUTE);

        expect(($this->record)(['title' => 'A'])->suppressed)->toBe(CustomAlert::SUPPRESSED_RATE_LIMIT);
        Bus::assertNotDispatched(SendCustomAlert::class);
    });

    it('sends nothing when the topic is off or leaves out the environment', function (array $rule) {
        ($this->topic)('default')->update($rule);

        expect(($this->record)(['title' => 'A']))->not->toBeNull();
        Bus::assertNotDispatched(SendCustomAlert::class);
    })->with([
        'off' => [['enabled' => false]],
        'another environment' => [['environments' => ['staging']]],
    ]);
});

describe('delivering an alert', function () {
    it('records how each channel went, without channels too', function () {
        Http::fake(['*' => Http::response(null, 204)]);
        $channel = NotificationChannel::factory()->for($this->project->team)->create(['name' => '#ops']);
        ($this->topic)('default')->channels()->attach($channel);

        expect(($this->record)(['title' => 'A'])->refresh()->deliveries)->toBe([['channel' => '#ops', 'type' => 'discord', 'delivered' => true]]);

        ($this->topic)('default')->channels()->detach($channel);

        expect(($this->record)(['title' => 'B'])->refresh()->deliveries)->toBe([]);
    });

    it('names the project and environment, and keeps level, table and links in Discord', function () {
        Http::fake(['*' => Http::response(null, 204)]);
        ($this->topic)('default')->channels()->attach(NotificationChannel::factory()->for($this->project->team)->create());

        ($this->record)(['title' => 'Paid', 'level' => 'success', 'tables' => [['headers' => ['Item', 'Qty'], 'rows' => [['Prints', '2']]]], 'links' => [['label' => 'Open', 'url' => 'https://example.test/o']]]);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'with_components=true')
            && $request['embeds'][0]['color'] === 0x4CB782
            && $request['embeds'][0]['footer']['text'] === 'Shop · production'
            && str_contains($request['embeds'][0]['description'], 'Prints  2')
            && $request['components'][0]['components'][0]['url'] === 'https://example.test/o'
            && $request['allowed_mentions'] === ['parse' => []]);
    });

    it('gives a webhook the whole alert as JSON', function () {
        Http::fake(['*' => Http::response(null, 200)]);
        ($this->topic)('default')->channels()->attach(NotificationChannel::factory()->for($this->project->team)->create([
            'type' => ChannelType::Webhook, 'project_id' => $this->project->id, 'config' => ['url' => 'https://93.184.215.14/hook', 'secret' => 'whsec_x'],
        ]));

        ($this->record)(['title' => 'Paid', 'level' => 'success', 'topic' => 'default']);

        Http::assertSent(fn ($request): bool => $request['event'] === 'custom_alert');
    });

    it('styles a Telegram button', function () {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        ($this->topic)('default')->channels()->attach(NotificationChannel::factory()->for($this->project->team)->create(['type' => ChannelType::Telegram, 'config' => ['bot_token' => '123456:'.str_repeat('a', 35), 'chat_id' => '-100123']]));

        ($this->record)(['title' => 'A', 'links' => [['label' => 'Refund', 'url' => 'https://example.test/r', 'style' => 'danger']]]);

        Http::assertSent(fn ($request): bool => str_contains($request->body(), 'danger'));
    });

    it('reaches every kind of channel', function (ChannelType $type, array $config) {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        ($this->topic)('default')->channels()->attach(NotificationChannel::factory()->for($this->project->team)->create(['type' => $type, 'name' => 'x', 'config' => $config, 'project_id' => $type === ChannelType::Webhook ? $this->project->id : null]));

        $alert = ($this->record)(['title' => 'Paid', 'level' => 'critical', 'message' => 'Text', 'fields' => [['name' => 'Amount', 'value' => '499 kr']], 'tables' => [['title' => 'T', 'headers' => ['A'], 'rows' => [['1']]]], 'links' => [['label' => 'Open', 'url' => 'https://example.test/o', 'style' => 'primary']]]);

        expect($alert->refresh()->deliveries[0]['delivered'])->toBeTrue();
    })->with([
        'slack' => [ChannelType::Slack, ['webhook_url' => 'https://hooks.slack.com/services/T/B/x']],
        'teams' => [ChannelType::Teams, ['webhook_url' => 'https://93.184.215.14/workflows/x']],
        'telegram' => [ChannelType::Telegram, ['bot_token' => '123456:'.str_repeat('a', 35), 'chat_id' => '-100123']],
        'ntfy' => [ChannelType::Ntfy, ['url' => 'https://93.184.215.14/topic']],
        'pushover' => [ChannelType::Pushover, ['user_key' => str_repeat('u', 30), 'app_token' => str_repeat('a', 30)]],
        'mail' => [ChannelType::Mail, ['address' => 'ops@example.test']],
    ]);
});

describe('managing topics', function () {
    beforeEach(function () {
        $this->admin = User::factory()->inTeam($this->project->team, TeamRole::Admin)->create();
        $this->member = User::factory()->inTeam($this->project->team, TeamRole::Member)->create();
    });

    it('lets an admin create a topic ahead of time, once', function () {
        $this->actingAs($this->admin)->post(route('alerts.topics.store'), ['name' => 'New Signups'])->assertSessionHasNoErrors();

        expect(($this->topic)('new-signups')->enabled)->toBeTrue();

        $this->actingAs($this->admin)->post(route('alerts.topics.store'), ['name' => 'new-signups'])->assertSessionHasErrors('name');
    });

    it('keeps members from creating or deleting topics', function () {
        $this->actingAs($this->member)->post(route('alerts.topics.store'), ['name' => 'x'])->assertForbidden();
        $this->actingAs($this->member)->delete(route('alerts.topics.destroy', ($this->topic)('default')))->assertForbidden();
    });

    it('deletes a topic and keeps its sent alerts', function () {
        Bus::fake([SendCustomAlert::class]);
        $alert = ($this->record)(['title' => 'A', 'topic' => 'pay']);

        $this->actingAs($this->admin)->delete(route('alerts.topics.destroy', ($this->topic)('pay')))->assertSessionHasNoErrors();

        expect(AlertRule::query()->where('topic', 'pay')->exists())->toBeFalse()
            ->and($alert->refresh()->topic)->toBe('pay')
            ->and($alert->alert_rule_id)->toBeNull();
    });

    it('lets a project customize a topic, which then decides for that project', function () {
        ($this->topic)('default')->channels()->attach(NotificationChannel::factory()->for($this->project->team)->create());

        $this->actingAs($this->admin)->post(route('alerts.rules.customize', ['project' => $this->project, 'type' => 'custom_alert', 'topic' => 'default']))->assertRedirect();

        $own = $this->project->alertRules()->sole();
        expect($own->topic)->toBe('default')->and($own->channels()->count())->toBe(1);

        Bus::fake([SendCustomAlert::class]);
        expect(($this->record)(['title' => 'A'])->alert_rule_id)->toBe($own->id);

        $own->update(['enabled' => false]);
        ($this->record)(['title' => 'B']);
        Bus::assertDispatchedTimes(SendCustomAlert::class, 1);
    });

    it('takes a project\'s version of a topic with it when the team deletes the topic', function () {
        Bus::fake([SendCustomAlert::class]);
        ($this->record)(['title' => 'A', 'topic' => 'pay']);
        $this->actingAs($this->admin)->post(route('alerts.rules.customize', ['project' => $this->project, 'type' => 'custom_alert', 'topic' => 'pay']));

        $this->actingAs($this->admin)->delete(route('alerts.topics.destroy', ($this->topic)('pay')))->assertSessionHasNoErrors();

        expect(AlertRule::query()->where('topic', 'pay')->exists())->toBeFalse();
    });

    it('will not delete the default topic or a rule of another kind', function () {
        $this->actingAs($this->admin)->delete(route('alerts.topics.destroy', ($this->topic)('default')))->assertSessionHasErrors('topic');
        $rule = AlertRule::query()->whereForTeam($this->project->team)->whereType(AlertRuleType::NewIssue)->sole();
        $this->actingAs($this->admin)->delete(route('alerts.topics.destroy', $rule))->assertSessionHasErrors('topic');

        expect($rule->exists)->toBeTrue();
    });
});
