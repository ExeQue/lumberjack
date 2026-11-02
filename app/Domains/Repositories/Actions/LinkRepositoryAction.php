<?php

declare(strict_types=1);

namespace App\Domains\Repositories\Actions;

use App\Domains\Projects\Models\Project;
use App\Domains\Repositories\Enums\RepositoryProvider;
use App\Domains\Repositories\Models\ProjectRepository;
use Carbon\CarbonImmutable;

class LinkRepositoryAction
{
    /**
     * A null token keeps the stored one, so the form never has to show it.
     *
     * @param  list<string>  $labels
     */
    public function execute(Project $project, RepositoryProvider $provider, string $repository, ?string $token, ?string $tokenExpiresAt, array $labels, bool $allowPublic): ProjectRepository
    {
        $link = $project->repository ?? new ProjectRepository(['project_id' => $project->id]);

        $link->fill(array_filter([
            'provider' => $provider,
            'repository' => trim($repository, '/ '),
            'token' => $token,
            'labels' => array_values(array_filter(array_map('trim', $labels))),
            'allow_public' => $allowPublic,
        ], fn ($v): bool => $v !== null));

        $link->token_expires_at = $tokenExpiresAt !== null ? CarbonImmutable::parse($tokenExpiresAt) : null;
        $link->save();

        return $link;
    }
}
