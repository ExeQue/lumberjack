<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Telemetry;

use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use App\Domains\Metrics\Enums\Metric;
use App\Domains\Metrics\Enums\MetricGroup;
use App\Domains\Metrics\Http\Requests\ReadSeriesRequest;
use App\Domains\Metrics\Queries\MetricSeriesQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[RequiresPermission(McpArea::Telemetry, McpLevel::Read)]
class GetMetricSeries extends Tool
{
    use UsesMcpContext;

    protected string $name = 'get_metric_series';

    protected string $description = 'A time series of one metric, as the metrics API serves it: points of {t, series, value}, optionally filtered (route, queue, job, host, release, check, domain, activity; * is a wildcard) and split by group_by. Durations are in microseconds, rates and shares from 0 to 1. Defaults to the last 24 hours.';

    public function __construct(private readonly MetricSeriesQuery $series)
    {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'metric' => $schema->string()->enum(array_column(Metric::cases(), 'value'))->required(),
            'from' => $schema->string()->description('Epoch milliseconds or ISO 8601. Defaults to a day before "to".'),
            'to' => $schema->string()->description('Epoch milliseconds or ISO 8601. Defaults to now.'),
            'interval' => $schema->string()->description('Bucket size: 30s, 5m, 1h, 1d or auto (about 300 points).'),
            'environment' => $schema->string(),
            'route' => $schema->string()->description('URI or route name.'),
            'domain' => $schema->string(),
            'queue' => $schema->string(),
            'job' => $schema->string(),
            'host' => $schema->string(),
            'release' => $schema->string(),
            'check' => $schema->string()->description('A health check slug, for the db_* metrics.'),
            'activity' => $schema->string()->description('An activity as group:name, for the activity metric.'),
            'group_by' => $schema->string()->enum(array_column(MetricGroup::cases(), 'value'))->description('One series per value; not every metric can be split by every group.'),
            'top' => $schema->integer()->min(1)->max(50)->description('How many series a split returns, the busiest first. Defaults to 10.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        // The metrics API's own request, so the names, limits and checks stay the same.
        $form = ReadSeriesRequest::create('/', 'GET', array_filter($request->all(), fn (mixed $value, string $key): bool => $key !== 'project' && $value !== null, ARRAY_FILTER_USE_BOTH));
        Validator::make($form->all(), $form->rules())->after($form->after())->validate();

        $metric = $form->metric();
        $window = $form->window();
        $micro = $metric->unit() === 'ms';

        return Response::json([
            'metric' => $metric->value,
            'unit' => $micro ? 'us' : $metric->unit(),
            'interval' => $window->interval(),
            'from' => $window->from->toIso8601ZuluString(),
            'to' => $window->to->toIso8601ZuluString(),
            'points' => array_map(
                fn (array $point): array => $micro && $point['value'] !== null ? [...$point, 'value' => (int) round($point['value'] * 1000)] : $point,
                $this->series->series($metric, $form->filter($project), $window, $form->top()),
            ),
        ]);
    }
}
