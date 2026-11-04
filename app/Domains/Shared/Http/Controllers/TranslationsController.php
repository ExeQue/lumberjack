<?php

declare(strict_types=1);

namespace App\Domains\Shared\Http\Controllers;

use App\Domains\Shared\Support\Translations;
use Illuminate\Http\JsonResponse;

/**
 * The UI strings of a locale for the React pages, fetched once by the browser and kept:
 * the address carries their version, so a changed file is a new address.
 */
class TranslationsController
{
    public function __invoke(string $locale): JsonResponse
    {
        abort_unless(Translations::exists($locale), 404);

        return response()->json(Translations::forFrontend($locale))
            ->header('Cache-Control', 'public, max-age=31536000, immutable');
    }
}
