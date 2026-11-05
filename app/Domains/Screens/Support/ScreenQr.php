<?php

declare(strict_types=1);

namespace App\Domains\Screens\Support;

use ExeQue\Qr\Builders\Rendered;
use ExeQue\Qr\Facades\Qr;
use ExeQue\Qr\Rendering\ScanSafety;

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
        return Qr::url($url)->style('lumberjack')->logo(file: public_path('favicon.svg'), size: self::LOGO_SIZE, shape: 'circle')->svg()->contents();
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
     * A QR code widget's picture, from its options: a link, a wifi network or any text, on
     * no background, so the same picture suits a light and a dark screen. Null while
     * there is nothing to encode yet.
     *
     * @param  array<string, mixed>  $options
     */
    public static function widgetSvg(array $options): ?string
    {
        $value = fn (string $key): string => trim((string) ($options[$key] ?? ''));

        $security = $value('security') ?: 'WPA';

        $qr = match ($options['content'] ?? 'link') {
            'wifi' => $value('ssid') === '' ? null : Qr::wifi($value('ssid'), $security === 'nopass' ? null : ($value('password') ?: null), $security, (bool) ($options['hidden_network'] ?? false)),
            'text' => $value('qr_text') === '' ? null : Qr::text($value('qr_text')),
            default => $value('url') === '' ? null : Qr::url($value('url')),
        };

        // Transparent, so the tile shows through. Amber has too little contrast for the
        // guard on white, so that one guard is off; the rest still apply. The mark has
        // no plate.
        return $qr?->style('lumberjack')->background(null)->scanSafety(new ScanSafety(minContrast: null, inverted: null))->logo(file: resource_path('images/qr-mark.svg'), size: self::LOGO_SIZE, shape: 'circle')->svg()->contents();
    }

    /**
     * The on screen variant as PNG: what a camera sees of the SVG. The mark as PNG, since
     * GD, which the Docker image draws with, cannot read SVG.
     */
    public static function screenPng(string $url, int $size = 600): Rendered
    {
        return Qr::url($url)->style('lumberjack')->logo(file: resource_path('images/qr-logo.png'), size: self::LOGO_SIZE, shape: 'circle')->png($size);
    }
}
