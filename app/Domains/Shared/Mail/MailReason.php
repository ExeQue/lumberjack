<?php

declare(strict_types=1);

namespace App\Domains\Shared\Mail;

use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Projects\Models\Project;

/**
 * Why someone gets a mail: a rule sending to a mail channel, an issue they watch, a
 * mention or an assignment. A mail lists every reason it stands in for (see MailRecipients),
 * and says where to change each kind.
 */
final readonly class MailReason
{
    public const string RULE = 'rule';

    public const string CHANNEL = 'channel';

    public const string WATCHING = 'watching';

    public const string MENTIONED = 'mentioned';

    public const string ASSIGNED = 'assigned';

    /**
     * @param  array<string, string>  $bold  shown in bold
     * @param  array<string, string>  $plain  shown as is
     */
    private function __construct(
        public string $kind,
        public array $bold = [],
        public array $plain = [],
        public ?string $changeUrl = null,
        public bool $health = false,
    ) {
    }

    /**
     * "the New error rule of Local Team sends to the mail channel Ops".
     */
    public static function rule(AlertRule $rule, NotificationChannel $channel, Project $project): self
    {
        $team = $rule->project_id === null;

        return new self(
            self::RULE,
            ['rule' => (string) __('alerts.rule_types.'.$rule->type->value.'.label'), 'channel' => $channel->name],
            ['owner' => $team ? $project->team->name : $project->name, 'where' => (string) __($team ? 'mail.footer.team_alerts' : 'mail.footer.project_alerts')],
            $team ? route('alerts.index') : route('projects.settings', $project),
            str_starts_with($rule->type->value, 'health_'),
        );
    }

    /**
     * A mail channel with no rule in between, as a test message is sent.
     */
    public static function channel(NotificationChannel $channel): self
    {
        return new self(self::CHANNEL, ['channel' => $channel->name], [], route('alerts.index'));
    }

    /**
     * "you watch DEMO-12 (you wrote a note on it)".
     *
     * @param  string|null  $why  how they came to watch it, as a phrase, if not by choice
     * @param  string|null  $stopUrl  the signed link that stops it
     */
    public static function watching(string $issue, ?string $why = null, ?string $stopUrl = null): self
    {
        return new self(self::WATCHING, [], ['issue' => $issue, 'why' => $why !== null ? ' ('.$why.')' : ''], $stopUrl);
    }

    public static function mentioned(string $author, string $project): self
    {
        return new self(self::MENTIONED, [], ['author' => $author, 'project' => $project]);
    }

    public static function assigned(string $actor, string $project): self
    {
        return new self(self::ASSIGNED, [], ['actor' => $actor, 'project' => $project]);
    }

    /**
     * As stored with a watched issue's update until its grouped mail goes out.
     *
     * @return array{kind: string, bold: array<string, string>, plain: array<string, string>, change_url: string|null, health: bool}
     */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'bold' => $this->bold, 'plain' => $this->plain, 'change_url' => $this->changeUrl, 'health' => $this->health];
    }

    /**
     * @param  array<string, mixed>  $stored  from toArray()
     */
    public static function fromArray(array $stored): self
    {
        /** @var array<string, string> $bold */
        $bold = is_array($stored['bold'] ?? null) ? $stored['bold'] : [];
        /** @var array<string, string> $plain */
        $plain = is_array($stored['plain'] ?? null) ? $stored['plain'] : [];

        return new self((string) ($stored['kind'] ?? self::CHANNEL), $bold, $plain, is_string($stored['change_url'] ?? null) ? $stored['change_url'] : null, (bool) ($stored['health'] ?? false));
    }

    /**
     * A mail that stands in for a personal one comes from the notifications sender.
     */
    public function isPersonal(): bool
    {
        return in_array($this->kind, [self::WATCHING, self::MENTIONED, self::ASSIGNED], true);
    }

    /**
     * "You get this because A and B. Change it under ...", then the host.
     *
     * @param  list<self>  $reasons
     * @return array{html: string, text: string}
     */
    public static function footer(array $reasons): array
    {
        $html = [];
        $text = [];

        if ($reasons !== []) {
            $html[] = e(__('mail.footer.because_start')).' '.self::join(array_map(fn (self $r): string => $r->phrase(true), $reasons)).'.';
            $text[] = __('mail.footer.because_start').' '.self::join(array_map(fn (self $r): string => $r->phrase(false), $reasons)).'.';

            foreach (self::changes($reasons) as $change) {
                $html[] = $change['html'];
                $text[] = $change['text'];
            }
        }

        $host = (string) __('mail.footer.host', ['host' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: config('app.url')]);

        return [
            'html' => ($html !== [] ? implode(' ', $html).'<br>' : '').e($host),
            'text' => ($text !== [] ? implode(' ', $text)."\n" : '').$host,
        ];
    }

    private function phrase(bool $html): string
    {
        $params = $html
            ? [...array_map(fn (string $v): string => '<strong>'.e($v).'</strong>', $this->bold), ...array_map(e(...), $this->plain)]
            : [...$this->bold, ...$this->plain];

        return (string) __('mail.footer.'.$this->kind, array_diff_key($params, ['where' => true]));
    }

    /**
     * Where to change each kind of reason, once per kind and place.
     *
     * @param  list<self>  $reasons
     * @return list<array{html: string, text: string}>
     */
    private static function changes(array $reasons): array
    {
        $changes = [];
        $profile = route('profile.edit');

        foreach ($reasons as $reason) {
            [$key, $links] = match ($reason->kind) {
                self::RULE => [$reason->health ? 'change_rule_health' : 'change_rule', ['where' => [$reason->plain['where'], $reason->changeUrl]]],
                self::CHANNEL => ['change_rule', ['where' => [(string) __('mail.footer.team_alerts'), $reason->changeUrl]]],
                self::WATCHING => $reason->changeUrl !== null
                    ? ['change_watching', ['stop' => [(string) __('mail.footer.stop_watching'), $reason->changeUrl], 'profile' => [(string) __('mail.footer.profile'), $profile]]]
                    : ['change_profile', ['profile' => [(string) __('mail.footer.profile'), $profile]]],
                default => ['change_profile', ['profile' => [(string) __('mail.footer.profile'), $profile]]],
            };

            $html = (string) __('mail.footer.'.$key, array_map(fn (array $l): string => '<a href="'.e((string) $l[1]).'" class="lj-link" style="color:#b86e00;">'.e($l[0]).'</a>', $links));
            $text = (string) __('mail.footer.'.$key, array_map(fn (array $l): string => $l[0].' ('.$l[1].')', $links));

            // A watching footer says where your profile is, so a mention's one would repeat it.
            if ($key === 'change_profile' && array_any($changes, fn (array $c): bool => str_contains($c['text'], $profile))) {
                continue;
            }

            $changes[$text] = ['html' => $html, 'text' => $text];
        }

        return array_values($changes);
    }

    /**
     * "a", "a and b", "a, b and c".
     *
     * @param  list<string>  $parts
     */
    private static function join(array $parts): string
    {
        $last = array_pop($parts);

        return $parts === [] ? (string) $last : implode(', ', $parts).' '.__('mail.footer.and').' '.$last;
    }
}
