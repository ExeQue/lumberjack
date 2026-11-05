<?php

declare(strict_types=1);

/*
 * Only what Lumberjack changes; the package's own config fills the rest.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Colors
    |--------------------------------------------------------------------------
    |
    | The brand amber and the dark panel the screens are drawn on, by name.
    |
    */

    'colors' => [
        'amber' => '#F0A93B',
        'panel' => '#161a1e',
        'amber-dark' => '#b5720f',
        'paper' => '#ffffff',
    ],

    /*
    |--------------------------------------------------------------------------
    | Styles
    |--------------------------------------------------------------------------
    |
    | Every QR code Lumberjack draws: amber, rounded modules and eyes, on the
    | dark panel; on a light screen the screen's darker amber (3.9:1 on white,
    | where the brand amber is only 1.9:1). Plain values, so the config can be
    | cached. The logo is added where a code is drawn, since SVG and PNG need
    | different files.
    |
    */

    'styles' => [
        'lumberjack' => [
            'modules' => ['shape' => 'rounded', 'paint' => 'amber'],
            'eyes' => [
                'top-left' => ['outer' => ['shape' => 'rounded'], 'inner' => ['shape' => 'soft']],
                'top-right' => ['outer' => ['shape' => 'rounded'], 'inner' => ['shape' => 'soft']],
                'bottom-left' => ['outer' => ['shape' => 'rounded'], 'inner' => ['shape' => 'soft']],
            ],
            'background' => 'panel',
            'margin' => 2,
        ],
        'lumberjack-light' => [
            'extends' => 'lumberjack',
            'modules' => ['paint' => 'amber-dark'],
            'background' => 'paper',
        ],
    ],

];
