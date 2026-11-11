<?php

declare(strict_types=1);

namespace App\Domains\Connectors\GitHub\Exceptions;

use RuntimeException;

/**
 * GitHub could not be asked, or did not answer what was asked; the message is shown to the user.
 */
final class GitHubRequestFailedException extends RuntimeException implements GitHubExceptionInterface
{
}
