<?php

declare(strict_types=1);

namespace App\Domains\Screens\Support;

use ExeQue\Qr\Builders\Rendered;
use ExeQue\Qr\Facades\Qr;
use ExeQue\Qr\Rendering\ScanSafety;
use ExeQue\Qr\Validation\StyleRule;

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

        if ($qr === null) {
            return null;
        }

        // Transparent unless a background is picked, so the tile shows through. Without
        // one there is nothing to measure the colors against, so the contrast guard is
        // off; a code lighter than its ground is fine on a dark screen. The rest apply.
        $background = $value('qr_background') ?: null;
        $qr = $qr->style(StyleRule::toStyle(self::style($options), base: 'lumberjack'))
            ->background($background)
            ->scanSafety(new ScanSafety(minContrast: $background === null ? null : 3.0, inverted: null));

        // The mark has no plate.
        return (($options['qr_logo'] ?? true) ? $qr->logo(file: resource_path('images/qr-mark.svg'), size: self::LOGO_SIZE, shape: 'circle') : $qr)
            ->svg()
            ->contents();
    }

    /**
     * A QR code widget's style options (WidgetSchema's qr_style section) as the library's
     * style input, over the `lumberjack` style: the same modules and eyes when none are picked.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private static function style(array $options): array
    {
        $paint = $options['qr_color'] ?? null;
        $eyes = ($options['qr_eyes'] ?? 'mix') === 'custom'
            ? ['outer' => ['shape' => $options['qr_outer'] ?? 'rounded'], 'inner' => ['shape' => $options['qr_inner'] ?? 'soft']]
            : ['preset' => $options['qr_eyes'] ?? 'mix'];

        return array_filter([
            'modules' => array_filter(['shape' => $options['qr_module'] ?? 'rounded', 'paint' => $paint]),
            'eyes' => array_filter([...$eyes, 'paint' => $paint]),
            'filters' => array_map(fn (string $name): array => ['name' => $name], (array) ($options['qr_filters'] ?? [])),
        ]);
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
