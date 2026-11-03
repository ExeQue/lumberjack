<?php

declare(strict_types=1);

namespace App\Domains\Administration\Notifications;

use App\Domains\Shared\Mail\MailCard;
use App\Domains\Shared\Mail\SendsMailCards;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\HtmlString;

/**
 * The password reset and email confirmation mails, in Lumberjack's layout. Laravel's own
 * notifications still send them; toMailUsing() hands their content over to this.
 */
class AccountMails
{
    use SendsMailCards;

    public static function register(): void
    {
        ResetPassword::toMailUsing(fn (User $user, string $token): MailMessage => app(self::class)->resetMail($user, $token));
        VerifyEmail::toMailUsing(fn (User $user, string $url): MailMessage => app(self::class)->verifyMail($user, $url));
    }

    public function resetMail(User $user, string $token): MailMessage
    {
        return $this->cardMail($this->resetCard($user, url(route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()], false))), true);
    }

    public function verifyMail(User $user, string $url): MailMessage
    {
        return $this->cardMail($this->verifyCard($user, $url), true);
    }

    public function resetCard(User $user, string $url): MailCard
    {
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

        return new MailCard(
            subject: (string) __('mail.reset.subject'),
            preheader: (string) __('mail.reset.preheader', ['minutes' => $minutes]),
            title: (string) __('mail.reset.title'),
            lead: new HtmlString((string) __('mail.reset.lead', ['email' => '<strong class="lj-ink" style="color:#15181c;">'.e($user->email).'</strong>'])),
            button: ['label' => (string) __('mail.reset.button'), 'url' => $url],
            after: trim(__('mail.reset.after', ['minutes' => $minutes]).($user->two_factor_confirmed_at !== null ? ' '.__('mail.reset.two_factor') : '')),
            fallbackUrl: $url,
            footer: (string) __('mail.reset.footer'),
        );
    }

    public function verifyCard(User $user, string $url): MailCard
    {
        $minutes = (int) config('auth.verification.expire', 60);

        return new MailCard(
            subject: (string) __('mail.verify.subject'),
            preheader: (string) __('mail.verify.preheader'),
            title: (string) __('mail.verify.title'),
            lead: new HtmlString((string) __('mail.verify.lead', ['email' => '<strong class="lj-ink" style="color:#15181c;">'.e($user->email).'</strong>'])),
            button: ['label' => (string) __('mail.verify.button'), 'url' => $url],
            after: (string) __('mail.verify.after', ['minutes' => $minutes]),
            fallbackUrl: $url,
            footer: (string) __('mail.verify.footer'),
        );
    }
}
