<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Support;

use App\Domains\DataSources\Models\DataSource;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Mcp\Models\McpToken;
use App\Domains\Projects\Models\Project;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Support\WidgetSchema;
use App\Domains\Teams\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A screen mixes projects, and a token covers some: it may only see or make a screen
 * showing nothing beyond the projects whose telemetry and issues it reads, and only the
 * health checks, data sources, alerts and tasks of projects where it reads those.
 */
class ScreenAccess
{
    public function covers(McpToken $token, Screen $screen): bool
    {
        return $this->coversDesign($token, $screen->team, $screen->scope, $screen->widgets);
    }

    /**
     * @param  array<string, mixed>  $scope  the screen's default scope
     * @param  array<int, array<string, mixed>>  $widgets
     */
    public function coversDesign(McpToken $token, Team $team, array $scope, array $widgets): bool
    {
        $teamProjects = $team->projects()->pluck('id', 'uuid');
        $needed = collect();
        // Projects whose data a widget shows from another area, by the tool that reads it there.
        $byTool = [];
        $picked = ['data_source' => [], 'checks' => []];

        foreach ($widgets as $widget) {
            $type = (string) ($widget['type'] ?? '');
            $options = [(array) ($widget['options'] ?? [])];

            foreach (WidgetSchema::isDataType($type) ? WidgetSchema::rotation($widget) : [] as $item) {
                $options[] = $item['options'];

                if (in_array($item['source'], ['external_json', 'calendar'], true)) {
                    continue;
                }

                // As the renderer reads it: strings only, and none means every project.
                $projects = array_values(array_filter((array) ($item['scope']['projects'] ?? $widget['scope']['projects'] ?? $scope['projects'] ?? []), is_string(...)));
                $ids = $projects === [] ? $teamProjects->values() : $teamProjects->only($projects)->values();
                $needed = $needed->merge($ids);

                foreach (self::toolsFor($type, (string) $item['source']) as $tool) {
                    $byTool[$tool] = ($byTool[$tool] ?? collect())->merge($ids);
                }
            }

            // A Wi-Fi password or a signed media link belongs to no project: only a token covering them all reads it.
            if (! empty($widget['options']['password']) || ! empty($widget['options']['media_url'])) {
                $needed = $needed->merge($teamProjects->values());
            }

            foreach ($options as $given) {
                $picked['data_source'][] = $given['data_source'] ?? null;
                $picked['checks'] = [...$picked['checks'], ...(array) ($given['checks'] ?? [])];
            }
        }

        $readable = $token->readableProjects();
        $where = fn (string $tool): array => $readable->filter(fn (Project $project): bool => $token->permits($tool, $project))->modelKeys();
        $outside = fn (Collection $projects, array $covered): bool => $projects->intersect($teamProjects->values())->diff($covered)->isNotEmpty();

        $byTool['list_data_sources'] = ($byTool['list_data_sources'] ?? collect())->merge(DataSource::query()->whereIn('uuid', $this->uuids($picked['data_source']))->pluck('project_id'));
        $byTool['list_health_checks'] = ($byTool['list_health_checks'] ?? collect())->merge(HealthCheck::query()->whereIn('uuid', $this->uuids($picked['checks']))->pluck('project_id'));

        if ($outside($needed, $this->coveredProjects($token))) {
            return false;
        }

        foreach ($byTool as $tool => $projects) {
            if ($outside($projects, $where($tool))) {
                return false;
            }
        }

        return true;
    }

    /**
     * The tools a widget's source reads beyond metrics and issues: its data belongs to
     * those areas, so the token must be allowed them in the projects it shows.
     *
     * @return list<string>
     */
    private static function toolsFor(string $type, string $source): array
    {
        return match (true) {
            // The overview judges queue backlogs by the alert rules.
            $type === 'overview' => ['list_health_checks', 'list_alert_rules'],
            $type === 'health', in_array($source, ['health_uptime', 'db_size', 'db_connections'], true) => ['list_health_checks'],
            $source === 'alert_feed' => ['list_alert_rules', 'list_notification_channels'],
            $source === 'scheduled_tasks' => ['list_scheduled_tasks'],
            default => [],
        };
    }

    /**
     * The projects a screen may show for the token: those it reads, where it may also read
     * their metrics and issues, since widgets show both.
     *
     * @return array<int, int|string>
     */
    public function coveredProjects(McpToken $token): array
    {
        return $token->readableProjects()
            ->filter(fn (Project $project): bool => $token->permits('get_metric_series', $project) && $token->permits('list_issues', $project))
            ->modelKeys();
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function uuids(array $values): array
    {
        return array_values(array_filter($values, fn (mixed $value): bool => is_string($value) && Str::isUuid($value)));
    }
}
