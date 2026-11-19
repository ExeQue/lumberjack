<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Data;

use App\Domains\Ai\Data\AiBudgetUsage;
use App\Domains\Ai\Models\AiBudget;
use App\Domains\Alerts\Contracts\Alert;
use App\Domains\Alerts\Data\Concerns\LabelsMaintenance;
use Override;

/**
 * An AI budget passed one of its rule's thresholds in this period, or ($threshold null)
 * is on course to go over before the period ends.
 */
final readonly class AiBudgetAlert implements Alert
{
    use LabelsMaintenance;

    public function __construct(
        public AiBudget $budget,
        public AiBudgetUsage $usage,
        /** The share of the budget passed, in percent; null for a projected overrun. */
        public ?int $threshold,
        public string $url,
    ) {
    }

    public function url(): string
    {
        return $this->url;
    }

    public function headline(): string
    {
        return $this->marked((string) ($this->threshold === null
            ? __('alerts.messages.ai_budget_projected', ['project' => $this->budget->project->name])
            : __('alerts.messages.ai_budget', ['project' => $this->budget->project->name, 'threshold' => $this->threshold])));
    }

    public function subject(): string
    {
        return $this->budget->title();
    }

    public function summary(): array
    {
        $values = [
            'spent' => $this->budget->format($this->usage->spent),
            'amount' => $this->budget->format($this->usage->amount),
            'projected' => $this->budget->format($this->usage->projected()),
            'percent' => (int) floor($this->usage->percent()),
            'period' => $this->usage->ended()
                ? $this->usage->start->translatedFormat((string) __('alerts.ai_budget.dates.'.$this->budget->period->value))
                : __('alerts.ai_budget.periods.'.$this->budget->period->value),
        ];

        return [
            'title' => $this->headline(),
            'subject' => $this->subject(),
            'body' => [(string) __($this->threshold === null ? 'alerts.ai_budget.projected' : 'alerts.ai_budget.used', $values)],
        ];
    }

    public function facts(): array
    {
        return [
            (string) __('alerts.ai_budget.facts.used') => $this->budget->format($this->usage->spent).' ('.floor($this->usage->percent()).'%)',
            (string) __('alerts.ai_budget.facts.budget') => $this->budget->format($this->usage->amount).' '.__('alerts.ai_budget.per.'.$this->budget->period->value),
            // A period that ended has no pace left.
            ...($this->usage->ended() ? [] : [(string) __('alerts.ai_budget.facts.projected') => $this->budget->format($this->usage->projected())]),
        ];
    }

    public function urgency(): string
    {
        return $this->threshold !== null && $this->threshold >= 100 ? 'high' : 'normal';
    }

    #[Override]
    protected function maintenanceScope(): array
    {
        return [$this->budget->project_id, null];
    }
}
