<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Data;

use App\Domains\Alerts\Contracts\Alert;
use App\Domains\Projects\Models\Project;

/**
 * What a personal notification says, the same by mail, in app and by push: a title, a
 * line of text, the page it opens, a tag under which a newer one replaces an older one,
 * and the project it is about, if any, for the notification center's label.
 */
final readonly class PushMessage
{
    public function __construct(
        public string $title,
        public string $body,
        public string $url,
        public string $tag,
        public ?string $project = null,
        public ?string $projectUuid = null,
        public ?string $teamUuid = null,
    ) {
    }

    public static function about(Project $project, string $title, string $body, string $url, string $tag): self
    {
        return new self($title, $body, $url, $tag, $project->name, $project->uuid, $project->team->uuid);
    }

    /**
     * A team alert as a notification: its headline, then what it is about and what happened.
     */
    public static function fromAlert(Alert $alert, Project $project, string $tag): self
    {
        $summary = $alert->summary();

        return self::about($project, $summary['title'], implode(' - ', array_filter([$summary['subject'], $summary['body'][0] ?? null])), $alert->url(), $tag);
    }
}
