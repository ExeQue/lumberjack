<?php

declare(strict_types=1);

namespace App\Domains\Screens\Support;

use ExeQue\Qr\Builders\EyesBuilder;
use ExeQue\Qr\Builders\PendingQr;
use ExeQue\Qr\Builders\Rendered;
use ExeQue\Qr\Facades\Qr;

/**
 * The QR codes of status screens, in the brand's two variants: amber on the dark panel
 * for a screen, and dark on white for print. The brand amber on white is only 1.9:1, so
 * the print variant darkens the eyes to #a9690f and leaves the modules dark.
 */
final class ScreenQr
{
    public const string AMBER = '#F0A93B';

    public const string PANEL = '#161a1e';

    public const string PRINT_EYES = '#a9690f';

    public const float LOGO_SIZE = 0.24;

    /** On a screen: inline SVG with the Lumberjack mark as SVG. */
    public static function svg(string $url): string
    {
        return self::screen($url)->logo(file: public_path('favicon.svg'), size: self::LOGO_SIZE)->svg()->contents();
    }

    /**
     * Anything that is not a link (a two-factor secret's otpauth:// address) in the same
     * on screen style, without the logo.
     */
    public static function textSvg(string $text): string
    {
        return self::style(Qr::text($text))->svg()->contents();
    }

    /** The on screen variant as PNG: what a camera sees of the SVG. */
    public static function screenPng(string $url, int $size = 600): Rendered
    {
        return self::screen($url)->logo(file: self::rasterLogo(), size: self::LOGO_SIZE)->png($size);
    }

    /** For a sticker on the wall: PNG, dark on white. */
    public static function printPng(string $url, int $size = 1200): Rendered
    {
        return Qr::url($url)
            ->modules('rounded')
            ->eyes(fn (EyesBuilder $eyes) => $eyes->outer('rounded')->inner('soft')->paint(self::PRINT_EYES))
            ->paint(self::PANEL)
            ->background('#ffffff')
            ->margin(2)
            ->logo(file: self::rasterLogo(), size: self::LOGO_SIZE)
            ->png($size);
    }

    private static function screen(string $url): PendingQr
    {
        return self::style(Qr::url($url));
    }

    private static function style(PendingQr $qr): PendingQr
    {
        return $qr->modules('rounded')->eyes(outer: 'rounded', inner: 'soft')->paint(self::AMBER)->background(self::PANEL)->margin(2);
    }

    /**
     * The mark as PNG: GD, which the Docker image draws with, cannot read SVG.
     */
    private static function rasterLogo(): string
    {
        return resource_path('images/qr-logo.png');
    }
}
