<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\DataSources\Models\DataSource;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Screens\Data\ScreenImport;
use App\Domains\Screens\Enums\ScreenAspect;
use App\Domains\Screens\Enums\ScreenFit;
use App\Domains\Screens\Enums\ScreenOrientation;
use App\Domains\Screens\Enums\ScreenTheme;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Support\ScreenFile;
use App\Domains\Screens\Support\ScreenLayout;
use App\Domains\Screens\Support\WidgetSchema;
use App\Domains\Shared\Enums\Period;
use App\Domains\Teams\Models\Team;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ImportScreenAction
{
    /**
     * A new screen in the team from a screen file (ExportScreenAction), under a fresh
     * uuid and not shared. Projects and checks resolve by their keys within the team;
     * those it has nothing for are left out and reported (ScreenImport), so a widget
     * covers the rest or, with none left, all projects or checks.
     *
     * @param  array<mixed>  $document
     *
     * @throws ValidationException when the file is not a screen file this version reads, or its screen is not valid
     */
    public function execute(Team $team, array $document, ?string $name = null): ScreenImport
    {
        $import = $this->read($team, $document);
        $screen = $team->screens()->create([...$import->attributes, ...($name !== null ? ['name' => $name] : [])])->refresh();

        return $import->withScreen($screen);
    }

    /**
     * The file checked and resolved for the team, nothing saved yet: what an import would
     * create, for its preview. Validated through WidgetSchema like the designer's saves;
     * nothing of the file is trusted beyond that, ids included.
     *
     * @param  array<mixed>  $document
     *
     * @throws ValidationException
     */
    public function read(Team $team, array $document): ScreenImport
    {
        Validator::make($document, [
            'format' => ['bail', 'required', Rule::in([ScreenFile::FORMAT])],
            'version' => ['bail', 'required', 'integer', Rule::in([ScreenFile::VERSION])],
        ], [
            'format.*' => __('ui/screens.import.errors.format'),
            'version.*' => __('ui/screens.import.errors.version', ['version' => ScreenFile::VERSION]),
        ])->validate();

        $time = ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'];

        Validator::make($document, [
            'screen' => ['required', 'array'],
            'screen.name' => ['required', 'string', 'max:100'],
            'screen.cols' => ['required', 'integer', 'min:'.Screen::MIN_COLS, 'max:'.Screen::MAX_COLS],
            'screen.rows' => ['required', 'integer', 'min:'.Screen::MIN_ROWS, 'max:'.Screen::MAX_ROWS],
            'screen.aspect' => ['required', Rule::enum(ScreenAspect::class)],
            'screen.orientation' => ['required', Rule::enum(ScreenOrientation::class)],
            'screen.fit' => ['required', Rule::enum(ScreenFit::class)],
            'screen.show_header' => ['required', 'boolean'],
            // Files from before the clock could be hidden show it.
            'screen.show_clock' => ['sometimes', 'boolean'],
            'screen.clock_format' => ['sometimes', 'in:24,12'],
            'screen.theme' => ['required', Rule::enum(ScreenTheme::class)],
            'screen.light_from' => $time,
            'screen.light_to' => $time,
            'screen.timezone' => ['present', 'nullable', 'timezone:all'],
            'screen.scope' => ['required', 'array'],
            'screen.scope.projects' => ['present', 'array'],
            'screen.scope.projects.*' => ['string'],
            'screen.scope.environment' => ['nullable', 'string', 'max:64'],
            'screen.scope.period' => ['required', Rule::enum(Period::class)],
            'screen.widgets' => ['present', 'array', 'list', 'max:'.WidgetSchema::MAX_WIDGETS],
        ])->validate();

        /** @var array<string, mixed> $file */
        $file = $document['screen'];
        $cols = (int) $file['cols'];
        $rows = (int) $file['rows'];

        $projectIds = $team->projects()->pluck('id', 'slug')->all();
        $projectUuids = $team->projects()->pluck('uuid', 'slug')->all();
        $slugs = array_flip($projectIds);
        $checks = HealthCheck::query()->whereIn('project_id', array_values($projectIds))->whereNotArchived()->orderBy('id')->get();
        $droppedProjects = [];
        $droppedChecks = [];

        $resolveProjects = function (mixed $given) use ($projectUuids, &$droppedProjects): array {
            $uuids = [];

            foreach ((array) $given as $slug) {
                if (is_string($slug) && isset($projectUuids[$slug])) {
                    $uuids[] = (string) $projectUuids[$slug];
                } else {
                    $droppedProjects[] = is_scalar($slug) ? (string) $slug : '?';
                }
            }

            return $uuids;
        };

        // A check by its project, slug (or name) and environment; another environment's
        // check of that slug when the team has it in none of the same name.
        $resolveChecks = function (mixed $given) use ($checks, $slugs, &$droppedChecks): array {
            $uuids = [];

            foreach ((array) $given as $key) {
                $key = is_array($key) ? $key : [];
                $matches = $checks->filter(fn (HealthCheck $c): bool => (string) ($slugs[$c->project_id] ?? '') === (is_scalar($key['project'] ?? null) ? (string) $key['project'] : null)
                    && (match (true) {
                        isset($key['slug']) => $c->slug === $key['slug'],
                        isset($key['url_hash']) => $c->slug === null && is_string($c->setting('url')) && hash('sha256', $c->setting('url')) === $key['url_hash'],
                        default => isset($key['name']) && $c->slug === null && in_array($key['name'], [$c->displayName(), 'HTTP: '.rtrim((string) preg_replace('#^https?://#i', '', (string) $c->setting('url')), '/')], true),
                    }));
                // A name from an older file held the whole address, and is the surer match.
                $exact = $matches->filter(fn (HealthCheck $c): bool => isset($key['name']) && $key['name'] === 'HTTP: '.rtrim((string) preg_replace('#^https?://#i', '', (string) $c->setting('url')), '/'));
                $matches = $exact->isNotEmpty() ? $exact : $matches;
                $check = $matches->firstWhere('environment', $key['environment'] ?? null) ?? $matches->first();

                if ($check !== null) {
                    $uuids[] = $check->uuid;
                } else {
                    $droppedChecks[] = ScreenFile::checkLabel($key);
                }
            }

            return $uuids;
        };

        // A connected source by its project, key and environment, or that key in another environment.
        $connected = DataSource::query()->whereIn('project_id', array_values($projectIds))->whereConnected()->whereNotArchived()->orderBy('id')->get();
        $resolveSource = function (array $key) use ($connected, $slugs, &$droppedChecks): ?string {
            $matches = $connected->filter(fn (DataSource $s): bool => (string) ($slugs[$s->project_id] ?? '') === (is_scalar($key['project'] ?? null) ? (string) $key['project'] : null) && $s->key === ($key['key'] ?? null));
            $source = $matches->firstWhere('environment', $key['environment'] ?? '') ?? $matches->first();

            if ($source === null) {
                $droppedChecks[] = ScreenFile::checkLabel(['project' => $key['project'] ?? null, 'slug' => $key['key'] ?? null]);
            }

            return $source?->uuid;
        };

        $resolve = fn (array $item): array => [
            ...$item,
            ...(isset($item['scope']['projects']) && is_array($item['scope']) ? ['scope' => [...$item['scope'], 'projects' => $resolveProjects($item['scope']['projects'])]] : []),
            ...(isset($item['options']['checks']) && is_array($item['options']) ? ['options' => [...$item['options'], 'checks' => $resolveChecks($item['options']['checks'])]] : []),
        ];
        $resolve = function (array $item) use ($resolve, $resolveSource): array {
            $item = $resolve($item);

            return is_array($item['options']['data_source'] ?? null)
                ? [...$item, 'options' => [...$item['options'], 'data_source' => $resolveSource($item['options']['data_source'])]]
                : $item;
        };

        $widgets = [];
        $seen = [];

        foreach ($file['widgets'] as $widget) {
            if (is_array($widget)) {
                $widget = $resolve($widget);
                $widget['rotate'] = array_map(fn (mixed $entry): mixed => is_array($entry) ? $resolve($entry) : $entry, (array) ($widget['rotate'] ?? []));

                // Ids only tell the widgets of a screen apart: one taken already gets a new one.
                if (is_string($widget['id'] ?? null) && isset($seen[$widget['id']])) {
                    $widget['id'] = $this->freeId($widget['id'], $seen);
                }

                $seen[(string) ($widget['id'] ?? '')] = true;
            }

            $widgets[] = $widget;
        }

        $scopeProjects = $resolveProjects($file['scope']['projects']);
        $rules = [];

        foreach ($widgets as $i => $widget) {
            $rules = [...$rules, ...WidgetSchema::rules("screen.widgets.{$i}", $widget, $cols, $rows, array_values($projectUuids))];
        }

        Validator::make(['screen' => ['widgets' => $widgets]], $rules)->validate();

        $widgets = array_map(
            fn (array $widget): array => WidgetSchema::clean($widget, $cols, $rows),
            ScreenLayout::positioned($widgets, $cols, $rows),
        );

        $overlapping = ScreenLayout::overlapping($widgets);

        if ($overlapping !== []) {
            throw ValidationException::withMessages(["screen.widgets.{$overlapping[0]}.x" => __('ui/screens.validation.overlap')]);
        }

        return new ScreenImport(
            attributes: [
                'name' => (string) $file['name'],
                'cols' => $cols,
                'rows' => $rows,
                'aspect' => ScreenAspect::from((string) $file['aspect']),
                'orientation' => ScreenOrientation::from((string) $file['orientation']),
                'fit' => ScreenFit::from((string) $file['fit']),
                'show_header' => (bool) $file['show_header'],
                'show_clock' => (bool) ($file['show_clock'] ?? true),
                'clock_format' => in_array($file['clock_format'] ?? null, ['24', '12'], true) ? $file['clock_format'] : '24',
                'theme' => ScreenTheme::from((string) $file['theme']),
                'light_from' => (string) $file['light_from'],
                'light_to' => (string) $file['light_to'],
                'timezone' => is_string($file['timezone']) ? $file['timezone'] : null,
                'scope' => [
                    'projects' => $scopeProjects,
                    'environment' => is_string($file['scope']['environment'] ?? null) && $file['scope']['environment'] !== '' ? $file['scope']['environment'] : null,
                    'period' => (string) $file['scope']['period'],
                ],
                'widgets' => $widgets,
            ],
            droppedProjects: array_values(array_unique($droppedProjects)),
            droppedChecks: array_values(array_unique($droppedChecks)),
        );
    }

    /** @param  array<string, true>  $seen */
    private function freeId(string $id, array $seen): string
    {
        $n = 2;

        while (isset($seen[$candidate = mb_substr($id, 0, 36).'-'.$n])) {
            $n++;
        }

        return $candidate;
    }
}
