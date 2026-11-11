<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Concerns;

use App\Domains\DataSources\Models\DataSource;
use App\Domains\Health\Http\Requests\SaveHealthCheckRequest;
use App\Domains\Health\Models\HealthCheck;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

trait UsesHealthChecks
{
    use UsesMcpContext;

    protected function checkArgument(JsonSchema $schema): mixed
    {
        return $schema->string()->description('The check\'s id; see list_health_checks.')->required();
    }

    /**
     * The check the request names within its project, if the token's maker may do $ability on it.
     */
    protected function healthCheck(Request $request, string $ability = 'view'): HealthCheck|Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        $uuid = $request->get('check');
        $check = is_string($uuid) && Str::isUuid($uuid) ? $project->healthChecks()->where('uuid', $uuid)->first() : null;

        if ($check === null) {
            return Response::error('Unknown check in project '.$project->slug.'; see list_health_checks.');
        }

        return $this->authorize($ability, $check) ?? $check;
    }

    /**
     * A check's settings, without its ping URL (a secret) or credentials in its URL.
     *
     * @return array<string, mixed>
     */
    protected function presentCheck(HealthCheck $check): array
    {
        $config = $check->config ?? [];

        if (isset($config['url']) && is_string($config['url'])) {
            $config['url'] = DataSource::maskQuery((string) preg_replace('#^([a-z]+://)[^/@]*@#i', '$1***@', $config['url']));
        }

        return [
            'id' => $check->uuid,
            'name' => $check->displayName(),
            'source' => $check->source->value,
            'type' => $check->type?->value,
            'slug' => $check->slug,
            'environment' => $check->environment !== '' ? $check->environment : null,
            'description' => $check->description,
            'interval_minutes' => $check->interval_minutes,
            'degrade_after' => $check->degrade_after,
            'down_after' => $check->downAfter(),
            'recover_after' => $check->recover_after,
            'muted' => $check->muted,
            'archived' => $check->archived_at !== null,
            'status' => $check->status?->value,
            'last_result_at' => $check->last_result_at?->toIso8601String(),
            'last_ping_at' => $check->last_ping_at?->toIso8601String(),
            'config' => array_intersect_key($config, array_flip(SaveHealthCheckRequest::CONFIG_KEYS[$check->type?->value] ?? [])),
        ];
    }

    /**
     * The settings every check can take, plus the type's own under config.
     *
     * @return array<string, mixed>
     */
    protected function settingsSchema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Optional, except for a heartbeat or a data_source check; without one a check is named from its settings.'),
            'description' => $schema->string(),
            'environment' => $schema->string()->description('A label, e.g. production.'),
            'interval_minutes' => $schema->integer()->enum(HealthCheck::INTERVALS)->description('How often it runs.'),
            'degrade_after' => $schema->integer()->description('Bad results in a row before degraded, 1 to 100 (2).'),
            'down_after' => $schema->integer()->description('Failed results in a row before down, 1 to 100, at least degrade_after (5).'),
            'recover_after' => $schema->integer()->description('Good results in a row before ok again, 1 to 100 (5).'),
            'muted' => $schema->boolean()->description('Muted checks send no alerts.'),
            'config' => $schema->object()->description(
                'The type\'s settings, defaults in parentheses. '
                .'http: url (required), method (GET, HEAD, POST; GET), expected_status (200), body_contains, json_path, json_expected, max_latency_ms (warn when slower), timeout_s (10). '
                .'ssl: host (required), port (443), warn_days (14), fail_days (3). '
                .'tcp: host, port (both required), timeout_s (5). '
                .'dns: name, record_type (A, AAAA, CNAME, MX, TXT; both required), expected. '
                .'heartbeat: grace_minutes (5). '
                .'domain: domain (required), warn_days (30), fail_days (7). '
                .'smtp: host, port (both required), encryption (STARTTLS, TLS, None; STARTTLS), timeout_s (10). '
                .'data_source: data_source (uuid of a data source of the project with a number: json, prometheus, or a connected one of shape number; required), path (JSONPath, or a Prometheus selector; required for json and prometheus), period (1h, 24h, 7d, 30d, as the connection answers for; required for a connected source, which the check then keeps pulled); operator (>, >=, <, <=, =, !=; >), warn, fail (numbers; the value breaching fail fails, breaching warn warns). Judged after each fetch of the source; fails when the source fails or is not fetched in time.'
            ),
        ];
    }
}
