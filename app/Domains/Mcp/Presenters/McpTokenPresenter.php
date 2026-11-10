<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Presenters;

use App\Domains\Mcp\Data\McpPermissions;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Models\McpToken;
use App\Domains\Mcp\Models\McpTokenProject;
use App\Domains\Mcp\Support\McpToolCatalog;
use App\Domains\Projects\Models\Project;

/**
 * Tokens and the permission catalog for the token picker on the team page.
 */
class McpTokenPresenter
{
    public function __construct(private readonly McpToolCatalog $catalog)
    {
    }

    /**
     * Every area with its levels and tools, in display order.
     *
     * @return list<array<string, mixed>>
     */
    public function catalog(): array
    {
        return array_map(fn (McpArea $area): array => [
            'key' => $area->value,
            'label' => $area->label(),
            'description' => $area->description(),
            'team_wide' => $area->isTeamWide(),
            'levels' => array_map(fn (McpLevel $level): string => $level->value, $area->levels()),
            'tools' => array_values(array_map(fn (array $tool): array => [
                'name' => $tool['name'],
                'label' => __('mcp.tools.'.$tool['name']),
                'level' => $tool['level']?->value,
                'deletes' => $tool['destructive'],
                'sends_out' => $tool['open_world'],
                'public' => $tool['public'],
            ], $this->catalog->inArea($area))),
        ], McpArea::cases());
    }

    /**
     * @return array<string, mixed>
     */
    public function token(McpToken $token): array
    {
        $projects = $token->projects->filter(fn (Project $project): bool => $project->team_id === $token->team_id);

        return [
            'id' => $token->id,
            'name' => $token->name,
            'all_projects' => $token->all_projects,
            'projects' => $token->all_projects ? [] : $projects->map(fn (Project $p): array => ['uuid' => $p->uuid, 'name' => $p->name])->values()->all(),
            'permissions' => $token->permissions->toArray() ?: (object) [],
            'changes' => (object) $projects->mapWithKeys(function (Project $project): array {
                /** @var McpTokenProject $pivot */
                $pivot = $project->getRelation('pivot');

                return $pivot->permissions->isEmpty() ? [] : [$project->uuid => $pivot->permissions->toArray()];
            })->all(),
            'tool_count' => $this->toolCount($token->permissions),
            'created_by' => $token->user?->name,
            'usable' => $token->isUsable(),
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'expires_at' => $token->expires_at?->toIso8601String(),
        ];
    }

    /**
     * How many tools Default gives; a quick measure for the token list.
     */
    private function toolCount(McpPermissions $permissions): int
    {
        return count(array_filter($this->catalog->all(), function (array $tool) use ($permissions): bool {
            if ($tool['area'] === null || $tool['level'] === null) {
                return true;
            }

            return $permissions->exception($tool['area'], $tool['name'])
                ?? ($permissions->level($tool['area']) ?? McpLevel::None)->includes($tool['level']);
        }));
    }
}
