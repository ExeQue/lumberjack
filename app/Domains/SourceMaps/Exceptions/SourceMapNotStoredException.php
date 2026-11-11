<?php

declare(strict_types=1);

namespace App\Domains\SourceMaps\Exceptions;

use RuntimeException;

/**
 * The uploaded map could not be written to the disk; nothing of it is kept.
 */
class SourceMapNotStoredException extends RuntimeException implements SourceMapsExceptionInterface
{
}
