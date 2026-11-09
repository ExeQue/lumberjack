<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Data;

use App\Domains\Alerts\Contracts\Alert;
use App\Domains\Projects\Models\Project;
use Override;

/**
 * A release an environment reported for the first time.
 */
final readonly class ReleaseAlert implements Alert
{
    public function __construct(
        public Project $project,
        public string $release,
        public ?string $environment,
        public ?string $host,
        public string $url,
    ) {
    }

    #[Override]
    public function url(): string
    {
        return $this->url;
    }

    #[Override]
    public function headline(): string
    {
        return (string) __('alerts.messages.new_release', ['project' => $this->project->name]);
    }

    #[Override]
    public function summary(): array
    {
        return [
            'title' => $this->headline(),
            'subject' => $this->release,
            'body' => [(string) __('alerts.new_release.first_seen')],
        ];
    }

    #[Override]
    public function facts(): array
    {
        return array_filter([
            (string) __('alerts.new_release.facts.environment') => $this->environment,
            (string) __('alerts.new_release.facts.host') => $this->host,
        ], fn (?string $value): bool => $value !== null);
    }

    #[Override]
    public function urgency(): string
    {
        return 'low';
    }
}
