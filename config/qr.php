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
        'ink' => '#16191d',
        'amber-dark' => '#a9690f',
        'paper' => '#ffffff',
    ],

    /*
    |--------------------------------------------------------------------------
    | Styles
    |--------------------------------------------------------------------------
    |
    | Every QR code Lumberjack draws: amber, rounded modules and eyes, on the
    | dark panel; on a light screen dark on white with darker amber eyes (the
    | brand amber on white is only 1.9:1). Plain values, so the config can be
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
            'modules' => ['paint' => 'ink'],
            'eyes' => [
                'top-left' => ['outer' => ['paint' => 'amber-dark'], 'inner' => ['paint' => 'amber-dark']],
                'top-right' => ['outer' => ['paint' => 'amber-dark'], 'inner' => ['paint' => 'amber-dark']],
                'bottom-left' => ['outer' => ['paint' => 'amber-dark'], 'inner' => ['paint' => 'amber-dark']],
            ],
            'background' => 'paper',
        ],
    ],

];
