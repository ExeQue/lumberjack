<?php

declare(strict_types=1);

namespace App\Domains\Connectors\GitHub\Http\Requests;

use App\Domains\Connectors\GitHub\Data\GitHubCredentials;
use App\Domains\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Override;

/**
 * A repository as owner/name, or its address on github.com, and a token that reads it.
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
            'token' => ['required', 'string', 'max:255'],
        ];
    }

    /** The address copied from the browser is taken as well as owner/name. */
    #[Override]
    protected function prepareForValidation(): void
    {
        if (is_string($repository = $this->input('repository'))) {
            $this->merge(['repository' => preg_replace(['~^\s*(?:https?://)?(?:www\.)?github\.com/~i', '~(?:\.git)?/*\s*$~'], '', $repository)]);
        }
    }

    public function credentials(): GitHubCredentials
    {
        return new GitHubCredentials($this->string('repository')->toString(), $this->string('token')->trim()->toString());
    }
}
