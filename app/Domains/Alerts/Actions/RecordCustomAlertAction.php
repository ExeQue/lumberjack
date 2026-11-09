<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Actions;

use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Enums\CustomAlertLevel;
use App\Domains\Alerts\Jobs\SendCustomAlert;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\CustomAlert;
use App\Domains\Ingest\Data\Envelope;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Keeps an alert an app sent and queues it for the channels of its topic. Anything an
 * app sends is cut down to what every channel can carry.
 */
class RecordCustomAlertAction
{
    public const int PER_MINUTE = 60;

    private const int MAX_FIELDS = 20;

    private const int MAX_TABLES = 3;

    private const int MAX_COLUMNS = 8;

    private const int MAX_ROWS = 20;

    /** Discord's limit for one row of link buttons. */
    private const int MAX_LINKS = 5;

    /** Slack's limit for a button's text, the shortest of the channels. */
    private const int MAX_LINK_LABEL = 75;

    /** Discord refuses a whole message over a longer button URL, and Pushover takes no more. */
    private const int MAX_URL = 512;

    /**
     * @param  array<string, mixed>  $event
     */
    public function execute(Project $project, array $event, Envelope $envelope): ?CustomAlert
    {
        $title = Envelope::short($event['title'] ?? null, 255);

        if ($title === null) {
            return null;
        }

        $topic = Str::limit(Str::slug((string) Envelope::short($event['topic'] ?? null, 64)), 64, '') ?: AlertRule::DEFAULT_TOPIC;
        $rule = AlertRule::query()->whereEffectiveFor($project)->whereType(AlertRuleType::CustomAlert)->where('topic', $topic)->first()
            ?? AlertRule::query()->firstOrCreate(
                ['team_id' => $project->team_id, 'type' => AlertRuleType::CustomAlert, 'topic' => $topic],
                ['enabled' => true],
            );

        $alert = CustomAlert::query()->create([
            'project_id' => $project->id,
            'alert_rule_id' => $rule->id,
            'topic' => $topic,
            'level' => CustomAlertLevel::tryFrom((string) Envelope::short($event['level'] ?? null)) ?? CustomAlertLevel::Info,
            'title' => $title,
            'message' => Envelope::short($event['message'] ?? null, 2000),
            'content' => [
                'url' => self::url($event['url'] ?? null),
                'fields' => $this->fields($event['fields'] ?? null),
                'tables' => $this->tables($event['tables'] ?? null),
                'links' => $this->links($event['links'] ?? null),
            ],
            'environment' => $envelope->environment,
            'host' => $envelope->host,
            'release' => $envelope->release,
            'suppressed' => $this->suppressed($project, $event),
            'occurred_at' => Envelope::time($event['timestamp'] ?? null),
        ]);

        if ($alert->suppressed === null && $rule->enabled && $rule->appliesToEnvironment($envelope->environment)) {
            SendCustomAlert::dispatch($alert, $rule);
        }

        return $alert;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function suppressed(Project $project, array $event): ?string
    {
        $key = Envelope::short($event['dedupe_key'] ?? null, 255);
        $minutes = is_numeric($event['dedupe_minutes'] ?? null) ? max(1, min(1440, (int) $event['dedupe_minutes'])) : 0;

        if ($key !== null && $minutes > 0 && ! Cache::add('custom-alert-dedupe:'.$project->id.':'.hash('xxh128', $key), true, $minutes * 60)) {
            return CustomAlert::SUPPRESSED_DEDUPE;
        }

        return RateLimiter::attempt('custom-alerts:'.$project->id, self::PER_MINUTE, fn (): bool => true) ? null : CustomAlert::SUPPRESSED_RATE_LIMIT;
    }

    /** @return list<array{name: string, value: string, inline: bool}> */
    private function fields(mixed $fields): array
    {
        $kept = [];

        foreach (array_slice(is_array($fields) ? array_values(array_filter($fields, 'is_array')) : [], 0, self::MAX_FIELDS) as $field) {
            $name = Envelope::short($field['name'] ?? null, 256);
            $value = Envelope::short($field['value'] ?? null, 1024);

            if ($name !== null && $value !== null) {
                $kept[] = ['name' => $name, 'value' => $value, 'inline' => ($field['inline'] ?? true) !== false];
            }
        }

        return $kept;
    }

    /** @return list<array{title: ?string, headers: list<string>, rows: list<list<string>>, omitted: int}> */
    private function tables(mixed $tables): array
    {
        $kept = [];

        foreach (array_slice(is_array($tables) ? array_values(array_filter($tables, 'is_array')) : [], 0, self::MAX_TABLES) as $table) {
            $headers = array_map(fn (mixed $cell): string => (string) Envelope::short($cell, 100), array_slice(array_values((array) ($table['headers'] ?? [])), 0, self::MAX_COLUMNS));
            $rows = array_values(array_filter((array) ($table['rows'] ?? []), 'is_array'));

            if ($headers === []) {
                continue;
            }

            $kept[] = [
                'title' => Envelope::short($table['title'] ?? null, 256),
                'headers' => $headers,
                'rows' => array_map(fn (array $row): array => array_map(fn (mixed $cell): string => (string) Envelope::short($cell, 200), array_slice(array_values($row), 0, count($headers))), array_slice($rows, 0, self::MAX_ROWS)),
                'omitted' => max(0, count($rows) - self::MAX_ROWS),
            ];
        }

        return $kept;
    }

    /** @return list<array{label: string, url: string, style: ?string}> */
    private function links(mixed $links): array
    {
        $kept = [];

        foreach (is_array($links) ? array_filter($links, 'is_array') : [] as $link) {
            $label = Envelope::short($link['label'] ?? null, self::MAX_LINK_LABEL);
            $url = self::url($link['url'] ?? null);

            if ($label !== null && $url !== null && count($kept) < self::MAX_LINKS) {
                $kept[] = ['label' => $label, 'url' => $url, 'style' => in_array($link['style'] ?? null, ['primary', 'danger'], true) ? $link['style'] : null];
            }
        }

        return $kept;
    }

    /** Only web addresses: a javascript: link in a mail or chat message must never get through. */
    private static function url(mixed $url): ?string
    {
        if (! is_string($url) || strlen($url) > self::MAX_URL) {
            return null;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false && preg_match('~^https?://~i', $url) === 1 ? $url : null;
    }
}
