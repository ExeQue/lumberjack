<?php

declare(strict_types=1);

namespace App\Domains\Retention\Http\Requests;

use App\Domains\Projects\Models\Project;
use App\Domains\Retention\Actions\ResolveRetentionDaysAction;
use App\Domains\Retention\Enums\RetentionCategory;
use App\Domains\Retention\Models\RetentionPolicy;
use App\Domains\Teams\Models\Team;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Days per data type at one level, never above what the levels above allow. `days.{type}`
 * empty inherits; a type listed in `forever` keeps its data forever. Authorization is checked
 * by the controller (or the admin middleware) against the level in the route.
 */
class UpdateRetentionRequest extends FormRequest
{
    public const int MAX_DAYS = 3650;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $categories = $this->categories();
        $rules = [];

        foreach ($categories as $category) {
            $rules['days.'.$category->value] = ['nullable', 'integer', 'min:1', 'max:'.self::MAX_DAYS];
        }

        return [
            'days' => ['array'],
            ...$rules,
            'forever' => ['sometimes', 'array'],
            'forever.*' => ['string', Rule::in(array_map(fn (RetentionCategory $c): string => $c->value, $categories))],
            'job_overrides' => ['sometimes', 'array', 'max:100'],
            'job_overrides.*.job_class' => ['required', 'string', 'max:255'],
            'job_overrides.*.days' => ['required', 'integer', 'min:1', 'max:'.self::MAX_DAYS],
            'channels' => ['sometimes', 'array'],
            'channels.*' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_DAYS],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $scope = $this->scope();
            $resolve = app(ResolveRetentionDaysAction::class);
            $policies = $resolve->policiesFor($scope);

            foreach ($this->categories() as $category) {
                $forever = $this->isForever($category);
                $days = $this->input('days.'.$category->value);

                if ($forever && $scope !== null && ! $resolve->execute($category, $scope, policies: $policies)->foreverAllowed) {
                    $validator->errors()->add('days.'.$category->value, __('retention.forever_not_allowed'));

                    continue;
                }

                $this->checkCap($validator, 'days.'.$category->value, $category, $scope, $policies, $forever ? null : $days, $forever || $days !== null, $resolve);
            }

            foreach ((array) $this->input('job_overrides', []) as $i => $override) {
                $this->checkCap($validator, "job_overrides.{$i}.days", RetentionCategory::JobRuns, $scope, $policies, $override['days'], true, $resolve);
            }

            foreach ((array) $this->input('channels', []) as $channelId => $days) {
                $this->checkCap($validator, "channels.{$channelId}", RetentionCategory::Logs, $scope, $policies, $days, $days !== null, $resolve);
            }
        }];
    }

    /**
     * The level being saved: a project, the user's current team, or the application.
     */
    public function scope(): Team|Project|null
    {
        $project = $this->route('project');

        return match (true) {
            $project instanceof Project => $project,
            $this->routeIs('admin.retention.update') => null,
            default => $this->user()->currentTeam ?? abort(403),
        };
    }

    /** @return list<RetentionCategory> */
    public function categories(): array
    {
        return $this->scope() === null ? RetentionCategory::cases() : RetentionCategory::forTeams();
    }

    public function isForever(RetentionCategory $category): bool
    {
        return in_array($category->value, (array) $this->input('forever', []), true);
    }

    /**
     * @param  Collection<int, RetentionPolicy>  $policies
     */
    private function checkCap(Validator $validator, string $key, RetentionCategory $category, Team|Project|null $scope, Collection $policies, mixed $days, bool $set, ResolveRetentionDaysAction $resolve): void
    {
        if (! $set || $scope === null) {
            return;
        }

        $cap = $resolve->execute($category, $scope, policies: $policies);

        if ($cap->capDays === null || ($days !== null && (int) $days <= $cap->capDays)) {
            return;
        }

        $validator->errors()->add($key, __('retention.above_cap', [
            'days' => $cap->capDays,
            'level' => __('retention.levels.'.$cap->capLevel?->value),
        ]));
    }
}
