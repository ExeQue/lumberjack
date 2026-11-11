<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Connectors\GitHub\Data\GitHubConfig;
use App\Domains\Connectors\MobilePay\Data\MobilePayConfig;
use App\Domains\Connectors\MobilePay\Data\MobilePayCredentials;
use App\Domains\Connectors\Mosparo\Data\MosparoConfig;
use App\Domains\Connectors\Umami\Data\UmamiConfig;
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
            'environment' => ConnectionKind::Umami->environment(),
            'url' => 'https://umami.example.com/api',
            'pull_token' => null,
            'name' => 'Shop',
            'config' => new UmamiConfig($websiteId, null, 'umami_key'),
        ]);
    }

    /**
     * A mosparo project, read with its public and private key.
     */
    public function mosparo(): static
    {
        return $this->state(fn (): array => [
            'kind' => ConnectionKind::Mosparo,
            'environment' => ConnectionKind::Mosparo->environment(),
            'url' => 'https://mosparo.example.com',
            'pull_token' => null,
            'name' => 'Contact form',
            'config' => new MosparoConfig('public_key', 'private_key'),
        ]);
    }

    /**
     * A GitHub repository, read with a fine-grained token.
     */
    public function github(): static
    {
        return $this->state(fn (): array => [
            'kind' => ConnectionKind::GitHub,
            'environment' => ConnectionKind::GitHub->environment(),
            'url' => 'https://github.com/acme/shop',
            'pull_token' => null,
            'name' => 'acme/shop',
            'config' => new GitHubConfig('acme/shop', 'github_pat_test'),
        ]);
    }

    /**
     * A Vipps MobilePay ledger in NOK, read with a sales unit's API keys.
     */
    public function mobilePay(): static
    {
        return $this->state(fn (): array => [
            'kind' => ConnectionKind::MobilePay,
            'environment' => ConnectionKind::MobilePay->environment(),
            'url' => 'https://api.vipps.no',
            'pull_token' => null,
            'name' => 'ACME Fitness Oslo',
            'config' => new MobilePayConfig(new MobilePayCredentials('client-id', 'client-secret', 'subscription-key', '123455'), '302321', 'NOK'),
        ]);
    }

    /** Signed in to Umami with a user name and password rather than an API key. */
    public function withUmamiLogin(): static
    {
        return $this->state(fn (array $attributes): array => ['config' => new UmamiConfig($attributes['config']->websiteId, 'admin', 'hunter2')]);
    }

    /** The app was last seen answering. */
    public function answering(): static
    {
        return $this->state(fn (): array => ['last_checked_at' => now(), 'last_ok_at' => now()]);
    }
}
