<?php

declare(strict_types=1);

namespace App\Domains\Issues\Actions;

use App\Domains\Issues\Enums\IssueEventType;
use App\Domains\Issues\Enums\LabelColor;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\Label;
use App\Domains\Live\Actions\BroadcastProjectChangeAction;
use App\Domains\Mcp\Models\McpToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Gives an issue exactly these labels, by name. A name the project does not
 * have yet becomes a new label with the next color of the palette.
 */
class SetIssueLabelsAction
{
    public function __construct(
        private readonly BroadcastProjectChangeAction $broadcast,
        private readonly RecordIssueEventAction $history,
    ) {
    }

    /**
     * @param  list<string>  $names
     */
    public function execute(Issue $issue, array $names, ?User $by = null, ?McpToken $token = null): void
    {
        $before = $issue->labels()->pluck('name')->all();

        $names = collect($names)
            ->map(fn (string $name): string => mb_substr(trim($name), 0, Label::MAX_NAME))
            ->filter(fn (string $name): bool => $name !== '')
            ->unique(fn (string $name): string => mb_strtolower($name))
            ->take(Label::MAX_PER_ISSUE)
            ->values();

        DB::transaction(function () use ($issue, $names): void {
            $existing = Label::query()->where('project_id', $issue->project_id)->get();

            $ids = $names->map(function (string $name) use ($issue, $existing): int {
                $label = $existing->first(fn (Label $l): bool => mb_strtolower($l->name) === mb_strtolower($name));

                if ($label === null) {
                    $label = Label::query()->create([
                        'project_id' => $issue->project_id,
                        'name' => $name,
                        'color' => LabelColor::forIndex($existing->count()),
                    ]);
                    $existing->push($label);
                }

                return $label->id;
            });

            $issue->labels()->sync($ids->all());
        });

        $after = $issue->labels()->pluck('name')->all();
        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        if ($added !== [] || $removed !== []) {
            $this->history->execute($issue, IssueEventType::LabelsChanged, $by, $token, ['added' => $added, 'removed' => $removed]);
        }

        $this->broadcast->issueUpdated($issue->project_id, $issue->uuid);
    }
}
