<?php

declare(strict_types=1);

namespace App\Domains\Repositories\Contracts;

use App\Domains\Repositories\Models\ProjectRepository;

/**
 * One code host (GitHub now; GitLab and Bitbucket can follow as new drivers).
 */
interface RepositoryDriver
{
    /**
     * Whether the token can reach the repository; returns whether the repository is public.
     */
    public function verify(ProjectRepository $repository): bool;

    /**
     * @param  list<string>  $labels
     * @return string the URL of the created issue
     */
    public function createIssue(ProjectRepository $repository, string $title, string $body, array $labels): string;
}
