<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Telemetry;

use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use App\Domains\Releases\Queries\ReleaseHealthQuery;
use App\Domains\Shared\Enums\Period;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[RequiresPermission(McpArea::Telemetry, McpLevel::Read)]
class ListReleases extends Tool
{
    use UsesMcpContext;

    protected string $name = 'list_releases';

    protected string $description = 'Releases seen in the period, newest first, optionally in one environment: requests, error rate (0 to 1), p95 in microseconds, occurrences, new issues and regressions, with the change from the release before. See get_release for its issues.';

    public function __construct(private readonly ReleaseHealthQuery $releases)
    {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'period' => $schema->string()->enum(['1h', '24h', '7d', '30d'])->description('Defaults to 7d.'),
            'environment' => $schema->string(),
            'limit' => $schema->integer()->min(1)->max(50)->description('Defaults to 20.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        $environment = (string) $request->get('environment', '');
        $rows = $this->releases->get(
            $project->id,
            Period::tryFrom((string) $request->get('period')) ?? Period::Week,
            $environment !== '' ? $environment : null,
            min(50, max(1, (int) $request->get('limit', 20))),
        );

        return Response::json(['releases' => array_map(self::present(...), $rows)]);
    }

    /**
     * A release row with its durations named in microseconds.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function present(array $row): array
    {
        return [
            'release' => $row['release'],
            'first_seen_at' => $row['first_seen_at'],
            'last_seen_at' => $row['last_seen_at'],
            'requests' => $row['requests'],
            'error_rate' => $row['error_rate'],
            'p95_us' => $row['p95'],
            'occurrences' => $row['occurrences'],
            'new_issues' => $row['new_issues'],
            'regressions' => $row['regressions'],
            'previous' => $row['previous'] !== null ? ['release' => $row['previous']['release'], 'error_rate' => $row['previous']['error_rate'], 'p95_us' => $row['previous']['p95']] : null,
            'change' => $row['change'],
        ];
    }
}
