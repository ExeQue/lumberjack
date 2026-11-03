<?php

declare(strict_types=1);

use App\Domains\Shared\Http\Controllers\MailPreviewController;
use App\Domains\Shared\Mail\MailPreviews;
use App\Models\User;

covers(MailPreviews::class, MailPreviewController::class);

describe('every mail', function () {
    it('renders as HTML and text with a subject, a preheader and the host in the footer', function () {
        foreach (app(MailPreviews::class)->all() as $name => $preview) {
            $mail = ($preview['mail'])();
            $html = (string) $mail->render();
            $text = view($mail->view['text'], $mail->data())->render();

            expect($mail->subject)->not->toBe('', $name)
                ->and($html)->toContain('prefers-color-scheme: dark', 'name="color-scheme"', e($mail->data()['card']->preheader), 'images/lumberjack-avatar.png', 'Lumberjack · ')
                ->and($text)->toContain('Lumberjack · ')
                ->and($text)->not->toContain('<strong', '<a ')
                ->and($html.$text)->not->toContain("\u{2013}", "\u{2014}");
        }
    });
});

describe('the preview', function () {
    it('is not there outside local development', function () {
        $this->actingAs(User::factory()->create())->get('/dev/mail')->assertNotFound();
    });
});
