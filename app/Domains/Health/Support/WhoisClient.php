<?php

declare(strict_types=1);

namespace App\Domains\Health\Support;

use App\Domains\Health\Exceptions\ProbeFailedException;

/**
 * Asks a whois server, behind a class so tests can answer it.
 */
class WhoisClient
{
    private const int PORT = 43;

    private const int MAX_BYTES = 65_536;

    public function query(string $address, string $query, float $timeout): string
    {
        $target = str_contains($address, ':') ? "[{$address}]:".self::PORT : "{$address}:".self::PORT;
        $socket = @stream_socket_client('tcp://'.$target, $code, $error, $timeout);

        if ($socket === false) {
            throw new ProbeFailedException($error !== '' ? $error : 'Could not reach the whois server.');
        }

        stream_set_timeout($socket, (int) ceil($timeout));
        @fwrite($socket, $query."\r\n");
        $answer = (string) stream_get_contents($socket, self::MAX_BYTES);
        fclose($socket);

        return $answer;
    }
}
