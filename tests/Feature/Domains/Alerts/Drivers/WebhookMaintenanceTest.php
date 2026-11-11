<?php

declare(strict_types=1);

use App\Domains\Alerts\Actions\SaveNotificationChannelAction;
use App\Domains\Alerts\Data\HealthCheckAlert;
use App\Domains\Alerts\Data\IssueAlert;
use App\Domains\Alerts\Drivers\WebhookDriver;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Enums\ChannelType;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Issues\Models\Issue;
use App\Domains\Maintenance\Models\MaintenanceWindow;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

covers(WebhookDriver::class, IssueAlert::class, HealthCheckAlert::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00'));
    config(['alerts.webhooks.allow_private' => false]);
    Http::fake(['93.184.215.14/*' => Http::response(null, 204)]);
    $this->issue = Issue::factory()->create();
    $this->project = $this->issue->project;
    $this->project->update(['name' => 'Shop']);
    $this->channel = app(SaveNotificationChannelAction::class)->execute($this->project->team, ChannelType::Webhook, 'App', ['url' => 'https://93.184.215.14/lumberjack/webhook'], project: $this->project);
    $this->alert = fn (): IssueAlert => new IssueAlert($this->issue->refresh(), AlertRuleType::NewIssue, 'production', 'https://lumberjack.test/x');
    $this->body = function (): array {
        $sent = Http::recorded()->map(fn (array $pair) => $pair[0])->first(fn (Request $request): bool => $request->url() === 'https://93.184.215.14/lumberjack/webhook');

        return json_decode($sent->body(), true);
    };
});

describe('an alert sent during a maintenance window', function () {
    it('marks its headline and carries the window in the webhook', function () {
        $window = MaintenanceWindow::factory()->of($this->project)->create(['message' => 'Upgrading the database']);
        $alert = ($this->alert)();

        app(WebhookDriver::class)->send($this->channel, $alert);

        expect($alert->headline())->toStartWith('[Under maintenance] ')
            ->and(($this->body)()['maintenance'])->toBe([
                'id' => $window->uuid,
                'started_at' => $window->starts_at->toIso8601String(),
                'message' => 'Upgrading the database',
            ]);
    });

    it('is marked by a window of every environment', function () {
        MaintenanceWindow::factory()->of($this->project, '')->create();

        expect(($this->alert)()->headline())->toStartWith('[Under maintenance] ');
    });

    it('marks a health alert of a check in the window\'s environment', function () {
        MaintenanceWindow::factory()->of($this->project)->create();
        $check = HealthCheck::factory()->for($this->project)->create(['environment' => 'production']);

        $alert = new HealthCheckAlert($check, HealthCheckAlert::DOWN, 'Connection refused', [], now()->toImmutable(), 'https://lumberjack.test/health');

        expect($alert->headline())->toBe('[Under maintenance] Health check down in Shop')
            ->and($alert->maintenance()?->project_id)->toBe($this->project->id);
    });
});

describe('an alert sent outside a maintenance window', function () {
    it('carries a null maintenance and an unmarked headline', function (?MaintenanceWindow $window) {
        $alert = ($this->alert)();

        app(WebhookDriver::class)->send($this->channel, $alert);

        $body = ($this->body)();
        expect($alert->headline())->not->toContain('[Under maintenance]')
            ->and($body)->toHaveKey('maintenance')
            ->and($body['maintenance'])->toBeNull();
    })->with([
        'no window at all' => [fn () => null],
        'a window of another environment' => [fn () => MaintenanceWindow::factory()->of($this->project, 'staging')->create()],
        'a window that has ended' => [fn () => MaintenanceWindow::factory()->of($this->project)->ended()->create()],
        'a planned window not started yet' => [fn () => MaintenanceWindow::factory()->of($this->project)->planned()->create()],
    ]);
});
