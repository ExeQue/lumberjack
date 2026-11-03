<?php

declare(strict_types=1);

use App\Domains\Screens\Support\ScreenLayout;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widgets get x and y: the cells each was drawn in while widgets flowed row by row in
 * order, so no screen changes. Nothing to undo: without x and y they flow the same way.
 */
return new class () extends Migration {
    public function up(): void
    {
        DB::table('screens')->orderBy('id')->each(function (object $screen): void {
            $widgets = json_decode((string) $screen->widgets, true);

            if (! is_array($widgets) || $widgets === []) {
                return;
            }

            DB::table('screens')->where('id', $screen->id)->update([
                'widgets' => json_encode(ScreenLayout::positioned(array_values($widgets), (int) $screen->cols, (int) $screen->rows)),
            ]);
        });
    }

    public function down(): void
    {
    }
};
