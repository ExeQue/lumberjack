<?php

declare(strict_types=1);

namespace App\Domains\Connectors\GitHub\Http\Requests;

use App\Domains\Connectors\GitHub\Enums\GitHubFigure;
use App\Domains\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A connected repository's settings: the branch it is read from (empty for its default
 * branch), and, when sent, the key figures its page shows.
 */
class UpdateGitHubConnectionRequest extends FormRequest
{
    /**
     * A git branch name, roughly as git-check-ref-format allows it: no spaces, none of
     * ~^:?*[\, no "..", and no leading dash or slash or trailing slash.
     */
    public const array BRANCH_RULES = ['nullable', 'string', 'max:255', 'not_regex:#[\s~^:?*\[\\\\]|\.\.|^[-/]|/$#'];

    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && $this->user()?->can('update', $project) === true;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return ['branch' => self::BRANCH_RULES, 'figures' => ['sometimes', 'array'], 'figures.*' => ['string', Rule::enum(GitHubFigure::class)]];
    }

    /**
     * The key figures to show, in their order, or null to keep them as they are.
     *
     * @return list<GitHubFigure>|null
     */
    public function figures(): ?array
    {
        if (! $this->has('figures')) {
            return null;
        }

        $chosen = $this->collect('figures')->all();

        return array_values(array_filter(GitHubFigure::cases(), fn (GitHubFigure $figure): bool => in_array($figure->value, $chosen, true)));
    }

    /** The branch asked for, or null for the default branch. */
    public static function branchOf(FormRequest $request): ?string
    {
        return $request->filled('branch') ? $request->string('branch')->trim()->toString() : null;
    }
}
