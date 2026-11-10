<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Mosparo\Exceptions;

use RuntimeException;

/**
 * mosparo could not be asked, or did not answer what was asked; the message is shown to the user.
 */
final class MosparoRequestFailedException extends RuntimeException implements MosparoExceptionInterface
{
}
