<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Http\Requests\Concerns;

use App\Domains\Mcp\Data\McpAreaGrant;
use App\Domains\Mcp\Data\McpPermissions;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Support\McpToolCatalog;
use App\Domains\Teams\Models\Team;
use Closure;
use Illuminate\Validation\Validator;

/**
 * Which projects a token covers and what it may do: a level per area as its Default,
 * single tools added or taken away, and per-project changes to project areas.
 *
 * Shape: permissions: {area: {level, grant: [tool], deny: [tool]}},
 * changes: {project uuid: {area: {level?, grant, deny}}}.
 */
trait ValidatesMcpAccess
{
    /** @return array<string, mixed> */
    protected function accessRules(): array
    {
        return [
            'all_projects' => ['boolean'],
            'projects' => ['exclude_if:all_projects,true', 'required', 'array', 'min:1'],
            'projects.*' => ['uuid'],
            'permissions' => ['required', 'array'],
            'permissions.*' => ['array'],
            'permissions.*.level' => ['sometimes', 'string'],
            'permissions.*.grant' => ['sometimes', 'array'],
            'permissions.*.grant.*' => ['string'],
            'permissions.*.deny' => ['sometimes', 'array'],
            'permissions.*.deny.*' => ['string'],
            'changes' => ['sometimes', 'array'],
            'changes.*' => ['array'],
            'changes.*.*' => ['array'],
            'changes.*.*.level' => ['sometimes', 'string'],
            'changes.*.*.grant' => ['sometimes', 'array'],
            'changes.*.*.grant.*' => ['string'],
            'changes.*.*.deny' => ['sometimes', 'array'],
            'changes.*.*.deny.*' => ['string'],
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            // The shape rules failed: the checks below would read arrays as strings.
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach ((array) $this->input('permissions', []) as $key => $grant) {
                $this->checkGrant($validator, 'permissions.'.$key, (string) $key, $grant, true);
            }

            $covered = $this->boolean('all_projects') ? null : (array) $this->input('projects', []);

            foreach ((array) $this->input('changes', []) as $project => $areas) {
                if ($covered !== null && ! in_array($project, $covered, true)) {
                    $validator->errors()->add('changes.'.$project, __('mcp.validation.change_outside_projects'));

                    continue;
                }

                foreach ((array) $areas as $key => $grant) {
                    $this->checkGrant($validator, "changes.{$project}.{$key}", (string) $key, $grant, false);
                }
            }
        }];
    }

    public function permissions(): McpPermissions
    {
        $permissions = McpPermissions::fromArray((array) $this->input('permissions', []));

        foreach (McpArea::cases() as $area) {
            $permissions = $permissions->area($area) === null ? $permissions->with($area, new McpAreaGrant(McpLevel::None)) : $permissions;
        }

        return $permissions;
    }

    /**
     * Each covered project's changes to Default, keyed by project id; team-wide areas dropped.
     *
     * @return array<int, McpPermissions>
     */
    public function changes(Team $team): array
    {
        $ids = $team->projects()->pluck('id', 'uuid');
        $changes = [];

        foreach ((array) $this->input('changes', []) as $uuid => $areas) {
            $permissions = McpPermissions::fromArray(array_filter((array) $areas, fn (mixed $grant, string|int $key): bool => McpArea::tryFrom((string) $key)?->isTeamWide() === false, ARRAY_FILTER_USE_BOTH));

            if ($ids->has($uuid) && ! $permissions->isEmpty()) {
                $changes[(int) $ids[$uuid]] = $permissions;
            }
        }

        return $changes;
    }

    /**
     * @return list<int>
     */
    public function projectIds(Team $team): array
    {
        return $this->boolean('all_projects')
            ? []
            : array_values($team->projects()->whereIn('uuid', (array) $this->input('projects'))->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
    }

    private function checkGrant(Validator $validator, string $field, string $key, mixed $grant, bool $levelRequired): void
    {
        $area = McpArea::tryFrom($key);

        if ($area === null || ! is_array($grant) || (! $levelRequired && $area->isTeamWide())) {
            $validator->errors()->add($field, __('mcp.validation.unknown_area'));

            return;
        }

        $level = McpLevel::tryFrom((string) ($grant['level'] ?? ''));

        if (($levelRequired || isset($grant['level'])) && ($level === null || ! in_array($level, $area->levels(), true))) {
            $validator->errors()->add($field.'.level', __('mcp.validation.level'));
        }

        $tools = array_keys(app(McpToolCatalog::class)->inArea($area));

        foreach (['grant', 'deny'] as $list) {
            $names = $grant[$list] ?? [];

            if (! is_array($names) || array_diff($names, $tools) !== []) {
                $validator->errors()->add($field.'.'.$list, __('mcp.validation.tools'));
            }
        }
    }
}
