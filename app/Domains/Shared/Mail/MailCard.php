<?php

declare(strict_types=1);

namespace App\Domains\Shared\Mail;

use App\Domains\Issues\Models\Issue;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Everything one Lumberjack mail shows, in the order the card lays it out. Every mail is
 * this one card (resources/views/mail/card.blade.php), filled in differently; a part left
 * empty is left out. Strings are escaped when rendered, an Htmlable is trusted as is.
 *
 * Tones: red (new, escalating, down), amber (regressed, degraded), blue (personal: a
 * mention, an assignment, a watched issue), green (resolved, recovered).
 */
final readonly class MailCard
{
    /**
     * @param  array{value: string, text: string}|null  $stat  a big number and what it counts
     * @param  array{main: string, muted: ?string}|null  $box  the message and where, in mono
     * @param  array{initials: string, name: string, what: string, body: string}|null  $comment  what someone wrote
     * @param  list<array{ref: ?string, title: string, url: string, state?: ?string, tone?: ?string}>  $rows  issues, each a link
     * @param  list<array{who: string, what: string, at: string, body: ?string, tone: string}>  $timeline  updates, oldest first
     * @param  array<string, string|array{value: string, mono?: bool, tone?: ?string}>  $facts  label => value, two to a row
     * @param  array{label: string, body: string}|null  $quote  a note quoted under the facts
     * @param  array{label: string, url: string}|null  $button  the one primary action
     * @param  array{label: string, url: string}|null  $link  a secondary link beside it
     * @param  list<MailReason>  $reasons  why the reader gets it; the footer says so unless $footer is given
     */
    public function __construct(
        public string $subject,
        public string $preheader,
        public string $title,
        public ?string $titleRef = null,
        public ?string $titleUrl = null,
        public bool $titleRefLast = false,
        public ?string $kind = null,
        public ?string $tone = null,
        public ?string $pill = null,
        public string|Htmlable|null $lead = null,
        public ?array $stat = null,
        public ?array $box = null,
        public ?array $comment = null,
        public array $rows = [],
        public ?string $more = null,
        public array $timeline = [],
        public array $facts = [],
        public ?array $quote = null,
        public ?array $button = null,
        public ?array $link = null,
        public string|Htmlable|null $after = null,
        public ?string $fallbackUrl = null,
        public array $reasons = [],
        public string|Htmlable|null $footer = null,
    ) {
    }

    /**
     * "[Demo] New error: DEMO-12 RuntimeException".
     */
    public static function subject(string $project, string $subject): string
    {
        return __('mail.subject', ['project' => $project, 'subject' => $subject]);
    }

    /**
     * "DEMO-12 RuntimeException", or the title alone for an issue without a number.
     */
    public static function issueName(Issue $issue): string
    {
        return $issue->number !== null ? $issue->reference().' '.$issue->title : $issue->title;
    }

    /**
     * "DEMO-12", or the title for an issue without a number.
     */
    public static function issueRef(Issue $issue): string
    {
        return $issue->number !== null ? $issue->reference() : $issue->title;
    }

    /**
     * "MH" for Morten Harders.
     */
    public static function initials(string $name): string
    {
        $words = preg_split('/[\s(]+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];

        return mb_strtoupper(mb_substr($words[0], 0, 1).(count($words) > 1 ? mb_substr($words[1], 0, 1) : ''));
    }

    /**
     * The footer, as HTML and as text: the reasons, then where to change them.
     *
     * @return array{html: string, text: string}
     */
    public function footer(): array
    {
        if ($this->footer !== null) {
            $host = MailReason::footer([]);

            return [
                'html' => ($this->footer instanceof Htmlable ? $this->footer->toHtml() : e($this->footer)).'<br>'.$host['html'],
                'text' => self::plain($this->footer)."\n".$host['text'],
            ];
        }

        return MailReason::footer($this->reasons);
    }

    /**
     * HTML as plain text, for the text part of the mail.
     */
    public static function plain(string|Htmlable|null $value): string
    {
        if ($value === null) {
            return '';
        }

        if (! $value instanceof Htmlable) {
            return $value;
        }

        // Links keep their target in brackets, the way a text mail can show one.
        $html = preg_replace('/<a [^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/s', '$2 ($1)', $value->toHtml()) ?? '';

        return html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)), ENT_QUOTES | ENT_HTML5);
    }
}
