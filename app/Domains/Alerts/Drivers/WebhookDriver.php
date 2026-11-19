<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Drivers;

use App\Domains\Ai\Enums\AiBudgetBasis;
use App\Domains\Ai\Enums\AiBudgetScope;
use App\Domains\Alerts\Contracts\Alert;
use App\Domains\Alerts\Contracts\ChannelDriver;
use App\Domains\Alerts\Data\AiBudgetAlert;
use App\Domains\Alerts\Data\AiErrorRateAlert;
use App\Domains\Alerts\Data\BulkStatusChangeAlert;
use App\Domains\Alerts\Data\CustomAlertMessage;
use App\Domains\Alerts\Data\HealthCheckAlert;
use App\Domains\Alerts\Data\IssueAlert;
use App\Domains\Alerts\Data\MaintenanceOverrunAlert;
use App\Domains\Alerts\Data\QueueBacklogAlert;
use App\Domains\Alerts\Data\WeeklySummaryAlert;
use App\Domains\Alerts\Drivers\Concerns\SendsHttp;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Issues\Models\Issue;
use App\Domains\Shared\Rules\PublicUrl;
use Illuminate\Http\Client\PendingRequest;
use Override;

/**
 * POSTs each alert as JSON to the app's own endpoint, so it can react, e.g.
 * turn a status banner on while an issue escalates. Signed with the channel's
 * secret: X-Lumberjack-Signature is "sha256=" and the HMAC of
 * "{X-Lumberjack-Timestamp}.{body}".
 */
class WebhookDriver implements ChannelDriver
{
    use SendsHttp;

    public const string VERSION = '1';

    #[Override]
    public function rules(): array
    {
        return [
            'url' => ['required', 'url', 'max:2000', new PublicUrl()],
        ];
    }

    #[Override]
    public function send(NotificationChannel $channel, Alert $alert): void
    {
        if ($alert instanceof CustomAlertMessage) {
            $custom = $alert->alert;

            $this->post($channel, [
                'event' => 'custom_alert',
                'project' => ['id' => $custom->project->uuid, 'slug' => $custom->project->slug, 'name' => $custom->project->name],
                'environment' => $custom->environment,
                'alert' => [
                    'topic' => $custom->topic,
                    'level' => $custom->level->value,
                    'title' => $custom->title,
                    'message' => $custom->message,
                    'host' => $custom->host,
                    'release' => $custom->release,
                    'occurred_at' => $custom->occurred_at->toIso8601String(),
                    ...$custom->content,
                ],
            ], $alert);

            return;
        }

        if ($alert instanceof QueueBacklogAlert) {
            $this->post($channel, [
                'event' => 'queue_backlog',
                'project' => ['id' => $alert->project->uuid, 'slug' => $alert->project->slug, 'name' => $alert->project->name],
                'queue' => [
                    'connection' => $alert->connection,
                    'name' => $alert->queue,
                    'state' => $alert->recovered ? 'recovered' : 'started',
                    'size' => $alert->size,
                    'oldest_age_s' => $alert->oldestAgeSeconds,
                    'since' => $alert->since->toIso8601String(),
                    'url' => $alert->url,
                ],
                'thresholds' => $alert->settings->toArray(),
            ], $alert);

            return;
        }

        if ($alert instanceof HealthCheckAlert) {
            $check = $alert->check;

            $this->post($channel, [
                // The rule that sent it: health_degraded, health_down or health_recovered.
                'event' => 'health_'.$alert->state,
                'project' => ['id' => $check->project->uuid, 'slug' => $check->project->slug, 'name' => $check->project->name],
                'environment' => $check->environment !== '' ? $check->environment : null,
                'check' => [
                    'id' => $check->uuid,
                    'name' => $check->displayName(),
                    'slug' => $check->slug,
                    'source' => $check->source->value,
                    'type' => $check->type?->value,
                    'state' => $alert->state,
                    'message' => $alert->message,
                    'hosts' => $alert->hosts,
                    'since' => $alert->since->toIso8601String(),
                    'url' => $alert->url,
                ],
            ], $alert);

            return;
        }

        if ($alert instanceof MaintenanceOverrunAlert) {
            $window = $alert->window;

            $this->post($channel, [
                'event' => AlertRuleType::MaintenanceOverrun->value,
                'project' => ['id' => $window->project->uuid, 'slug' => $window->project->slug, 'name' => $window->project->name],
                'environment' => $window->environment !== '' ? $window->environment : null,
                'window' => [
                    ...$window->toSummary(),
                    // overrun while it is open, ended once the end came after an overrun alert.
                    'state' => $alert->ended() ? 'ended' : 'overrun',
                    'overrun_minutes' => $alert->overrunMinutes(),
                    'url' => $alert->url,
                ],
            ], $alert);

            return;
        }

        if ($alert instanceof AiBudgetAlert) {
            $budget = $alert->budget;

            $this->post($channel, [
                'event' => AlertRuleType::AiBudget->value,
                'project' => ['id' => $budget->project->uuid, 'slug' => $budget->project->slug, 'name' => $budget->project->name],
                'budget' => [
                    'id' => $budget->uuid,
                    'name' => $budget->title(),
                    'scope' => $budget->scope->value,
                    // What it counts, by its scope: keys (never a fingerprint), or owners or actors by type and id.
                    'keys' => array_map(fn (string $fingerprint): array => [
                        'id' => $budget->keys()->get($fingerprint)?->uuid,
                        'short_id' => substr($fingerprint, 0, 8),
                        'name' => $budget->keys()->get($fingerprint)?->name,
                        'label' => $budget->keys()->get($fingerprint)?->label,
                    ], $budget->fingerprints()),
                    'owners' => $budget->scope === AiBudgetScope::Owner ? $budget->references() : [],
                    'actors' => $budget->scope === AiBudgetScope::Actor ? $budget->references() : [],
                    'period' => $budget->period->value,
                    'basis' => $budget->basis->value,
                    'currency' => $budget->basis === AiBudgetBasis::Cost ? $budget->project->team->currency : null,
                    // The share passed, in percent; null when it is on course to go over.
                    'threshold' => $alert->threshold,
                    ...$alert->usage->toArray(),
                    // A period that ended has no pace left.
                    'projected' => $alert->usage->ended() ? null : $alert->usage->projected(),
                    'url' => $alert->url,
                ],
            ], $alert);

            return;
        }

        if ($alert instanceof AiErrorRateAlert) {
            $this->post($channel, [
                'event' => AlertRuleType::AiErrorRate->value,
                'project' => ['id' => $alert->project->uuid, 'slug' => $alert->project->slug, 'name' => $alert->project->name],
                'key' => [
                    'id' => $alert->key?->uuid,
                    'short_id' => substr($alert->fingerprint, 0, 8),
                    'name' => $alert->key?->name,
                    'label' => $alert->key?->label,
                    'provider' => $alert->key?->provider,
                    'state' => $alert->recovered ? 'recovered' : 'started',
                    'calls' => $alert->calls,
                    'errors' => $alert->errors,
                    'since' => $alert->since->toIso8601String(),
                    'url' => $alert->url,
                ],
                'thresholds' => $alert->settings->toArray(),
            ], $alert);

            return;
        }

        if ($alert instanceof WeeklySummaryAlert) {
            $this->post($channel, [
                'event' => AlertRuleType::WeeklySummary->value,
                'period' => ['from' => $alert->from->toIso8601String(), 'to' => $alert->to->toIso8601String()],
                'projects' => $alert->projects,
                'url' => $alert->url,
            ], $alert);

            return;
        }

        if ($alert instanceof BulkStatusChangeAlert) {
            $this->post($channel, [
                'event' => 'bulk_status_change',
                'project' => ['id' => $alert->project->uuid, 'slug' => $alert->project->slug, 'name' => $alert->project->name],
                'status' => $alert->status->value,
                'actor' => $alert->actor,
                'issues' => array_map(fn (Issue $issue): array => [
                    'id' => $issue->uuid,
                    'reference' => $issue->number !== null ? $issue->reference() : null,
                    'title' => $issue->title,
                ], $alert->issues),
                'url' => $alert->url,
            ], $alert);

            return;
        }

        if (! $alert instanceof IssueAlert) {
            return;
        }

        $issue = $alert->issue;

        $this->post($channel, [
            'event' => $alert->type->value,
            'project' => ['id' => $issue->project->uuid, 'slug' => $issue->project->slug, 'name' => $issue->project->name],
            'environment' => $alert->environment,
            'issue' => [
                'id' => $issue->uuid,
                'reference' => $issue->number !== null ? $issue->reference() : null,
                'title' => $issue->title,
                'message' => $issue->message,
                'status' => $issue->status->value,
                'source' => $issue->source->value,
                'escalating' => $issue->isEscalating(),
                'occurrences' => $issue->occurrences_count,
                'first_seen_at' => $issue->first_seen_at->toIso8601String(),
                'last_seen_at' => $issue->last_seen_at->toIso8601String(),
                'url' => $alert->url,
            ],
            'burst' => $alert->burst,
            'status_change' => $alert->statusChange !== null ? ['status' => $alert->statusChange->value, 'by' => $alert->actor] : null,
            'note' => $alert->note !== null ? ['by' => $alert->noteAuthor(), 'body' => $alert->noteBody()] : null,
        ], $alert);
    }

    #[Override]
    public function sendTest(NotificationChannel $channel): void
    {
        $this->post($channel, ['event' => 'test', 'message' => __('alerts.test_message', ['channel' => $channel->name])]);
    }

    /**
     * The window an alert was sent during, null outside one.
     *
     * @return array{id: string, started_at: string, message: string|null}|null
     */
    public static function maintenance(Alert $alert): ?array
    {
        $window = $alert->maintenance();

        return $window === null ? null : ['id' => $window->uuid, 'started_at' => $window->starts_at->toIso8601String(), 'message' => $window->message];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  Alert|null  $alert  the alert it carries, for its maintenance object; none on a test
     */
    private function post(NotificationChannel $channel, array $payload, ?Alert $alert = null): void
    {
        $url = (string) ($channel->config['url'] ?? '');

        $body = (string) json_encode([
            'version' => self::VERSION,
            'sent_at' => now()->toIso8601String(),
            ...$payload,
            ...($alert !== null ? ['maintenance' => self::maintenance($alert)] : []),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = (string) now()->getTimestamp();

        // Checked again on every send, and pinned: the host may resolve somewhere else by now.
        $this->deliver($channel, fn (PendingRequest $http) => $http
            ->withHeaders([
                'User-Agent' => 'Lumberjack-Webhook/'.self::VERSION,
                'X-Lumberjack-Event' => (string) $payload['event'],
                'X-Lumberjack-Timestamp' => $timestamp,
                'X-Lumberjack-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, (string) ($channel->config['secret'] ?? '')),
            ])
            ->withBody($body, 'application/json')
            ->post($url), pinTo: $url);
    }
}
