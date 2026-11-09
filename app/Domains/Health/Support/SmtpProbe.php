<?php

declare(strict_types=1);

namespace App\Domains\Health\Support;

use App\Domains\Health\Exceptions\ProbeFailedException;

/**
 * Greets a mail server the way a sender would, and sends nothing. The certificate is
 * verified on every encrypted connection, so one that expired fails the check.
 */
class SmtpProbe
{
    public const string ENCRYPTION_STARTTLS = 'STARTTLS';

    public const string ENCRYPTION_TLS = 'TLS';

    public const string ENCRYPTION_NONE = 'None';

    private const int MAX_REPLY_LINES = 50;

    /**
     * The server's greeting.
     */
    public function check(string $address, string $host, int $port, string $encryption, float $timeout): string
    {
        $context = stream_context_create(['ssl' => ['peer_name' => $host, 'SNI_enabled' => true, 'verify_peer' => true, 'verify_peer_name' => true]]);
        $target = str_contains($address, ':') ? "[{$address}]:{$port}" : "{$address}:{$port}";
        $scheme = $encryption === self::ENCRYPTION_TLS ? 'ssl' : 'tcp';
        $socket = @stream_socket_client($scheme.'://'.$target, $code, $error, $timeout, STREAM_CLIENT_CONNECT, $context);

        if ($socket === false) {
            throw new ProbeFailedException($error !== '' ? $error : "Could not connect to port {$port}.");
        }

        stream_set_timeout($socket, (int) ceil($timeout));

        try {
            return $this->converse($socket, $encryption === self::ENCRYPTION_STARTTLS);
        } finally {
            fclose($socket);
        }
    }

    /**
     * @param  resource  $socket
     */
    public function converse($socket, bool $startTls): string
    {
        [$code, $greeting] = $this->reply($socket);

        if ($code !== 220) {
            throw new ProbeFailedException(__('health.results.smtp_refused', ['reply' => $code.' '.$greeting[0]]));
        }

        $this->send($socket, 'EHLO '.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost'));
        [$code, $capabilities] = $this->reply($socket);

        if ($code !== 250) {
            throw new ProbeFailedException(__('health.results.smtp_refused', ['reply' => $code.' '.$capabilities[0]]));
        }

        if ($startTls) {
            $this->startTls($socket, $capabilities);
        }

        // A server that hangs up before the goodbye has still answered.
        @fwrite($socket, "QUIT\r\n");

        return $greeting[0];
    }

    /**
     * @param  resource  $socket
     * @param  list<string>  $capabilities
     */
    private function startTls($socket, array $capabilities): void
    {
        if (! in_array('STARTTLS', array_map(fn (string $line): string => strtoupper(trim($line)), $capabilities), true)) {
            throw new ProbeFailedException(__('health.results.smtp_no_starttls'));
        }

        $this->send($socket, 'STARTTLS');
        [$code, $answer] = $this->reply($socket);

        if ($code !== 220) {
            throw new ProbeFailedException(__('health.results.smtp_refused', ['reply' => $code.' '.$answer[0]]));
        }

        if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
            throw new ProbeFailedException(__('health.results.smtp_tls_failed'));
        }
    }

    /**
     * @param  resource  $socket
     */
    private function send($socket, string $line): void
    {
        if (@fwrite($socket, $line."\r\n") === false) {
            throw new ProbeFailedException(__('health.results.smtp_silent'));
        }
    }

    /**
     * A reply's code and its text, one entry per line.
     *
     * @param  resource  $socket
     * @return array{int, non-empty-list<string>}
     */
    private function reply($socket): array
    {
        $lines = [];

        for ($read = 0; $read < self::MAX_REPLY_LINES; $read++) {
            $line = fgets($socket, 1024);

            if ($line === false) {
                throw new ProbeFailedException(__('health.results.smtp_silent'));
            }

            $lines[] = trim(substr($line, 4));

            if (($line[3] ?? ' ') !== '-') {
                return [(int) substr($line, 0, 3), $lines];
            }
        }

        throw new ProbeFailedException(__('health.results.smtp_silent'));
    }
}
