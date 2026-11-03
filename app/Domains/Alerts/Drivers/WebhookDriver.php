<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Drivers;

use App\Domains\Alerts\Contracts\Alert;
use App\Domains\Alerts\Contracts\ChannelDriver;
use App\Domains\Alerts\Data\BulkStatusChangeAlert;
use App\Domains\Alerts\Data\HealthCheckAlert;
use App\Domains\Alerts\Data\IssueAlert;
use App\Domains\Alerts\Data\QueueBacklogAlert;
use App\Domains\Alerts\Drivers\Concerns\SendsHttp;
use App\Domains\Alerts\Exceptions\AlertDeliveryFailedException;
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
            ]);

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
            ]);

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
            ]);

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
        ]);
    }

    #[Override]
    public function sendTest(NotificationChannel $channel): void
    {
        $this->post($channel, ['event' => 'test', 'message' => __('alerts.test_message', ['channel' => $channel->name])]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post(NotificationChannel $channel, array $payload): void
    {
        $url = (string) ($channel->config['url'] ?? '');

        // Checked again on every send: the host may resolve somewhere else by now.
        if (! PublicUrl::allows($url)) {
            throw AlertDeliveryFailedException::notPublic($channel->name);
        }

        $body = (string) json_encode(['version' => self::VERSION, 'sent_at' => now()->toIso8601String(), ...$payload], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = (string) now()->getTimestamp();

        $this->deliver($channel, fn (PendingRequest $http) => $http
            ->withoutRedirecting()
            ->withHeaders([
                'User-Agent' => 'Lumberjack-Webhook/'.self::VERSION,
                'X-Lumberjack-Event' => (string) $payload['event'],
                'X-Lumberjack-Timestamp' => $timestamp,
                'X-Lumberjack-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, (string) ($channel->config['secret'] ?? '')),
            ])
            ->withBody($body, 'application/json')
            ->post($url));
    }
}
