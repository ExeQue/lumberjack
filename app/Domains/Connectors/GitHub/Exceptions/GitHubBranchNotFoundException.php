<?php

declare(strict_types=1);

namespace App\Domains\Connectors\GitHub\Exceptions;

use RuntimeException;

/**
 * The repository has no branch of the name chosen; the message is shown on the branch field.
 */
final class GitHubBranchNotFoundException extends RuntimeException implements GitHubExceptionInterface
{
}
