<?php

declare(strict_types=1);

namespace App\Domains\Health\Support;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * Where a response body is written as it arrives: it keeps the first $max bytes and then
 * takes no more, which makes curl stop the transfer. See NetworkGuard::send().
 */
final class CappedStream implements StreamInterface
{
    use StreamDecoratorTrait;

    /** Whether more came than it keeps. */
    public bool $full = false;

    private StreamInterface $stream;

    public function __construct(
        private readonly int $max,
    ) {
        $this->stream = Utils::streamFor(Utils::tryFopen('php://temp', 'r+'));
    }

    public function write(string $string): int
    {
        $room = $this->max - (int) $this->stream->getSize();

        if (strlen($string) <= $room) {
            return $this->stream->write($string);
        }

        $this->stream->write(substr($string, 0, max(0, $room)));
        $this->full = true;

        // Fewer bytes than given: curl stops reading.
        return 0;
    }
}
