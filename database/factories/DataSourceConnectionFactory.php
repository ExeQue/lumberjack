<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Connectors\Actions\Umami\ConnectUmamiAction;
use App\Domains\DataSources\Enums\ConnectionKind;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataSourceConnection>
 */
class DataSourceConnectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'environment' => 'production',
            'url' => 'https://app.example.com/lumberjack/data-sources',
            'pull_token' => 'lj_pull_test',
        ];
    }

    /**
     * An Umami website on a self-hosted instance, read with an API key.
     */
    public function umami(string $websiteId = 'site-1'): static
    {
        return $this->state(fn (): array => [
            'kind' => ConnectionKind::Umami,
            'environment' => ConnectUmamiAction::environment(),
            'url' => 'https://umami.example.com/api',
            'pull_token' => null,
            'secret' => 'umami_key',
            'website_id' => $websiteId,
            'website_name' => 'Shop',
        ]);
    }

    /** Signed in to Umami with a user name and password rather than an API key. */
    public function withUmamiLogin(): static
    {
        return $this->state(fn (): array => ['username' => 'admin', 'secret' => 'hunter2']);
    }

    /** The app was last seen answering. */
    public function answering(): static
    {
        return $this->state(fn (): array => ['last_checked_at' => now(), 'last_ok_at' => now()]);
    }
}
