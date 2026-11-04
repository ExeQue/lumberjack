<?php

declare(strict_types=1);

namespace App\Domains\Screens\Data;

use App\Domains\Screens\Models\Screen;

/**
 * A screen file read for a team: the attributes the new screen gets, the references the
 * team has nothing for (left out, so a widget covers all projects or all checks instead),
 * and the screen once it is created.
 */
final readonly class ScreenImport
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $droppedProjects  project slugs
     * @param  list<string>  $droppedChecks  checks as "project: slug"
     */
    public function __construct(
        public array $attributes,
        public array $droppedProjects = [],
        public array $droppedChecks = [],
        public ?Screen $screen = null,
    ) {
    }

    public function hasDropped(): bool
    {
        return $this->droppedProjects !== [] || $this->droppedChecks !== [];
    }

    /** What the import left out, as a sentence for a notice; empty when nothing. */
    public function droppedNotice(): string
    {
        if (! $this->hasDropped()) {
            return '';
        }

        return __('ui/screens.import.dropped', [
            'items' => implode(', ', [
                ...array_map(fn (string $slug): string => __('ui/screens.import.dropped_project', ['slug' => $slug]), $this->droppedProjects),
                ...array_map(fn (string $check): string => __('ui/screens.import.dropped_check', ['check' => $check]), $this->droppedChecks),
            ]),
        ]);
    }

    /**
     * For the import preview.
     *
     * @return array{name: string, cols: int, rows: int, widgets: list<array{type: string, x: int, y: int, w: int, h: int}>, dropped: array{projects: list<string>, checks: list<string>}, notice: string}
     */
    public function toPreview(): array
    {
        return [
            'name' => (string) $this->attributes['name'],
            'cols' => (int) $this->attributes['cols'],
            'rows' => (int) $this->attributes['rows'],
            'widgets' => array_values(array_map(
                fn (array $w): array => ['type' => (string) $w['type'], 'x' => (int) $w['x'], 'y' => (int) $w['y'], 'w' => (int) $w['w'], 'h' => (int) $w['h']],
                (array) $this->attributes['widgets'],
            )),
            'dropped' => ['projects' => $this->droppedProjects, 'checks' => $this->droppedChecks],
            'notice' => $this->droppedNotice(),
        ];
    }

    public function withScreen(Screen $screen): self
    {
        return new self($this->attributes, $this->droppedProjects, $this->droppedChecks, $screen);
    }
}
