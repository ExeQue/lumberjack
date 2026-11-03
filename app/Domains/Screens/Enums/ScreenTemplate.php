<?php

declare(strict_types=1);

namespace App\Domains\Screens\Enums;

/**
 * Ready layouts a new screen can start from, so a useful wall is one click away. Each
 * fills its grid exactly; widgets are stored like any other and can be changed after.
 */
enum ScreenTemplate: string
{
    case Blank = 'blank';
    case Overview = 'overview';
    case OnCall = 'on_call';
    case Infrastructure = 'infrastructure';
    case Background = 'background';

    /** @return array{0: int, 1: int} columns and rows */
    public function grid(): array
    {
        return match ($this) {
            self::Blank => [4, 4],
            self::Overview => [4, 5],
            self::OnCall, self::Infrastructure, self::Background => [4, 4],
        };
    }

    /**
     * @return list<array{id: string, type: string, source: string|null, rotate: list<string>, w: int, h: int, title: null, options: array<string, mixed>, scope: array<string, mixed>}>
     */
    public function widgets(): array
    {
        $rows = match ($this) {
            self::Blank => [],
            self::Overview => [
                ['stat', 'requests', 1, 1, ['rotate' => ['users_affected', 'apdex']]],
                ['stat', 'error_rate', 1, 1],
                ['stat', 'p95', 1, 1],
                ['stat', 'open_issues', 1, 1],
                ['chart', 'requests_errors', 2, 2],
                ['list', 'latest_issues', 2, 2],
                ['health', 'checks', 2, 2],
                ['list', 'queues', 1, 1],
                ['list', 'hosts', 1, 1],
                ['stat', 'current_release', 1, 1, ['options' => ['compare' => 'none', 'sparkline' => false]]],
                ['gauge', 'host_memory', 1, 1],
            ],
            self::OnCall => [
                ['stat', 'error_rate', 1, 1],
                ['stat', 'open_issues', 1, 1],
                ['gauge', 'p95', 1, 1],
                ['stat', 'users_affected', 1, 1],
                ['list', 'latest_issues', 2, 3, ['options' => ['sort' => 'newest']]],
                ['health', 'checks', 2, 2, ['options' => ['only_problems' => true]]],
                ['list', 'alert_feed', 2, 1],
            ],
            self::Infrastructure => [
                ['gauge', 'host_cpu', 1, 1],
                ['gauge', 'host_memory', 1, 1],
                ['gauge', 'host_disk', 1, 1],
                ['stat', 'agents_online', 1, 1],
                ['chart', 'host_cpu', 2, 2],
                ['list', 'hosts', 2, 1],
                ['list', 'agents', 2, 1],
                ['health', 'checks', 4, 1],
            ],
            self::Background => [
                ['stat', 'failed_jobs', 1, 1],
                ['stat', 'queue_size', 1, 1],
                ['gauge', 'queue_size', 1, 1],
                ['stat', 'current_release', 1, 1, ['options' => ['compare' => 'none', 'sparkline' => false]]],
                ['chart', 'queue_size', 2, 2],
                ['list', 'failed_jobs', 2, 2],
                ['list', 'scheduled_tasks', 2, 1],
                ['list', 'queues', 2, 1],
            ],
        };

        $widgets = [];

        foreach ($rows as $i => [$type, $source, $w, $h]) {
            $widgets[] = [
                'id' => $this->value.'-'.($i + 1),
                'type' => $type,
                'source' => $source,
                'rotate' => [],
                'w' => $w,
                'h' => $h,
                'title' => null,
                'options' => [],
                'scope' => [],
                ...($rows[$i][4] ?? []),
            ];
        }

        return $widgets;
    }

    public function label(): string
    {
        return __('ui/screens.templates.'.$this->value.'.label');
    }

    public function description(): string
    {
        return __('ui/screens.templates.'.$this->value.'.description');
    }

    /**
     * Every template but the empty one, with its full widgets, for an empty designer to fill.
     *
     * @return list<array<string, mixed>>
     */
    public static function forDesigner(): array
    {
        return array_values(array_map(
            fn (self $t): array => [...$t->toArray(), 'widgets' => $t->widgets()],
            array_filter(self::cases(), fn (self $t): bool => $t !== self::Blank),
        ));
    }

    /** @return array{id: string, label: string, description: string, cols: int, rows: int, widgets: list<array{type: string, w: int, h: int}>} */
    public function toArray(): array
    {
        [$cols, $rows] = $this->grid();

        return [
            'id' => $this->value,
            'label' => $this->label(),
            'description' => $this->description(),
            'cols' => $cols,
            'rows' => $rows,
            'widgets' => array_map(fn (array $w): array => ['type' => $w['type'], 'w' => $w['w'], 'h' => $w['h']], $this->widgets()),
        ];
    }
}
