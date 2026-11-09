<?php

declare(strict_types=1);

use App\Domains\Health\Exceptions\ProbeFailedException;
use App\Domains\Health\Support\SmtpProbe;

covers(SmtpProbe::class);

describe('talking to a mail server', function () {
    beforeEach(function () {
        $this->converse = function (string $server, bool $startTls): string {
            [$client, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            fwrite($peer, $server);

            // Kept open to the end of the test, as a server that goes on listening is; nothing to say is a hang-up.
            if ($server === '') {
                fclose($peer);
            }

            $this->peer = $peer;

            return app(SmtpProbe::class)->converse($client, $startTls);
        };
    });

    it('gives back the greeting, past a reply of several lines', function () {
        expect(($this->converse)("220 mail.example.com ESMTP\r\n250-mail.example.com\r\n250-SIZE 1000\r\n250 8BITMIME\r\n", false))
            ->toBe('mail.example.com ESMTP');
    });

    it('refuses what a server should not say', function (string $server, bool $startTls, string $message) {
        expect(fn () => ($this->converse)($server, $startTls))->toThrow(ProbeFailedException::class, $message);
    })->with([
        'a refusing greeting' => ["554 go away\r\n", false, 'refused'],
        'no starttls on offer' => ["220 hi\r\n250-mail\r\n250 SIZE 1000\r\n", true, 'STARTTLS'],
        'hanging up' => ['', false, 'stopped answering'],
    ]);
});
