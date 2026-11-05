<?php

declare(strict_types=1);

use App\Domains\Screens\Support\ScreenQr;
use ExeQue\Qr\Facades\Qr;

covers(ScreenQr::class);

describe('the QR codes of status screens', function () {
    it('draws the on screen variant amber on the dark panel, with the mark, as SVG', function () {
        $svg = ScreenQr::svg('https://lumberjack.test/pair/K7QM2X');

        expect($svg)->toStartWith('<svg')
            ->and(strtolower($svg))->toContain('#f0a93b')
            ->and(strtolower($svg))->toContain('#161a1e');
    });

    it('reads back what it holds', function () {
        $url = 'https://lumberjack.test/s/ls_'.str_repeat('a1', 20);
        $png = ScreenQr::screenPng($url);

        expect($png->mimeType())->toBe('image/png')
            ->and(Qr::read($png)->text)->toBe($url);
    });
});
