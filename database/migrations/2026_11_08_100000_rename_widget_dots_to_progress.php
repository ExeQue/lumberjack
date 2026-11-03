<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A rotating widget's position dots became a progress bar: the dots option is now
 * progress, and a widget that hid its dots hides the bar. Nothing to undo: an unknown
 * option is ignored.
 */
return new class () extends Migration {
    public function up(): void
    {
        DB::table('screens')->orderBy('id')->each(function (object $screen): void {
            $widgets = json_decode((string) $screen->widgets, true);

            if (! is_array($widgets) || ! array_filter($widgets, fn (mixed $w): bool => is_array($w) && array_key_exists('dots', (array) ($w['options'] ?? [])))) {
                return;
            }

            foreach ($widgets as &$widget) {
                $options = (array) ($widget['options'] ?? []);

                if (array_key_exists('dots', $options)) {
                    if ($options['dots'] === false) {
                        $options['progress'] = false;
                    }

                    unset($options['dots']);
                    $widget['options'] = $options;
                }
            }

            DB::table('screens')->where('id', $screen->id)->update(['widgets' => json_encode(array_values($widgets))]);
        });
    }

    public function down(): void
    {
    }
};
