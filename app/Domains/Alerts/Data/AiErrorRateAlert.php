<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Data;

use App\Domains\Ai\Models\AiKey;
use App\Domains\Alerts\Contracts\Alert;
use App\Domains\Alerts\Data\Concerns\LabelsMaintenance;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Override;

/**
 * Too many of an AI key's recent calls failed (a revoked key, a provider outage, a rate
 * limit), or they are back to working.
 */
final readonly class AiErrorRateAlert implements Alert
{
    use LabelsMaintenance;

    public function __construct(
        public Project $project,
        public string $fingerprint,
        public ?AiKey $key,
        public int $calls,
        public int $errors,
        public AiErrorRateSettings $settings,
        public CarbonImmutable $since,
        public bool $recovered,
        public string $url,
    ) {
    }

    public function url(): string
    {
        return $this->url;
    }

    public function headline(): string
    {
        return $this->marked((string) __($this->recovered ? 'alerts.messages.ai_error_rate_recovered' : 'alerts.messages.ai_error_rate', ['project' => $this->project->name]));
    }

    public function subject(): string
    {
        return (string) __('alerts.ai_budget.titles.key', ['key' => $this->key?->displayName() ?? substr($this->fingerprint, 0, 8)]);
    }

    public function rate(): int
    {
        return $this->calls > 0 ? (int) round($this->errors / $this->calls * 100) : 0;
    }

    public function summary(): array
    {
        return [
            'title' => $this->headline(),
            'subject' => $this->subject(),
            'body' => [(string) __($this->recovered ? 'alerts.ai_error_rate.recovered' : 'alerts.ai_error_rate.started', [
                'errors' => number_format($this->errors),
                'calls' => number_format($this->calls),
                'rate' => $this->rate(),
                'minutes' => $this->settings->minutes,
            ])],
        ];
    }

    public function facts(): array
    {
        return [
            (string) __('alerts.ai_error_rate.facts.failed') => number_format($this->errors).' / '.number_format($this->calls).' ('.$this->rate().'%)',
            (string) __('alerts.ai_error_rate.facts.provider') => $this->key->provider ?? '-',
            (string) __('alerts.ai_error_rate.facts.threshold') => $this->settings->describe(),
        ];
    }

    public function urgency(): string
    {
        return $this->recovered ? 'low' : 'high';
    }

    #[Override]
    protected function maintenanceScope(): array
    {
        return [$this->project->id, null];
    }
}
