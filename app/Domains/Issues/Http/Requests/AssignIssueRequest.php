<?php

declare(strict_types=1);

namespace App\Domains\Issues\Http\Requests;

use App\Domains\Issues\Models\Issue;
use Illuminate\Foundation\Http\FormRequest;

class AssignIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        $issue = $this->route('issue');

        return $issue instanceof Issue && $this->user()?->can('update', $issue);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['user_id' => ['nullable', 'integer', 'exists:users,id']];
    }
}
