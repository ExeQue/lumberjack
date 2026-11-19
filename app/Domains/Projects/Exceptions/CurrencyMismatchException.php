<?php

declare(strict_types=1);

namespace App\Domains\Projects\Exceptions;

use DomainException;

/**
 * A project with AI prices of its own cannot move to a team with another currency: the
 * prices are amounts in its old team's currency and would silently change meaning.
 */
final class CurrencyMismatchException extends DomainException implements ProjectExceptionInterface
{
}
