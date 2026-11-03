<?php

declare(strict_types=1);

namespace App\Domains\Shared\Http\Controllers;

use App\Domains\Shared\Mail\MailPreviews;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Every mail with sample data, light and dark side by side, plus its text part. Only
 * registered in the local environment (routes/web.php); see docs/administration.md.
 */
class MailPreviewController
{
    public function index(MailPreviews $previews): View
    {
        return view('mail.preview.index', ['mails' => array_map(fn (array $p): string => $p['label'], $previews->all())]);
    }

    public function show(MailPreviews $previews, string $name): View
    {
        $mail = $this->mail($previews, $name);

        return view('mail.preview.show', [
            'name' => $name,
            'mails' => array_map(fn (array $p): string => $p['label'], $previews->all()),
            'subject' => $mail->subject,
            'from' => $mail->from,
            'preheader' => $mail->data()['card']->preheader,
            'text' => $this->text($mail),
        ]);
    }

    /**
     * The HTML part as a mail client in light or dark mode would show it, whatever the
     * browser's own scheme: the dark rules always or never apply.
     */
    public function frame(MailPreviews $previews, string $name, string $scheme): Response
    {
        $html = (string) $this->mail($previews, $name)->render();
        $html = str_replace('@media (prefers-color-scheme: dark)', $scheme === 'dark' ? '@media all' : '@media not all', $html);

        return response($html);
    }

    private function mail(MailPreviews $previews, string $name): MailMessage
    {
        $preview = $previews->all()[$name] ?? abort(404);

        return ($preview['mail'])();
    }

    private function text(MailMessage $mail): string
    {
        return view('mail.card-text', $mail->data())->render();
    }
}
