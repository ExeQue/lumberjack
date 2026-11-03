<?php

declare(strict_types=1);

use App\Domains\Screens\Models\Screen;
use Illuminate\Support\Facades\DB;

covers(Screen::class);

describe('renaming the rotation dots to a progress bar', function () {
    it('turns hidden dots into a hidden progress bar and drops the old option', function () {
        $widget = fn (string $id, array $options): array => ['id' => $id, 'type' => 'stat', 'source' => 'requests', 'rotate' => ['p95'], 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'title' => null, 'options' => $options, 'scope' => []];
        $screen = Screen::factory()->create();
        DB::table('screens')->where('id', $screen->id)->update(['widgets' => json_encode([
            $widget('a', ['dots' => false, 'rotate_every' => '5']),
            $widget('b', ['dots' => true]),
            $widget('c', []),
        ])]);

        (require database_path('migrations/2026_11_08_100000_rename_widget_dots_to_progress.php'))->up();

        expect(array_column($screen->refresh()->widgets, 'options'))->toBe([
            ['rotate_every' => '5', 'progress' => false],
            [],
            [],
        ]);
    });
});
