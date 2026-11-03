<?php

declare(strict_types=1);

namespace App\Domains\Issues\Http\Requests;

use App\Domains\Issues\Models\Issue;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Watch or stop watching one issue: anyone who may see it.
 */
class WatchIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        $issue = $this->route('issue');

        return $issue instanceof Issue && $this->user()?->can('view', $issue);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['watching' => ['required', 'boolean']];
    }
}
