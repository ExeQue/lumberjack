<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Presenters;

use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\CustomAlert;
use App\Domains\Teams\Models\Team;

class CustomAlertPresenter
{
    private const int SENT_SHOWN = 25;

    public function __construct(
        private readonly AlertRulePresenter $rules,
    ) {
    }

    /**
     * The team's topics, default first, as rules with what came in on them.
     *
     * @return list<array<string, mixed>>
     */
    public function topics(Team $team): array
    {
        $rules = $team->alertRules()->whereType(AlertRuleType::CustomAlert)->with('channels:id')->orderBy('topic')->get();
        $stats = CustomAlert::query()->whereIn('project_id', $team->projects()->select('id'))
            ->selectRaw('topic, COUNT(*) FILTER (WHERE occurred_at >= ?) AS day, MAX(occurred_at) AS latest', [now()->subDay()])
            ->groupBy('topic')->get()->keyBy('topic');

        return array_values($rules->sortBy(fn (AlertRule $rule): string => ($rule->topic === AlertRule::DEFAULT_TOPIC ? '0' : '1').$rule->topic)
            ->map(fn (AlertRule $rule): array => [
                ...$this->rules->present($rule),
                'label' => $rule->topic,
                'topic' => $rule->topic,
                'deletable' => $rule->topic !== AlertRule::DEFAULT_TOPIC,
                'last_day' => (int) ($stats->get($rule->topic)->day ?? 0),
                'last_at' => $stats->get($rule->topic)?->getAttribute('latest'),
            ])->all());
    }

    /**
     * The latest alerts from the team's projects.
     *
     * @return list<array<string, mixed>>
     */
    public function sent(Team $team): array
    {
        return array_values(CustomAlert::query()->whereIn('project_id', $team->projects()->select('id'))->with('project:id,name')
            ->latest('occurred_at')->limit(self::SENT_SHOWN)->get()
            ->map(fn (CustomAlert $alert): array => [
                'id' => $alert->id,
                'title' => $alert->title,
                'message' => $alert->message,
                'topic' => $alert->topic,
                'level' => $alert->level->value,
                'project' => $alert->project->name,
                'environment' => $alert->environment,
                'host' => $alert->host,
                'occurred_at' => $alert->occurred_at->toIso8601String(),
                'suppressed' => $alert->suppressed,
                'deliveries' => $alert->deliveries,
            ])->all());
    }
}
