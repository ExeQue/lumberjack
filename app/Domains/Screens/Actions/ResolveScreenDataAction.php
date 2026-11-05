<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\Screens\Data\WidgetScope;
use App\Domains\Screens\Enums\WidgetType;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Support\ScreenQr;
use App\Domains\Screens\Support\WidgetSchema;
use App\Domains\Screens\Support\WidgetSources;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The data of a screen's widgets in one go, or of one widget when only its settings
 * changed: per widget, per source it shows (the picked one and those it rotates
 * through, each with its own settings over the widget's, see WidgetSchema::rotation()),
 * what ResolveWidgetDataAction answers; a QR code widget's picture under qr. Identical questions are answered from a short
 * cache, so a wall of kiosks asks Postgres once, not once per kiosk.
 */
class ResolveScreenDataAction
{
    public const int CACHE_SECONDS = 20;

    public function __construct(
        private readonly ResolveWidgetDataAction $resolveWidgetData,
    ) {
    }

    /**
     * @return array<string, array<string, array<string, mixed>|null>>  by widget id, then source id (source#index for a repeated one); null where it failed
     */
    public function execute(Screen $screen, ?string $widgetId = null, bool $aggregatesOnly = false): array
    {
        $data = [];

        foreach ($screen->widgets as $widget) {
            $type = WidgetType::tryFrom((string) ($widget['type'] ?? ''));

            if ($widgetId !== null && $widget['id'] !== $widgetId) {
                continue;
            }

            if (($widget['type'] ?? null) === 'qr') {
                $data[$widget['id']]['qr'] = $this->qr((array) ($widget['options'] ?? []));

                continue;
            }

            if ($type === null) {
                continue;
            }

            // Each value of a rotation with its own options and period over the widget's.
            $items = WidgetSchema::rotation($widget);

            foreach ($items as $i => $item) {
                $source = $item['source'];
                // A repeated source (health values differ by their checks) answers as source#index.
                $at = array_search($source, array_column($items, 'source'), true) === $i ? $source : "{$source}#{$i}";
                $own = (array) ($widget['scope'] ?? []);

                // A filter names a route, queue, job or host: a value that filters something
                // else does not take the widget's.
                if ($this->filterOn($type, $source) !== $this->filterOn($type, (string) $widget['source'])) {
                    unset($own['filter'], $own['domain']);
                }

                $scope = $this->scope($screen, [...$widget, 'scope' => [...$own, ...$item['scope']]]);
                $options = $this->options($type, [...(array) ($widget['options'] ?? []), ...$item['options']]);
                $key = 'screen-data:'.md5(serialize([$screen->team_id, $type->value, $source, $scope, $options, $aggregatesOnly]));

                $data[$widget['id']][$at] = Cache::remember($key, self::CACHE_SECONDS, function () use ($screen, $type, $source, $scope, $options, $aggregatesOnly): ?array {
                    try {
                        return $this->resolveWidgetData->execute($screen->team, $type, $source, $scope, $options, $aggregatesOnly);
                    } catch (Throwable $e) {
                        // One broken widget must not blank the screen; the rest still shows.
                        report($e);

                        return null;
                    }
                });
            }
        }

        return $data;
    }

    /**
     * The widget's own scope over the screen's default.
     *
     * @param  array<string, mixed>  $widget
     */
    public function scope(Screen $screen, array $widget): WidgetScope
    {
        $default = $screen->scope;
        $own = (array) ($widget['scope'] ?? []);
        $environment = $own['environment'] ?? $default['environment'] ?? null;

        return WidgetScope::fromArray([
            'projects' => $own['projects'] ?? $default['projects'] ?? [],
            'environment' => $environment === WidgetSchema::ALL_ENVIRONMENTS ? null : $environment,
            'period' => $own['period'] ?? $default['period'] ?? null,
            'release' => $own['release'] ?? null,
            'filter' => $own['filter'] ?? null,
            'domain' => $own['domain'] ?? null,
        ]);
    }

    private function filterOn(WidgetType $type, string $source): ?string
    {
        return WidgetSources::has($type, $source) ? WidgetSources::find($type, $source)->filterOn : null;
    }

    /**
     * The options the data depends on, defaults filled in; a list fitting its tile asks
     * for as many rows as any tile shows.
     *
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    private function options(WidgetType $type, array $stored): array
    {
        $fields = WidgetSchema::fields($type->value);
        $options = array_intersect_key(WidgetSchema::options($type->value, $stored), array_filter($fields, fn (array $f): bool => $f['data']));

        if (($options['limit'] ?? null) === 'auto') {
            $options['limit'] = WidgetSchema::LIST_FIT_LIMIT;
        }

        ksort($options);

        return $options;
    }

    /**
     * {svg}: null while there is nothing to encode; the whole answer null when it cannot
     * be encoded (a link that is not one). The same options draw the same picture, so it
     * is kept for a day, and drawn again when the QR styles in config/qr.php change.
     *
     * @param  array<string, mixed>  $options
     * @return array{svg: string|null}|null
     */
    private function qr(array $options): ?array
    {
        return Cache::remember('screen-qr:v4:'.md5(serialize([$options, config('qr.styles'), config('qr.colors')])), 86400, function () use ($options): ?array {
            try {
                return ['svg' => ScreenQr::widgetSvg($options)];
            } catch (Throwable) {
                return null;
            }
        });
    }
}
