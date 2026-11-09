<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\DataSources\Models\DataSource;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Support\ScreenFile;
use App\Domains\Screens\Support\ScreenLayout;

class ExportScreenAction
{
    /**
     * The screen as a portable file (ScreenFile): its grid, settings and widgets, projects
     * and health checks named by their keys instead of uuids. Nothing that grants access
     * goes in: no share key, no displays, no ids of records.
     *
     * @return array{format: string, version: int, exported_at: string, screen: array<string, mixed>}
     */
    public function execute(Screen $screen): array
    {
        $projects = $screen->team->projects()->pluck('slug', 'uuid')->all();
        $slugs = $screen->team->projects()->pluck('slug', 'id')->all();
        $checks = HealthCheck::query()->whereIn('project_id', array_keys($slugs))->get()
            ->mapWithKeys(fn (HealthCheck $c): array => [$c->uuid => ScreenFile::checkKey($c, (string) $slugs[$c->project_id])])
            ->all();

        // ponytail: a uuid of a record gone since is left out, like the designer does.
        $scope = fn (array $scope): array => array_key_exists('projects', $scope)
            ? [...$scope, 'projects' => array_values(array_filter(array_map(fn (mixed $uuid): ?string => $projects[$uuid] ?? null, (array) $scope['projects'])))]
            : $scope;
        $connected = DataSource::query()->whereIn('project_id', array_keys($slugs))->whereConnected()->get()
            ->mapWithKeys(fn (DataSource $s): array => [$s->uuid => ['project' => (string) $slugs[$s->project_id], 'environment' => $s->environment, 'key' => $s->key]])
            ->all();
        $options = fn (array $options): array => [
            ...$options,
            ...(array_key_exists('checks', $options) ? ['checks' => array_values(array_filter(array_map(fn (mixed $uuid): ?array => $checks[$uuid] ?? null, (array) $options['checks'])))] : []),
            // A connected source has the same key wherever the app is connected.
            ...(is_string($options['data_source'] ?? null) && isset($connected[$options['data_source']]) ? ['data_source' => $connected[$options['data_source']]] : []),
        ];
        $portable = fn (array $item): array => [
            ...$item,
            ...(isset($item['scope']) ? ['scope' => $scope((array) $item['scope'])] : []),
            ...(isset($item['options']) ? ['options' => $options((array) $item['options'])] : []),
        ];

        return [
            'format' => ScreenFile::FORMAT,
            'version' => ScreenFile::VERSION,
            'exported_at' => now()->toIso8601String(),
            'screen' => [
                'name' => $screen->name,
                'cols' => $screen->cols,
                'rows' => $screen->rows,
                'aspect' => $screen->aspect->value,
                'orientation' => $screen->orientation->value,
                'fit' => $screen->fit->value,
                'show_header' => $screen->show_header,
                'show_clock' => $screen->show_clock,
                'clock_format' => $screen->clock_format,
                'theme' => $screen->theme->value,
                'light_from' => $screen->light_from,
                'light_to' => $screen->light_to,
                'timezone' => $screen->timezone,
                'scope' => $scope($screen->scope),
                'widgets' => array_map(fn (array $widget): array => [
                    ...$portable($widget),
                    'rotate' => array_map(fn (mixed $entry): mixed => is_array($entry) ? $portable($entry) : $entry, (array) ($widget['rotate'] ?? [])),
                ], ScreenLayout::positioned($screen->widgets, $screen->cols, $screen->rows)),
            ],
        ];
    }
}
