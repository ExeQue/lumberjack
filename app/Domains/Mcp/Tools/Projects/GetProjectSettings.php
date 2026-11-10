<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Projects;

use App\Domains\Logs\Models\LogChannel;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use App\Domains\Projects\Queries\ProjectEnvironmentsQuery;
use App\Domains\Retention\Actions\ResolveRetentionDaysAction;
use App\Domains\Retention\Models\RetentionPolicy;
use App\Domains\Retention\Presenters\RetentionPresenter;
use App\Domains\Shared\Support\Scrubber;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * What the project settings page shows, without a single secret: no server token, no
 * keys, no stats link, no repository token.
 */
#[IsReadOnly]
#[RequiresPermission(McpArea::Project, McpLevel::Read)]
class GetProjectSettings extends Tool
{
    use UsesMcpContext;

    protected string $name = 'get_project_settings';

    protected string $description = 'The project\'s settings: name, slug, issue prefix, environments, allowed browser origins, scrubbed keys, log channels, retention per data type, the linked repository\'s name and whether public stats are on. Never returns tokens or keys.';

    public function __construct(
        private readonly ProjectEnvironmentsQuery $environments,
        private readonly ResolveRetentionDaysAction $retention,
        private readonly RetentionPresenter $retentionRows,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        if (($denied = $this->authorize('update', $project)) !== null) {
            return $denied;
        }

        $policies = $this->retention->policiesFor($project);
        $repository = $project->repository;

        return Response::json([
            'id' => $project->uuid,
            'name' => $project->name,
            'slug' => $project->slug,
            'issue_prefix' => $project->issuePrefix(),
            'environments' => $this->environments->get($project),
            'allowed_origins' => $project->allowed_origins ?? [],
            'scrub_keys' => $project->scrub_keys ?? [],
            'default_scrub_keys' => Scrubber::SENSITIVE,
            'log_channels' => $project->logChannels()->orderByRaw('name = ? DESC', [LogChannel::DEFAULT])->orderBy('name')->get()->map(fn (LogChannel $channel): array => [
                'id' => $channel->id,
                'name' => $channel->name,
                'is_default' => $channel->isDefault(),
                'retention_days' => $policies->first(fn (RetentionPolicy $p): bool => $p->log_channel_id === $channel->id)?->days,
            ])->all(),
            'retention' => array_map(fn (array $row): array => [
                'category' => $row['category'],
                'label' => $row['label'],
                'own' => $row['stored'],
                'effective' => $row['effective'],
            ], $this->retentionRows->rows($project, $policies)),
            'job_retention' => $policies->filter(fn (RetentionPolicy $p): bool => $p->project_id === $project->id && $p->job_class !== null)
                ->map(fn (RetentionPolicy $p): array => ['job_class' => $p->job_class, 'days' => $p->days])->values()->all(),
            'repository' => $repository === null ? null : ['provider' => $repository->provider->value, 'repository' => $repository->repository],
            'public_stats' => ['enabled' => $project->stats_key !== null, 'include_titles' => $project->stats_include_titles],
        ]);
    }
}
