<?php

declare(strict_types=1);

namespace App\Domains\Connectors\GitHub\Http\Requests;

use App\Domains\Connectors\GitHub\Data\GitHubCredentials;
use App\Domains\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Override;

/**
 * A repository as owner/name, or its address on github.com, a token that reads it (or the
 * connection whose saved token to use instead), and optionally the branch to read instead of
 * the default branch.
 */
class ConnectGitHubRequest extends FormRequest
{
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
        return [
            'repository' => ['required', 'string', 'max:200', 'regex:~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~'],
            'token' => ['required_without:token_from', 'nullable', 'string', 'max:255'],
            'token_from' => ['nullable', 'uuid'],
            'branch' => UpdateGitHubConnectionRequest::BRANCH_RULES,
        ];
    }

    /** Any address copied from the browser under the repository is taken as well as owner/name. */
    #[Override]
    protected function prepareForValidation(): void
    {
        if (is_string($repository = $this->input('repository'))) {
            // Any page under the repository: /tree/main/src, /pulls/12, ?tab=readme.
            $this->merge(['repository' => preg_replace('~^(?:https?://)?(?:www\.)?github\.com/([^/\s?#]+)/([^/\s?#]+?)(?:\.git)?(?:[/?#].*)?$~i', '$1/$2', trim($repository))]);
        }
    }

    /** The connection whose saved token to use, or null when one was pasted. */
    public function tokenFrom(): ?string
    {
        return $this->filled('token_from') ? $this->string('token_from')->toString() : null;
    }

    /** With $token, a saved one, in place of the token pasted. */
    public function credentials(?string $token = null): GitHubCredentials
    {
        return new GitHubCredentials($this->string('repository')->toString(), $token ?? $this->string('token')->trim()->toString(), UpdateGitHubConnectionRequest::branchOf($this));
    }
}
