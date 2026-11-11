<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Presenters;

use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AlertRulePresenter
{
    /**
     * @return array<string, mixed>
     */
    public function present(AlertRule $rule): array
    {
        return [
            'id' => $rule->id,
            'type' => $rule->type->value,
            'label' => $rule->type->label(),
            'description' => $rule->type->description(),
            'enabled' => $rule->enabled,
            'channels' => $rule->channels->modelKeys(),
            'environments' => $rule->environments ?? [],
            'settings' => match ($rule->type) {
                AlertRuleType::QueueBacklog => $rule->queueBacklogSettings()->toArray(),
                AlertRuleType::WeeklySummary => $rule->weeklySummarySettings()->toArray(),
                default => null,
            },
        ];
    }

    /**
     * The team's rules, in the order of the types.
     *
     * @return list<array<string, mixed>>
     */
    public function forTeam(Team $team): array
    {
        $rules = $this->byType($team->alertRules()->with('channels:id')->get());

        return array_values(collect(AlertRuleType::standard())->map(fn (AlertRuleType $t): ?AlertRule => $rules->get($t->value))->filter()
            ->map(fn (AlertRule $r): array => $this->present($r))->all());
    }

    /**
     * Per type, the team's rule and the project's own if it customized it; the own one counts.
     *
     * @return list<array<string, mixed>>
     */
    public function forProject(Project $project): array
    {
        $team = $this->byType($project->team->alertRules()->with('channels:id')->get());
        $own = $this->byType($project->alertRules()->with('channels:id')->get());
        $addOns = DB::table('project_alert_rule_channel')->where('project_id', $project->id)->get()->groupBy('alert_rule_id');

        $rows = array_map(fn (AlertRuleType $t): array => [
            'type' => $t->value,
            'label' => $t->label(),
            'description' => $t->description(),
            'team' => ($rule = $team->get($t->value)) !== null ? $this->present($rule) : null,
            'add_ons' => $addOns->get($team->get($t->value)->id ?? 0, collect())->pluck('notification_channel_id')->map(fn ($id): int => (int) $id)->values()->all(),
            'project' => ($rule = $own->get($t->value)) !== null ? $this->present($rule) : null,
        ], AlertRuleType::standard());

        $topics = $project->team->alertRules()->whereType(AlertRuleType::CustomAlert)->with('channels:id')->orderBy('topic')->get();
        $ownTopics = $project->alertRules()->whereType(AlertRuleType::CustomAlert)->with('channels:id')->get()->keyBy('topic');

        return [...$rows, ...$topics->map(fn (AlertRule $rule): array => [
            'type' => $rule->type->value,
            'topic' => $rule->topic,
            'label' => $rule->topic,
            'description' => (string) __('alerts.custom.topic_description'),
            'team' => $this->present($rule),
            'add_ons' => $addOns->get($rule->id, collect())->pluck('notification_channel_id')->map(fn ($id): int => (int) $id)->values()->all(),
            'project' => $ownTopics->has($rule->topic) ? $this->present($ownTopics->get($rule->topic)) : null,
        ])->all()];
    }

    /**
     * @param  Collection<int, AlertRule>  $rules
     * @return Collection<string, AlertRule>
     */
    private function byType(Collection $rules): Collection
    {
        return $rules->keyBy(fn (AlertRule $r): string => $r->type->value);
    }
}
