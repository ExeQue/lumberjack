<?php

declare(strict_types=1);

namespace App\Domains\Screens\Support;

use ExeQue\Qr\Builders\Rendered;
use ExeQue\Qr\Facades\Qr;

/**
 * The QR codes Lumberjack draws, in the `lumberjack` style of config/qr.php: amber on
 * the dark panel.
 */
final class ScreenQr
{
    public const float LOGO_SIZE = 0.24;

    /** On a screen: inline SVG with the Lumberjack mark as SVG. */
    public static function svg(string $url): string
    {
        return Qr::url($url)->style('lumberjack')->logo(file: public_path('favicon.svg'), size: self::LOGO_SIZE)->svg()->contents();
    }

    /**
     * Anything that is not a link (a two-factor secret's otpauth:// address), without the
     * logo.
     */
    public static function textSvg(string $text): string
    {
        return Qr::text($text)->style('lumberjack')->svg()->contents();
    }

    /**
     * The on screen variant as PNG: what a camera sees of the SVG. The mark as PNG, since
     * GD, which the Docker image draws with, cannot read SVG.
     */
    public static function screenPng(string $url, int $size = 600): Rendered
    {
        return Qr::url($url)->style('lumberjack')->logo(file: resource_path('images/qr-logo.png'), size: self::LOGO_SIZE)->png($size);
    }
}
