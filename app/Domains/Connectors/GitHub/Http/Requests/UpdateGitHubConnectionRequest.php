<?php

declare(strict_types=1);

namespace App\Domains\Connectors\GitHub\Http\Requests;

use App\Domains\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The branch a connected repository is read from; empty for its default branch.
 */
class ChangeGitHubBranchRequest extends FormRequest
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
        return ['branch' => self::BRANCH_RULES];
    }

    /** The branch asked for, or null for the default branch. */
    public static function branchOf(FormRequest $request): ?string
    {
        return $request->filled('branch') ? $request->string('branch')->trim()->toString() : null;
    }
}
