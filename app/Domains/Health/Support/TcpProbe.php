<?php

declare(strict_types=1);

namespace App\Domains\Health\Support;

use App\Domains\Health\Exceptions\ProbeFailedException;

/**
 * Opens and closes a TCP connection, behind a class so tests can answer it.
 */
class TcpProbe
{
    public function connect(string $address, int $port, float $timeout): void
    {
        $target = str_contains($address, ':') ? "[{$address}]:{$port}" : "{$address}:{$port}";
        $socket = @stream_socket_client('tcp://'.$target, $code, $error, $timeout);

        if ($socket === false) {
            throw new ProbeFailedException($error !== '' ? $error : "Could not connect to port {$port}.");
        }

        fclose($socket);
    }
}
