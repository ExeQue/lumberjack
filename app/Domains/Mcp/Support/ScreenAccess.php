<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Support;

use App\Domains\DataSources\Models\DataSource;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Mcp\Models\McpToken;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Support\WidgetSchema;
use App\Domains\Teams\Models\Team;
use Illuminate\Support\Str;

/**
 * A screen mixes projects, and a token covers some: it may only see or make a screen
 * showing nothing beyond its projects.
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
        $picked = ['data_source' => [], 'checks' => []];

        foreach ($widgets as $widget) {
            $type = (string) ($widget['type'] ?? '');
            $options = [(array) ($widget['options'] ?? [])];

            foreach (WidgetSchema::isDataType($type) ? WidgetSchema::rotation($widget) : [] as $item) {
                $options[] = $item['options'];

                if (in_array($item['source'], ['external_json', 'calendar'], true)) {
                    continue;
                }

                $projects = (array) ($item['scope']['projects'] ?? $widget['scope']['projects'] ?? $scope['projects'] ?? []);
                $needed = $needed->merge($projects === [] ? $teamProjects->values() : $teamProjects->only($projects)->values());
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

        $needed = $needed
            ->merge(DataSource::query()->whereIn('uuid', $this->uuids($picked['data_source']))->pluck('project_id'))
            ->merge(HealthCheck::query()->whereIn('uuid', $this->uuids($picked['checks']))->pluck('project_id'));

        return $needed->intersect($teamProjects->values())->diff($token->readableProjects()->modelKeys())->isEmpty();
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
