<?php

declare(strict_types=1);

use App\Domains\Shared\Http\Controllers\MailPreviewController;
use Illuminate\Support\Facades\Route;

/*
 * The web routes, one file per area under routes/web/.
 */

Route::redirect('/', '/projects')->name('home');

require __DIR__.'/web/public.php';

Route::middleware(['auth', 'verified'])->group(function () {
    require __DIR__.'/web/account.php';
    require __DIR__.'/web/projects.php';
    require __DIR__.'/web/admin.php';
    require __DIR__.'/web/team.php';
    require __DIR__.'/web/screens.php';
});

require __DIR__.'/settings.php';

// Every mail with sample data, light and dark; local development only. See docs/administration.md.
if (app()->environment('local')) {
    Route::middleware('auth')->prefix('dev/mail')->group(function () {
        Route::get('/', [MailPreviewController::class, 'index'])->name('dev.mail.index');
        Route::get('{name}', [MailPreviewController::class, 'show'])->name('dev.mail.show');
        Route::get('{name}/{scheme}', [MailPreviewController::class, 'frame'])->whereIn('scheme', ['light', 'dark'])->name('dev.mail.frame');
    });
}
