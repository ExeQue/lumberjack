<?php

declare(strict_types=1);

namespace App\Domains\Health\Support;

use App\Domains\Health\Exceptions\ProbeFailedException;
use Carbon\CarbonImmutable;

/**
 * Reads when a server's certificate expires, after a verified handshake for the host
 * name. Behind a class so tests can answer it.
 */
class TlsCertificateReader
{
    public function expiresAt(string $address, string $host, int $port, float $timeout): CarbonImmutable
    {
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'peer_name' => $host,
            'SNI_enabled' => true,
            'verify_peer' => true,
            'verify_peer_name' => true,
        ]]);
        $target = str_contains($address, ':') ? "[{$address}]:{$port}" : "{$address}:{$port}";
        $socket = @stream_socket_client('ssl://'.$target, $code, $error, $timeout, STREAM_CLIENT_CONNECT, $context);

        if ($socket === false) {
            throw new ProbeFailedException($error !== '' ? $error : 'The TLS handshake failed.');
        }

        $certificate = stream_context_get_params($socket)['options']['ssl']['peer_certificate'] ?? null;
        fclose($socket);
        $parsed = $certificate !== null ? openssl_x509_parse($certificate) : false;

        if (! is_array($parsed) || ! isset($parsed['validTo_time_t'])) {
            throw new ProbeFailedException('The server sent no certificate.');
        }

        return CarbonImmutable::createFromTimestamp((int) $parsed['validTo_time_t']);
    }
}
